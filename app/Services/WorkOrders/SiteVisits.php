<?php

namespace App\Services\WorkOrders;

use App\Enums\PermissionArea;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\SiteVisit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Activity\ActivityLogger;
use App\Support\Access\Access;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Visita técnica: la presencia física de un técnico en un edificio.
 *
 * Flujo: tomar el trabajo → «Llegué al edificio» (arrive) → trabajos y remitos
 * (uno o varios) → «Finalizar visita y retirarme» (leave).
 *
 * Qué entra en una visita (sin adivinar):
 * - Del MISMO técnico, la MISMA empresa y el MISMO edificio, mientras la visita
 *   está abierta. Nunca de otro técnico, otra empresa u otro edificio, ni de
 *   otro día (una visita abierta más de LEAVE_WINDOW_HOURS ya no admite salida).
 * - Órdenes: las asignadas a él en ese edificio, en curso y sin remito, al
 *   llegar; y las que toma o abre (intervenciones) mientras está ahí.
 * - Remitos: los que firma mientras está ahí (building_visits.site_visit_id).
 *
 * Horas: del servidor, una vez cada una (bloqueo de la fila del usuario: dos
 * toques o dos celulares no crean dos visitas). La llegada y la salida de la
 * visita se copian a cada orden (work_orders.arrived_at / left_at), así los
 * tiempos de atención, el historial y el panel siguen funcionando igual
 * (AttendanceTimes). El remito no registra la salida ni cierra la visita; la
 * salida no cierra órdenes.
 */
class SiteVisits
{
    /** Salida: hasta cuántas horas después de la llegada. Después, cierre sin salida con motivo. */
    public const LEAVE_WINDOW_HOURS = 24;

    /** Marca de un remito firmado con un formulario sin el paso de llegada (anterior a las visitas técnicas). */
    public const LEGACY_FORM_EXCEPTION = 'Sin llegada registrada: formulario sin el paso de llegada (anterior a las visitas técnicas).';

    /**
     * Visita abierta más allá del plazo de salida (se olvidó de finalizarla):
     * no suma trabajos ni remitos de otro día. Solo se cierra, con motivo.
     */
    public static function isStale(SiteVisit $visit): bool
    {
        return $visit->closed_at === null && $visit->arrived_at->lt(now()->subHours(self::LEAVE_WINDOW_HOURS));
    }

    /** Orden con llegada propia, registrada antes de las visitas técnicas (sin visita). */
    public static function hasLegacyArrival(?WorkOrder $order): bool
    {
        return $order !== null && $order->arrived_at !== null && $order->site_visit_id === null;
    }

    /** Visita abierta del técnico (como mucho una). */
    public static function openFor(User $user): ?SiteVisit
    {
        return SiteVisit::withoutGlobalScopes()->where('company_id', $user->company_id)
            ->where('user_id', $user->id)->open()->latest('arrived_at')->first();
    }

    /** Visita abierta y vigente del técnico en ESE edificio (una olvidada de otro día no cuenta). */
    public static function openIn(User $user, Building|int $building): ?SiteVisit
    {
        $visit = self::openFor($user);

        return $visit && ! self::isStale($visit) && (int) $visit->building_id === (int) ($building instanceof Building ? $building->id : $building) ? $visit : null;
    }

    /**
     * ¿Puede registrar la llegada a este edificio? Lo tiene asignado, o tiene
     * ahí una orden en curso asignada a él (incluye su intervención de guardia).
     * Estar de guardia NO alcanza por sí solo: no abre visitas en cualquier edificio.
     */
    public static function canArriveAt(User $user, Building $building): bool
    {
        if ((int) $building->company_id !== (int) $user->company_id || $user->isSuperAdmin() || $building->trashed()) {
            return false;
        }

        return $building->users()->where('users.id', $user->id)->exists()
            || self::openOrders($user, $building->id)->exists();
    }

    /** «Llegué al edificio». Devuelve [visita, creada ahora (false = ya estaba)]. */
    public function arrive(User $user, Building $building, ?WorkOrder $order = null): array
    {
        if ($order && ((int) $order->company_id !== (int) $user->company_id || ! $order->users()->whereKey($user->id)->exists())) {
            throw ValidationException::withMessages(['attendance' => 'Esta orden no está asignada a vos.']);
        }
        if ($order && $order->status === 'pending') {
            throw ValidationException::withMessages(['attendance' => 'Primero tomá el trabajo.']);
        }
        if ($order && ($order->status !== 'in_progress' || $order->trashed() || $order->deliveryNote()->exists())) {
            throw ValidationException::withMessages(['attendance' => 'Esta orden ya está cerrada: no se puede registrar la llegada.']);
        }
        if ($order && (int) $order->building_id !== (int) $building->id) {
            throw ValidationException::withMessages(['attendance' => 'Esta orden es de otro edificio.']);
        }
        if (! self::canArriveAt($user, $building)) {
            throw ValidationException::withMessages(['attendance' => 'Este edificio no está asignado a vos.']);
        }

        return DB::transaction(function () use ($user, $building) {
            // Una visita abierta por técnico: se serializa por usuario.
            User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->first();

            if ($open = self::openFor($user)) {
                if (self::isStale($open)) {
                    throw ValidationException::withMessages(['attendance' => 'Quedó abierta tu visita a '.($open->building?->name ?? 'otro edificio')
                        .' del '.AttendanceTimes::time($open->arrived_at).', sin salida. Cerrala desde Inicio indicando el motivo y después registrá la llegada.']);
                }
                if ((int) $open->building_id !== (int) $building->id) {
                    throw ValidationException::withMessages(['attendance' => 'Tenés una visita abierta en '.($open->building?->name ?? 'otro edificio')
                        .' desde las '.AttendanceTimes::time($open->arrived_at).'. Finalizala antes de registrar la llegada a otro edificio.']);
                }
                $this->linkOpenOrders($open, now());

                return [$open, false];
            }

            $visit = new SiteVisit;
            $visit->forceFill([
                'company_id' => $user->company_id,
                'building_id' => $building->id,
                'user_id' => $user->id,
                'arrived_at' => now(),
            ])->save();
            $this->linkOpenOrders($visit, $visit->arrived_at);

            return [$visit, true];
        });
    }

    /** Tomó u abrió una orden estando en el edificio: entra en la visita, sin repetir la llegada. */
    public function attach(User $user, WorkOrder $order): void
    {
        if ($visit = self::openIn($user, (int) $order->building_id)) {
            $this->link($visit, $order, now());
        }
    }

    /**
     * «Finalizar visita y retirarme». No cierra órdenes ni toca remitos: las que
     * quedaron sin remito siguen en curso. Devuelve [visita, registrada ahora].
     */
    public function leave(User $user, SiteVisit $visit): array
    {
        return DB::transaction(function () use ($user, $visit) {
            $locked = $this->lockedOwn($user, $visit);
            if ($locked->left_at !== null) {
                return [$locked, false];
            }
            if ($locked->closed_at !== null) {
                throw ValidationException::withMessages(['attendance' => 'Esta visita ya se cerró sin salida registrada.']);
            }
            if ($locked->arrived_at->lt(now()->subHours(self::LEAVE_WINDOW_HOURS))) {
                throw ValidationException::withMessages(['attendance' => 'Pasaron más de '.self::LEAVE_WINDOW_HOURS.' h desde la llegada: ya no se registra la salida. Cerrá la visita indicando el motivo.']);
            }
            $now = now();
            $locked->forceFill(['left_at' => $now, 'closed_at' => $now, 'closed_by' => $user->id])->save();

            // La salida de una orden es la de la visita que la cerró: la del remito
            // (aunque la orden haya entrado en la visita de otro técnico o de otro
            // día) o, sin remito, la de su visita si ya no está en curso. Una orden
            // que sigue pendiente no recibe salida: queda «Sin registrar» hasta
            // que se termine.
            WorkOrder::withoutGlobalScopes()->where('company_id', $locked->company_id)->whereNotNull('arrived_at')->whereNull('left_at')
                ->where(fn ($q) => $q
                    ->whereIn('id', BuildingVisit::withoutGlobalScopes()->where('site_visit_id', $locked->id)->whereNotNull('work_order_id')->select('work_order_id'))
                    ->orWhere(fn ($w) => $w->where('site_visit_id', $locked->id)->where('status', '!=', 'in_progress')->whereDoesntHave('deliveryNote')))
                ->update(['left_at' => $now, 'left_by' => $user->id]);

            return [$locked, true];
        });
    }

    /**
     * Excepción explícita: cerrar la visita SIN salida (se olvidó y pasó el
     * plazo, se fue a otro edificio, etc.). Ninguna hora se inventa: la salida
     * queda «Sin registrar». Con motivo y auditoría. Puede hacerlo el técnico
     * de la visita o quien gestiona órdenes de trabajo en su empresa.
     */
    public function closeWithoutExit(User $actor, SiteVisit $visit, string $reason): SiteVisit
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['close_note' => 'Contá en pocas palabras por qué se cierra sin salida (al menos 10 caracteres).']);
        }

        return DB::transaction(function () use ($actor, $visit, $reason) {
            $locked = SiteVisit::withoutGlobalScopes()->where('company_id', $actor->company_id)->lockForUpdate()->find($visit->id);
            if (! $locked || ! ((int) $locked->user_id === (int) $actor->id || self::canManage($actor))) {
                throw ValidationException::withMessages(['close_note' => 'No podés cerrar esta visita.']);
            }
            if ($locked->closed_at !== null) {
                return $locked;
            }
            $locked->forceFill(['closed_at' => now(), 'closed_by' => $actor->id, 'close_note' => mb_substr($reason, 0, 500)])->save();
            ActivityLogger::record('site_visit.closed_without_exit', $locked, ['motivo' => $locked->close_note], 'Visita #'.$locked->id);

            return $locked;
        });
    }

    public static function canManage(User $user): bool
    {
        return Access::can($user, PermissionArea::WorkOrders, PermissionArea::MANAGE);
    }

    /** Órdenes de la visita que todavía no tienen remito (siguen en curso). */
    public static function pending(SiteVisit $visit): Collection
    {
        return WorkOrder::withoutGlobalScopes()->where('site_visit_id', $visit->id)->where('status', 'in_progress')
            ->whereDoesntHave('deliveryNote')->whereNull('deleted_at')->orderBy('id')->get();
    }

    /**
     * Órdenes que tomó (en curso, asignadas a él, sin remito) y todavía sin
     * llegada: el recordatorio «cuando llegues, registrá la llegada». Las que
     * ya entraron en una visita o tienen llegada no aparecen.
     */
    public static function awaitingArrival(User $user, int $limit = 5): Collection
    {
        return WorkOrder::withoutGlobalScopes()->where('company_id', $user->company_id)->where('status', 'in_progress')
            ->whereNull('deleted_at')->whereNull('arrived_at')->whereNull('site_visit_id')->whereDoesntHave('deliveryNote')
            ->whereHas('users', fn ($q) => $q->whereKey($user->id))
            ->with('building:id,name')->orderBy('started_at')->limit($limit)->get();
    }

    /** ¿Se puede registrar la salida de esta visita ahora? */
    public static function canLeave(User $user, SiteVisit $visit): bool
    {
        return (int) $visit->user_id === (int) $user->id && (int) $visit->company_id === (int) $user->company_id
            && $visit->closed_at === null && $visit->arrived_at->gte(now()->subHours(self::LEAVE_WINDOW_HOURS));
    }

    private function lockedOwn(User $user, SiteVisit $visit): SiteVisit
    {
        $locked = SiteVisit::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->lockForUpdate()->find($visit->id);
        if (! $locked) {
            throw ValidationException::withMessages(['attendance' => 'Esta visita no es tuya.']);
        }

        return $locked;
    }

    private static function openOrders(User $user, int $buildingId)
    {
        return WorkOrder::withoutGlobalScopes()->where('company_id', $user->company_id)->where('building_id', $buildingId)
            ->where('status', 'in_progress')->whereNull('deleted_at')->whereDoesntHave('deliveryNote')
            ->whereHas('users', fn ($q) => $q->whereKey($user->id));
    }

    private function linkOpenOrders(SiteVisit $visit, \DateTimeInterface $at): void
    {
        $user = User::withoutGlobalScopes()->find($visit->user_id);
        self::openOrders($user, (int) $visit->building_id)->whereNull('site_visit_id')->get()
            ->each(fn (WorkOrder $order) => $this->link($visit, $order, $at));
    }

    /**
     * La orden entra en la visita. Su llegada ($at) es la de la visita si ya
     * estaba en curso al llegar; si la tomó o la abrió estando ahí, la hora en
     * que entró: nunca una llegada anterior al trabajo. Una llegada ya
     * registrada (la del primer técnico) no se toca.
     */
    private function link(SiteVisit $visit, WorkOrder $order, \DateTimeInterface $at): void
    {
        if ($order->site_visit_id !== null || (int) $order->building_id !== (int) $visit->building_id || (int) $order->company_id !== (int) $visit->company_id
            || $order->status !== 'in_progress' || ! $order->users()->whereKey($visit->user_id)->exists()) {
            return;
        }
        $changes = ['site_visit_id' => $visit->id];
        if ($order->arrived_at === null) {
            $changes += ['arrived_at' => $at, 'arrived_by' => $visit->user_id];
        }
        $order->forceFill($changes)->saveQuietly();
    }
}
