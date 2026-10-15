<?php

namespace App\Services\Claims;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Resources\Claims\ClientClaimResource;
use App\Models\Building;
use App\Models\ClientClaim;
use App\Models\ClientClaimEvent;
use App\Models\ClientClaimPhoto;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\ClientClaimNotification;
use App\Services\Notifications\Notifier;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use App\Support\Plans\PlanGuard;
use App\Support\Portal\PortalAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reclamos de clientes desde el portal.
 *
 * - La empresa, el cliente y el edificio salen de las relaciones reales: el
 *   acceso vigente al portal de la persona y los edificios que tiene
 *   autorizados. Nunca de ids de empresa o cliente enviados por el navegador.
 * - Primero se guarda el reclamo; los avisos van después y si fallan no lo
 *   deshacen (se registra el error, sin datos del reclamo).
 * - Todo cambio queda en el historial. Las notas internas nunca son públicas.
 * - Una orden de trabajo se crea solo con la acción explícita, una sola vez.
 */
final class ClientClaimService
{
    public const MAX_PHOTOS = 3;

    public function __construct(private Notifier $notifier) {}

    /** ¿Puede esta persona del portal presentar reclamos en su empresa actual? */
    public static function portalCanClaim(?User $user): bool
    {
        $companyId = $user?->isClientUser() ? PortalAccess::currentCompanyId($user) : null;

        return $companyId !== null && PlanGuard::companyAllows($companyId, PlanFeature::ClientPortal);
    }

    /**
     * @param  array{building_id: int, elevator_id?: ?int, category: string, description: string, occurred_at?: ?string, contact_phone?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function create(User $user, array $data, array $photos = []): ClientClaim
    {
        abort_unless(self::portalCanClaim($user), 403);
        $companyId = (int) PortalAccess::currentCompanyId($user);
        $membership = PortalAccess::currentMembership($user);
        abort_unless($membership !== null, 403);

        // Edificio: de la empresa actual, del cliente de esta persona y autorizado para ella.
        $building = Building::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->find((int) $data['building_id']);
        if (! $building || (int) $building->client_id !== (int) $membership->client_id || ! PortalAccess::buildingIds($user, $companyId)->contains((int) $building->id)) {
            throw ValidationException::withMessages(['building_id' => 'Elegí uno de tus edificios.']);
        }

        $elevator = null;
        if (! empty($data['elevator_id'])) {
            $elevator = Elevator::withoutGlobalScopes()->where('building_id', $building->id)->where('is_active', true)->find((int) $data['elevator_id']);
            if (! $elevator) {
                throw ValidationException::withMessages(['elevator_id' => 'Ese ascensor no es de este edificio.']);
            }
        }

        $stored = [];
        try {
            $claim = DB::transaction(function () use ($user, $data, $companyId, $membership, $building, $elevator, $photos, &$stored) {
                // Número correlativo por empresa: se serializa con el registro de la empresa.
                Company::whereKey($companyId)->lockForUpdate()->first();
                $number = (int) ClientClaim::withoutGlobalScopes()->where('company_id', $companyId)->max('number') + 1;

                $claim = new ClientClaim;
                $claim->forceFill([
                    'company_id' => $companyId,
                    'number' => $number,
                    'client_id' => (int) $membership->client_id,
                    'building_id' => $building->id,
                    'elevator_id' => $elevator?->id,
                    'created_by' => $user->id,
                    'category' => $data['category'],
                    'description' => trim($data['description']),
                    'occurred_at' => $data['occurred_at'] ?? null,
                    'contact_phone' => filled($data['contact_phone'] ?? null) ? trim($data['contact_phone']) : null,
                    'status' => ClientClaim::NEW,
                ]);
                ClientClaim::withoutEvents(fn () => $claim->save());

                $this->event($claim, $user, 'created', public: true);

                foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $photo) {
                    $ext = match ($photo->getMimeType()) {
                        'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
                    };
                    $path = $photo->storeAs("client-claims/{$companyId}/{$claim->id}", Str::random(32).'.'.$ext, 'local');
                    $stored[] = $path;
                    (new ClientClaimPhoto)->forceFill([
                        'company_id' => $companyId, 'client_claim_id' => $claim->id, 'path' => $path,
                        'mime' => (string) $photo->getMimeType(), 'size' => (int) $photo->getSize(),
                    ])->save();
                }

                return $claim;
            });
        } catch (Throwable $e) {
            // Si algo falló, no quedan fotos sueltas.
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        $this->notifyCompany($claim);

        return $claim;
    }

    /** Personas de la empresa que reciben el aviso: dueño y administrativos con «Órdenes de trabajo». */
    public function companyRecipients(ClientClaim $claim): Collection
    {
        return User::query()->where('company_id', $claim->company_id)->whereIn('role', ['admin', User::ROLE_STAFF])
            ->where('is_super_admin', false)->whereNull('deleted_at')->get()
            ->filter(fn (User $u) => $u->role === 'admin' || (Access::isActiveStaff($u) && Access::can($u, PermissionArea::WorkOrders)))
            ->values();
    }

    private function notifyCompany(ClientClaim $claim): void
    {
        $claim->load(['building', 'elevator']);
        foreach ($this->companyRecipients($claim) as $user) {
            $this->safely(fn () => $this->notifier->sendTo($user, $this->notification($claim, 'created', $this->panelPath($claim)), (int) $claim->company_id, mail: true, pushAfterResponse: true));
        }
    }

    private function notifyCreator(ClientClaim $claim, string $kind, string $extra, string $key): void
    {
        $creator = $claim->created_by ? User::find($claim->created_by) : null;
        if ($creator?->isClientUser()) {
            $claim->load(['building', 'elevator']);
            $this->safely(fn () => $this->notifier->sendTo($creator, $this->notification($claim, $kind, route('portal.claims.show', $claim->id), $extra, $key), (int) $claim->company_id, mail: false, pushAfterResponse: true));
        }
    }

    private function notification(ClientClaim $claim, string $kind, ?string $target, string $extra = '', string $key = ''): ClientClaimNotification
    {
        return new ClientClaimNotification($kind, $claim->id, $claim->reference(), $claim->placeLabel(), $claim->categoryLabel(), $target, (int) $claim->building_id, $extra, $key);
    }

    private function panelPath(ClientClaim $claim): ?string
    {
        try {
            return ClientClaimResource::getUrl('view', ['record' => $claim->id]);
        } catch (Throwable) {
            return null;
        }
    }

    /** Un aviso que falla no deshace nada: se registra sin datos del reclamo. */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar un reclamo', ['exception' => $e::class]);
        }
    }

    /* ======================== GESTIÓN (EMPRESA) ======================== */

    private function ensureManager(ClientClaim $claim, User $actor): void
    {
        abort_unless(
            Access::panelUser($actor) && Access::can($actor, PermissionArea::WorkOrders, PermissionArea::MANAGE)
            && ($actor->isSuperAdmin() ? (int) CompanyContext::currentId() === (int) $claim->company_id : (int) $actor->company_id === (int) $claim->company_id),
            403,
        );
    }

    /** El cambio de estado lo ve el cliente (sin la nota interna). */
    public function changeStatus(ClientClaim $claim, User $actor, string $status): ClientClaim
    {
        abort_unless(array_key_exists($status, ClientClaim::STATUSES), 422);
        $this->ensureManager($claim, $actor);

        $changed = DB::transaction(function () use ($claim, $actor, $status) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            if ($fresh->status === $status) {
                return null;
            }
            $from = $fresh->status;
            $fresh->forceFill([
                'status' => $status,
                'resolved_at' => $status === 'resolved' ? now() : ($status === 'closed' ? $fresh->resolved_at : null),
                'closed_at' => $status === 'closed' ? now() : null,
            ]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'status', $from, $status, public: true);

            return $fresh;
        });

        if ($changed) {
            $this->notifyCreator($changed, 'status', 'Ahora: '.$changed->statusLabel(), $status.':'.$changed->events()->count());

            return $changed;
        }

        return $claim->fresh();
    }

    /** Responsable: dueño o administrativo vigente de la MISMA empresa (o nadie). */
    public function assign(ClientClaim $claim, User $actor, ?int $userId): ClientClaim
    {
        $this->ensureManager($claim, $actor);
        $assignee = null;
        if ($userId) {
            $assignee = User::query()->where('company_id', $claim->company_id)->whereIn('role', ['admin', User::ROLE_STAFF])->whereNull('deleted_at')->find($userId);
            abort_unless($assignee && ($assignee->role === 'admin' || Access::isActiveStaff($assignee)), 422);
        }

        DB::transaction(function () use ($claim, $actor, $assignee) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            $fresh->forceFill(['assigned_to' => $assignee?->id]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'assigned', null, $assignee?->name);
        });

        if ($assignee && ! $assignee->is($actor)) {
            $claim->load(['building', 'elevator']);
            $this->safely(fn () => $this->notifier->sendTo($assignee, $this->notification($claim, 'assigned', $this->panelPath($claim), '', (string) now()->timestamp), (int) $claim->company_id, mail: false));
        }

        return $claim->fresh();
    }

    /** Nota interna: nunca la ve el cliente. */
    public function addNote(ClientClaim $claim, User $actor, string $body): void
    {
        $this->ensureManager($claim, $actor);
        $this->event($claim, $actor, 'note', body: $this->text($body), public: false);
    }

    /** Respuesta al cliente: la ve en su portal y le llega un aviso. */
    public function reply(ClientClaim $claim, User $actor, string $body): void
    {
        $this->ensureManager($claim, $actor);
        $event = $this->event($claim, $actor, 'reply', body: $this->text($body), public: true);
        $this->notifyCreator($claim, 'reply', 'Tenés una respuesta', (string) $event->id);
    }

    /**
     * Crea la orden de trabajo del reclamo (acción explícita). Si ya tiene
     * una, devuelve esa: nunca dos órdenes por el mismo reclamo.
     */
    public function createWorkOrder(ClientClaim $claim, User $actor): WorkOrder
    {
        $this->ensureManager($claim, $actor);

        return DB::transaction(function () use ($claim, $actor) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            if ($fresh->work_order_id && ($existing = WorkOrder::withoutGlobalScopes()->find($fresh->work_order_id))) {
                return $existing;
            }

            $order = new WorkOrder;
            $order->forceFill([
                'company_id' => $fresh->company_id,
                'building_id' => $fresh->building_id,
                'type' => 'claim',
                'status' => 'pending',
                'priority' => $fresh->category === 'stopped' ? 'urgent' : ($fresh->category === 'request' ? 'medium' : 'high'),
                'unit' => $fresh->elevator?->label,
                'notes' => 'Reclamo '.$fresh->reference().' ('.$fresh->categoryLabel().'): '.$fresh->description,
            ])->save();

            $fresh->forceFill(['work_order_id' => $order->id]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'work_order', null, (string) $order->id);

            return $order;
        });
    }

    private function text(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw ValidationException::withMessages(['body' => 'Escribí un texto de hasta 2000 caracteres.']);
        }

        return $body;
    }

    private function event(ClientClaim $claim, ?User $user, string $type, ?string $from = null, ?string $to = null, ?string $body = null, bool $public = false): ClientClaimEvent
    {
        $event = new ClientClaimEvent;
        $event->forceFill([
            'company_id' => $claim->company_id, 'client_claim_id' => $claim->id, 'user_id' => $user?->id,
            'type' => $type, 'from' => $from, 'to' => $to, 'body' => $body, 'public' => $public, 'created_at' => now(),
        ])->save();

        return $event;
    }
}
