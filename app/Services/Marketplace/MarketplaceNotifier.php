<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\Supplier;
use App\Models\User;
use App\Notifications\Marketplace\MarketplaceNotice;
use App\Services\Notifications\Notifier;
use App\Support\Marketplace\BuyerAccess;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Único punto de envío de los avisos del marketplace (matriz de eventos en
 * docs/marketplace-comercial.md). Reutiliza Notifier: deduplicación por
 * evento, tiempo real (Reverb), push y correo con constancia de envío/fallo.
 *
 * Destinatarios:
 * - proveedor: sus usuarios (solo los de ESE proveedor, cuenta sin cerrar);
 * - comprador: quien hizo el pedido si todavía puede verlo; si no, los dueños
 *   de la empresa (nunca otra empresa);
 * - equipo: los SuperAdmin.
 *
 * Un aviso que falla no rompe la acción que lo disparó: queda en el log.
 * Devuelven cuántos avisos se crearon (0 = ya estaba avisado o no corresponde).
 */
class MarketplaceNotifier
{
    public function __construct(private Notifier $notifier) {}

    public function supplier(Supplier|int $supplier, string $title, string $body, string $path, string $key, bool $mail = true, bool $push = true): int
    {
        $id = $supplier instanceof Supplier ? $supplier->id : $supplier;
        $notice = new MarketplaceNotice($title, $body, $path, 's'.$id.':'.$key, $mail, $id, false, $push);

        return $this->each(User::where('supplier_id', $id)->where('role', User::ROLE_SUPPLIER)->get(), fn (User $u) => $this->notifier->sendToAccount($u, $notice, $mail), ['supplier_id' => $id]);
    }

    public function buyer(MarketplaceRequest $request, string $title, string $body, string $key, bool $mail = false, bool $push = true, ?User $actor = null): int
    {
        if ($request->company_id === null) {
            return 0; // empresa eliminada
        }
        $notice = new MarketplaceNotice($title, $body, '/admin/pedidos-de-repuestos?pedido='.$request->id, 'r'.$request->id.':'.$key, $mail, null, false, $push);

        return $this->each($this->buyers($request, $actor), fn (User $u) => $this->notifier->sendTo($u, $notice, (int) $request->company_id, $mail), ['marketplace_request_id' => $request->id]);
    }

    public function team(string $title, string $body, string $path, string $key, bool $mail = false): int
    {
        $notice = new MarketplaceNotice($title, $body, $path, 'team:'.$key, $mail, null, true, false);

        return $this->each(User::where('is_super_admin', true)->get(), fn (User $u) => $this->notifier->sendToAccount($u, $notice, $mail), ['key' => $key]);
    }

    /**
     * Quién recibe los avisos de un pedido del lado comprador: quien lo hizo,
     * si sigue pudiendo ver los repuestos; si no, los dueños de la empresa.
     * Nunca quien hizo la acción (ya lo ve en pantalla).
     */
    private function buyers(MarketplaceRequest $request, ?User $actor)
    {
        $creator = $request->creator;
        $users = $creator && (int) $creator->company_id === (int) $request->company_id && BuyerAccess::canBrowse($creator)
            ? collect([$creator])
            : User::where('company_id', $request->company_id)->where('role', 'admin')->where('is_super_admin', false)->get();

        return $users->reject(fn (User $u) => $actor && $u->id === $actor->id)->values();
    }

    private function each(iterable $users, callable $send, array $context): int
    {
        $created = 0;
        foreach ($users as $user) {
            try {
                $created += $send($user) ? 1 : 0;
            } catch (Throwable $e) {
                Log::warning('No se pudo registrar un aviso del marketplace', $context + ['user_id' => $user->id, 'exception' => $e::class]);
            }
        }

        return $created;
    }
}
