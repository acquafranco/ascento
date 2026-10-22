<?php

namespace App\Notifications\Marketplace;

use App\Models\Marketplace\Supplier;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Aviso al proveedor cuando el SuperAdmin aprueba, rechaza, suspende o
 * reactiva su cuenta (su área /proveedores y correo). Sin esto, un proveedor
 * pendiente no se entera de que ya puede vender.
 */
class SupplierAccountUpdate extends AppNotification
{
    public const TEXTS = [
        Supplier::APPROVED => ['Tu cuenta de proveedor está aprobada', 'Aprobamos tu cuenta: tus productos publicados ya los ven las empresas de mantenimiento en Ascento.'],
        Supplier::REJECTED => ['No pudimos aprobar tu cuenta de proveedor', 'Revisamos tu cuenta y por ahora no la podemos aprobar.'],
        Supplier::SUSPENDED => ['Suspendimos tu cuenta de proveedor', 'Tu catálogo dejó de verse y no podés cotizar pedidos nuevos.'],
    ];

    public function __construct(public string $status, public ?string $reason, public string $stamp) {}

    public function title(): string
    {
        return self::TEXTS[$this->status][0];
    }

    public function body(): string
    {
        return self::TEXTS[$this->status][1].(filled($this->reason) ? ' Motivo: '.$this->reason : '');
    }

    public function path(): ?string
    {
        return '/proveedores';
    }

    public function dedupeKey(): ?string
    {
        return 'mkt-supplier-account:'.$this->status.':'.$this->stamp;
    }

    public function icon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return $this->mail($this->title(), [$this->body()], 'Entrar a Ascento');
    }

    /** A los usuarios del proveedor: en su área al instante; el correo, después de responder. */
    public static function deliver(Supplier $supplier, string $status, ?string $reason): void
    {
        if (! isset(self::TEXTS[$status])) {
            return;
        }
        $notification = new self($status, $reason, $supplier->id.':'.now()->timestamp);
        User::where('supplier_id', $supplier->id)->where('role', User::ROLE_SUPPLIER)->get()->each(function (User $user) use ($notification, $supplier) {
            try {
                $user->notifyNow($notification, ['database']);
                $send = fn () => Notification::sendNow($user, $notification, ['mail']);
                app()->runningInConsole() ? $send() : dispatch(function () use ($send, $supplier) {
                    try {
                        $send();
                    } catch (Throwable $e) {
                        Log::warning('No se pudo enviar el correo de estado de un proveedor', ['supplier_id' => $supplier->id, 'exception' => $e::class]);
                    }
                })->afterResponse();
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar al proveedor su estado', ['supplier_id' => $supplier->id, 'exception' => $e::class]);
            }
        });
    }
}
