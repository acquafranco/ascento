<?php

namespace App\Console\Commands;

use App\Filament\Resources\Marketplace\MarketplaceModeration;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
use App\Services\Marketplace\Billing\SupplierBilling;
use App\Services\Marketplace\FeaturedPlacements;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tareas periódicas del marketplace (cada hora, desde routes/console.php):
 *
 * 1. Cotizaciones: aviso 24 h antes de vencer y al vencer (comprador y proveedor).
 * 2. Destacados: vencimiento, aviso 24 h antes y checkouts abandonados.
 * 3. Proveedores: cambios de estado comercial (prueba vencida, suscripción
 *    vencida, vuelve a estar activo) con aviso al proveedor y a los
 *    compradores con pedidos abiertos; aviso 3 días antes de que termine la
 *    prueba o un período pago sin renovación.
 *
 * Idempotente: cada aviso tiene su clave de evento (Notifier no repite) y las
 * marcas se toman con un UPDATE condicional. Si la tarea falla y se vuelve a
 * correr, no se duplica nada. El catálogo no depende de esta tarea: la
 * visibilidad se calcula siempre en el momento (SupplierStanding).
 */
class MarketplaceTick extends Command
{
    protected $signature = 'marketplace:tick';

    protected $description = 'Vencimientos y avisos del marketplace de repuestos (cotizaciones, destacados, pruebas y suscripciones)';

    public function handle(MarketplaceNotifier $notify, FeaturedPlacements $features): int
    {
        $counts = ['expiring' => 0, 'expired' => 0, 'featured' => 0, 'states' => 0, 'reminders' => 0];

        foreach (['quotes' => fn () => $this->quotes($notify, $counts), 'featured' => fn () => $counts['featured'] = $features->expireDue(), 'suppliers' => fn () => $this->suppliers($notify, $counts)] as $step => $run) {
            try {
                $run();
            } catch (Throwable $e) {
                report($e);
                $this->error("Falló el paso «{$step}»: ".$e::class);
            }
        }

        $this->info(collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(' · '));

        return self::SUCCESS;
    }

    private function quotes(MarketplaceNotifier $notify, array &$counts): void
    {
        MarketplaceRequest::where('status', MarketplaceRequest::QUOTED)->whereNotNull('quote_valid_days')->whereNull('expired_notified_at')
            ->with('supplier:id,name')->orderBy('id')->each(function (MarketplaceRequest $r) use ($notify, &$counts) {
                $expires = $r->quoteExpiresAt();
                if ($expires === null) {
                    return;
                }
                $stamp = $r->quoted_at->timestamp;
                if ($expires->isPast()) {
                    if (MarketplaceRequest::whereKey($r->id)->whereNull('expired_notified_at')->update(['expired_notified_at' => now()])) {
                        $notify->buyer($r, 'Venció una cotización', 'La cotización de '.$r->supplier->name.' para el pedido '.$r->reference.' venció el '.$expires->format('d/m/Y').'. Ya no se puede aceptar: pedile que la actualice o cancelá el pedido.', 'expired-'.$stamp);
                        $notify->supplier($r->supplier_id, 'Venció tu cotización', 'La cotización del pedido '.$r->reference.' ('.$r->buyerLabel().') venció sin respuesta. Si querés, podés actualizarla.', '/proveedores/pedidos/'.$r->reference, 'r'.$r->id.':expired-'.$stamp, mail: false);
                        $counts['expired']++;
                    }
                } elseif ($expires->lte(now()->addHours(MarketplaceRequest::EXPIRY_WARNING_HOURS)) && $r->expiring_notified_at === null) {
                    if (MarketplaceRequest::whereKey($r->id)->whereNull('expiring_notified_at')->update(['expiring_notified_at' => now()])) {
                        $notify->buyer($r, 'Una cotización vence pronto', 'La cotización de '.$r->supplier->name.' para el pedido '.$r->reference.' ('.$r->money((float) $r->quoted_total).') vence el '.$expires->format('d/m/Y').' a las '.$expires->format('H:i').'.', 'expiring-'.$stamp, mail: true);
                        $counts['expiring']++;
                    }
                }
            });
    }

    private function suppliers(MarketplaceNotifier $notify, array &$counts): void
    {
        $settings = MarketplaceSettings::current();

        Supplier::whereNull('closed_at')->with('subscription')->orderBy('id')->each(function (Supplier $s) use ($notify, $settings, &$counts) {
            $state = SupplierStanding::of($s, $settings);
            $previous = $s->commercial_state;

            if ($state !== $previous) {
                // Marca con compare-and-set: dos corridas a la vez avisan una sola vez.
                $won = Supplier::whereKey($s->id)->where(fn ($q) => $previous === null ? $q->whereNull('commercial_state') : $q->where('commercial_state', $previous))
                    ->update(['commercial_state' => $state, 'commercial_state_at' => now()]);
                if ($won && $previous !== null) {
                    $this->transition($notify, $s, $previous, $state);
                    $counts['states']++;
                }
            }

            $counts['reminders'] += $this->reminders($notify, $s, $state);
        });
    }

    /** Avisos de un cambio de estado comercial (la moderación avisa en su propia acción). */
    private function transition(MarketplaceNotifier $notify, Supplier $s, string $from, string $to): void
    {
        $wasOperating = in_array($from, SupplierStanding::OPERATING, true);
        $isOperating = in_array($to, SupplierStanding::OPERATING, true);
        $moderation = [SupplierStanding::PENDING_APPROVAL, SupplierStanding::REJECTED, SupplierStanding::SUSPENDED, SupplierStanding::CLOSED];
        if (in_array($to, $moderation, true) || in_array($from, $moderation, true)) {
            return;
        }
        $key = 'state:'.$to.':'.now()->format('YmdH');

        if ($wasOperating && ! $isOperating) {
            [$title, $body] = match ($to) {
                SupplierStanding::TRIAL_EXPIRED => ['Terminó tu prueba gratuita', 'Tu catálogo dejó de verse y no recibís pedidos nuevos. No borramos nada: tus productos, fotos y pedidos se conservan y vuelven a verse cuando te suscribas.'],
                SupplierStanding::LAPSED => ['Tu suscripción venció', 'Tu catálogo dejó de verse y no recibís pedidos nuevos. No borramos nada: reactivá la suscripción para volver a publicar.'],
                default => ['Tu catálogo dejó de verse', 'Por ahora no hay una modalidad disponible para tu cuenta. Tus datos se conservan; escribinos si tenés dudas.'],
            };
            $notify->supplier($s, $title, $body, '/proveedores/suscripcion', $key);
            MarketplaceModeration::noticeOpenBuyers($s, $key);
        } elseif (! $wasOperating && $isOperating) {
            $notify->supplier($s, $to === SupplierStanding::ACTIVE ? 'Suscripción activa' : 'Tu catálogo vuelve a verse', 'Tus productos publicados vuelven a verse y podés recibir pedidos nuevos.', '/proveedores/suscripcion', $key);
        }
    }

    /** Aviso 3 días antes del fin de la prueba o de un período pago que no se renueva (una vez por fecha). */
    private function reminders(MarketplaceNotifier $notify, Supplier $s, string $state): int
    {
        $soon = now()->addDays(3);
        $sub = $s->subscription;
        $renews = $sub && in_array($sub->status, [SupplierSubscription::AUTHORIZED, SupplierSubscription::PAST_DUE], true);

        if ($state === SupplierStanding::TRIAL && $s->trial_ends_at?->lte($soon) && ! $renews && ! $sub?->grantsAccess()) {
            $charges = MarketplaceSettings::current()->chargesSubscription() && SupplierBilling::supportsCountry($s->country);

            return $notify->supplier($s, 'Tu prueba gratuita termina pronto', 'Tu prueba termina el '.$s->trial_ends_at->format('d/m/Y').'. '.($charges
                ? 'Suscribite desde «Suscripción» para que tu catálogo siga visible.'
                : 'Te vamos a avisar qué opciones tenés para seguir publicando.'), '/proveedores/suscripcion', 'trial-ending:'.$s->trial_ends_at->toDateString());
        }
        if ($state === SupplierStanding::ACTIVE && $sub && ! $renews && $sub->current_period_end?->lte($soon)) {
            return $notify->supplier($s, 'Tu suscripción vence pronto', 'Tu suscripción está cancelada y vence el '.$sub->current_period_end->format('d/m/Y').'. Después tu catálogo deja de verse (no se borra nada).', '/proveedores/suscripcion', 'period-ending:'.$sub->current_period_end->toDateString());
        }

        return 0;
    }
}
