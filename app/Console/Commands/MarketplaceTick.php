<?php

namespace App\Console\Commands;

use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
use App\Services\Marketplace\FeaturedPlacements;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Services\Marketplace\PublicationLimits;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tareas periódicas del marketplace (cada 15 minutos, desde routes/console.php):
 *
 * 1. Cotizaciones: aviso 24 h antes de vencer y al vencer (comprador y proveedor).
 * 2. Destacados: vencimiento, aviso 24 h antes y checkouts abandonados.
 * 3. Proveedores: cambio de plan (Premium ↔ gratuito) con aviso; si hay más
 *    publicaciones que el límite del plan, las que sobran quedan «fuera del
 *    límite» (PublicationLimits, nada se borra); aviso 3 días antes de que
 *    termine un Premium cancelado.
 *
 * Idempotente: cada aviso tiene su clave de evento (Notifier no repite) y las
 * marcas se toman con un UPDATE condicional. Si la tarea falla y se vuelve a
 * correr, no se duplica nada. La moderación y los vencimientos de
 * cotizaciones y destacados se aplican siempre en el momento; esta tarea
 * avisa y ajusta el límite cuando termina un Premium.
 */
class MarketplaceTick extends Command
{
    protected $signature = 'marketplace:tick';

    protected $description = 'Vencimientos y avisos del marketplace de repuestos (cotizaciones, destacados, Premium y límite de publicaciones)';

    public function handle(MarketplaceNotifier $notify, FeaturedPlacements $features): int
    {
        $counts = ['expiring' => 0, 'expired' => 0, 'featured' => 0, 'states' => 0, 'over_limit' => 0, 'reminders' => 0];

        $steps = [
            'quotes' => function () use ($notify, &$counts) {
                $this->quotes($notify, $counts);
            },
            'featured' => function () use ($features, &$counts) {
                $counts['featured'] = $features->expireDue();
            },
            'suppliers' => function () use ($notify, &$counts) {
                $this->suppliers($notify, $counts);
            },
        ];
        foreach ($steps as $step => $run) {
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
        $limits = app(PublicationLimits::class);

        Supplier::whereNull('closed_at')->with('subscription')->orderBy('id')->each(function (Supplier $s) use ($notify, $limits, &$counts) {
            $state = SupplierStanding::of($s);
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

            // Más publicaciones que el límite del plan (terminó Premium, bajó un límite, datos anteriores): lo que sobra queda fuera del límite.
            if (PublicationLimits::counted($s->id)->count() > SupplierStanding::publicationLimit($s)) {
                $counts['over_limit'] += $limits->enforce($s);
            }

            $counts['reminders'] += $this->reminders($notify, $s, $state);
        });
    }

    /** Cambio de plan (la moderación avisa en su propia acción). */
    private function transition(MarketplaceNotifier $notify, Supplier $s, string $from, string $to): void
    {
        $key = 'state:'.$to.':'.now()->format('YmdH');
        if ($from === SupplierStanding::PREMIUM && $to === SupplierStanding::FREE) {
            $free = MarketplaceSettings::current()->freeLimit();
            $notify->supplier($s, 'Terminó tu Premium', "Volviste al plan gratuito: podés tener hasta {$free} productos publicados. No borramos nada; si tenías más, los que no entran quedan «fuera del límite» y podés elegir cuáles publicar o reactivar Premium.", '/proveedores/suscripcion', $key);
        } elseif ($from === SupplierStanding::FREE && $to === SupplierStanding::PREMIUM) {
            $notify->supplier($s, 'Premium activo', 'Ya podés publicar hasta '.MarketplaceSettings::current()->premiumLimit().' productos. Si tenías productos fuera del límite, podés volver a publicarlos desde «Plan».', '/proveedores/suscripcion', $key);
        }
    }

    /** Aviso 3 días antes de que termine un Premium cancelado (una vez por fecha). */
    private function reminders(MarketplaceNotifier $notify, Supplier $s, string $state): int
    {
        $sub = $s->subscription;
        if ($state === SupplierStanding::PREMIUM && $sub && $sub->status === SupplierSubscription::CANCELED && $sub->current_period_end?->lte(now()->addDays(3))) {
            return $notify->supplier($s, 'Tu Premium termina pronto', 'Premium está cancelado y termina el '.$sub->current_period_end->format('d/m/Y').'. Después volvés al plan gratuito: marcá en «Mi catálogo» qué productos conservar publicados (no se borra nada).', '/proveedores/catalogo', 'period-ending:'.$sub->current_period_end->toDateString());
        }

        return 0;
    }
}
