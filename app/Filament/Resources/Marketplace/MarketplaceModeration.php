<?php

namespace App\Filament\Resources\Marketplace;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceReport;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Services\Activity\PlatformAudit;
use App\Services\Marketplace\Billing\SupplierSubscriptions;
use App\Services\Marketplace\FeaturedPlacements;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Services\Marketplace\PublicationLimits;
use App\Services\Marketplace\SupplierEraser;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Acciones de moderación del marketplace (solo SuperAdmin). Cada una vuelve a
 * verificar el rol, valida el estado de partida, avisa a quien corresponde y
 * queda en PlatformAudit. La moderación es independiente del pago: aprobar
 * no cobra (plan gratuito), suspender no cancela Premium solo (se avisa) y
 * pagar nunca aprueba ni reactiva.
 */
final class MarketplaceModeration
{
    public static function allowed(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    private static function reason(bool $required = true): Textarea
    {
        return Textarea::make('reason')->label('Motivo (lo ve el proveedor)')->rows(2)->maxLength(500)->required($required);
    }

    /** @return list<Action> */
    public static function supplierActions(): array
    {
        $move = function (Supplier $record, string $to, ?string $reason, string $title) {
            abort_unless(self::allowed(), 403);
            $from = $record->status;
            $record->forceFill([
                'status' => $to,
                'status_reason' => $to === Supplier::APPROVED ? null : (filled($reason) ? trim($reason) : null),
                'reviewed_by' => auth()->id(),
                'approved_at' => $to === Supplier::APPROVED ? ($record->approved_at ?? now()) : $record->approved_at,
            ])->save();
            PlatformAudit::record('marketplace.supplier.'.$to, $record, ['from' => $from, 'to' => $to, 'reason' => $reason]);
            self::noticeSupplier($record, $from, $to, $reason);
            Notification::make()->title($title)->success()->send();
        };

        return [
            Action::make('approve')->label('Aprobar')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Supplier $record) => self::allowed() && $record->closed_at === null && in_array($record->status, [Supplier::PENDING, Supplier::REJECTED], true))
                ->requiresConfirmation()->modalDescription('Su catálogo publicado pasa a verse (plan gratuito, sin cargo, con su límite de publicaciones).')
                ->action(fn (Supplier $record) => $move($record, Supplier::APPROVED, null, 'Proveedor aprobado')),
            Action::make('reject')->label('Rechazar')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Supplier $record) => self::allowed() && $record->status === Supplier::PENDING)
                ->schema([self::reason()])
                ->action(fn (Supplier $record, array $data) => $move($record, Supplier::REJECTED, $data['reason'], 'Proveedor rechazado')),
            Action::make('suspend')->label('Suspender')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (Supplier $record) => self::allowed() && $record->status === Supplier::APPROVED && $record->closed_at === null)
                ->schema([self::reason()])
                ->modalDescription(fn (Supplier $record) => 'Su catálogo deja de verse y no puede cotizar. Los pedidos quedan como están y se avisa a los compradores con pedidos abiertos.'
                    .($record->subscription?->isBillable() ? ' Ojo: su Premium en Mercado Pago sigue cobrando hasta que lo canceles desde «Cancelar Premium».' : ''))
                ->action(fn (Supplier $record, array $data) => $move($record, Supplier::SUSPENDED, $data['reason'], 'Proveedor suspendido')),
            Action::make('reactivate')->label('Reactivar')->icon('heroicon-o-arrow-path')->color('success')
                ->visible(fn (Supplier $record) => self::allowed() && $record->status === Supplier::SUSPENDED && $record->closed_at === null)
                ->requiresConfirmation()
                ->action(fn (Supplier $record) => $move($record, Supplier::APPROVED, null, 'Proveedor reactivado')),
            Action::make('notes')->label('Notas internas')->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn () => self::allowed())
                ->fillForm(fn (Supplier $record) => ['admin_notes' => $record->admin_notes])
                ->schema([Textarea::make('admin_notes')->label('Notas administrativas (no las ve el proveedor)')->rows(5)->maxLength(5000)])
                ->action(function (Supplier $record, array $data) {
                    abort_unless(self::allowed(), 403);
                    $record->forceFill(['admin_notes' => filled($data['admin_notes']) ? trim($data['admin_notes']) : null])->save();
                    PlatformAudit::record('marketplace.supplier.notes', $record);
                    Notification::make()->title('Notas guardadas')->success()->send();
                }),
            Action::make('cancelSubscription')->label('Cancelar Premium')->icon('heroicon-o-credit-card')->color('danger')
                ->visible(fn (Supplier $record) => self::allowed() && (bool) $record->subscription?->isBillable())
                ->requiresConfirmation()->modalDescription('Cancela la suscripción en Mercado Pago: no se le vuelve a cobrar. Los días ya pagados se respetan.')
                ->action(function (Supplier $record) {
                    abort_unless(self::allowed(), 403);
                    try {
                        app(SupplierSubscriptions::class)->cancel($record, auth()->user());
                    } catch (Throwable $e) {
                        Notification::make()->title('No se pudo cancelar')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'Intentá de nuevo en unos minutos.')->danger()->send();

                        return;
                    }
                    PlatformAudit::record('marketplace.supplier.subscription_cancelled', $record);
                    Notification::make()->title('Premium cancelado')->success()->send();
                }),
            Action::make('erase')->label('Cerrar y eliminar datos')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn (Supplier $record) => self::allowed() && $record->closed_at === null)
                ->schema([
                    Textarea::make('reason')->label('Motivo (pedido del proveedor, baja, etc.)')->rows(2)->maxLength(500)->required(),
                    TextInput::make('confirm')->label('Escribí el nombre del proveedor para confirmar')->required(),
                ])
                ->modalDescription('Borra sus usuarios, productos, fotos y datos de contacto. Se conservan, sin datos personales, los pedidos y cotizaciones de las empresas compradoras y los cobros (registro contable). No se puede deshacer.')
                ->action(function (Supplier $record, array $data) {
                    abort_unless(self::allowed(), 403);
                    try {
                        $summary = app(SupplierEraser::class)->erase($record, auth()->user(), (string) $data['confirm'], (string) $data['reason']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('No se eliminó')->body(collect($e->errors())->flatten()->first())->danger()->persistent()->send();

                        return;
                    }
                    Notification::make()->title('Proveedor cerrado')->body($summary)->success()->send();
                }),
        ];
    }

    /** Avisos de un cambio de moderación: al proveedor y, si deja de operar, a los compradores con pedidos abiertos. */
    private static function noticeSupplier(Supplier $supplier, string $from, string $to, ?string $reason): void
    {
        $notify = app(MarketplaceNotifier::class);
        $why = filled($reason) ? ' Motivo: '.trim($reason) : '';
        $stamp = now()->timestamp;
        [$title, $body] = match (true) {
            $to === Supplier::APPROVED && $from === Supplier::SUSPENDED => ['Reactivamos tu cuenta de proveedor', 'Tu cuenta vuelve a estar habilitada. Tus productos publicados se ven otra vez (no se reactivan publicaciones ocultas por moderación).'],
            $to === Supplier::APPROVED => ['Tu cuenta de proveedor está aprobada', 'Aprobamos tu cuenta: tus productos publicados ya los ven las empresas de mantenimiento. Publicar es gratis hasta '.MarketplaceSettings::current()->freeLimit().' productos a la vez.'],
            $to === Supplier::REJECTED => ['No pudimos aprobar tu cuenta de proveedor', 'Revisamos tu cuenta y por ahora no la podemos aprobar.'.$why],
            default => ['Suspendimos tu cuenta de proveedor', 'Tu catálogo dejó de verse y no podés cotizar pedidos nuevos. Tus datos y tu historial se conservan.'.$why],
        };
        $notify->supplier($supplier, $title, $body, '/proveedores', 'account:'.$to.':'.$stamp);

        if ($to === Supplier::SUSPENDED) {
            self::noticeOpenBuyers($supplier, 'suspended:'.$stamp);
        }
    }

    /** Compradores con pedidos abiertos a un proveedor que deja de estar disponible (uno por pedido). */
    public static function noticeOpenBuyers(Supplier $supplier, string $key): void
    {
        $notify = app(MarketplaceNotifier::class);
        MarketplaceRequest::forSupplier($supplier->id)->whereIn('status', MarketplaceRequest::OPEN)->whereNotNull('company_id')->get()
            ->each(fn (MarketplaceRequest $r) => $notify->buyer($r, 'Proveedor no disponible', $supplier->name.' no está disponible por ahora. Tu pedido '.$r->reference.' se conserva: '
                .($r->status === MarketplaceRequest::ACCEPTED ? 'podés seguir coordinando con el proveedor por sus datos de contacto, o cancelarlo.' : 'no vas a poder aceptar su cotización mientras tanto; podés cancelarlo.'), 'supplier-off:'.$key, mail: true));
    }

    /** @return list<Action> */
    public static function productActions(): array
    {
        return [
            Action::make('hide')->label('Ocultar')->icon('heroicon-o-eye-slash')->color('danger')
                ->visible(fn (SupplierProduct $record) => self::allowed() && ! $record->hidden_by_admin)
                ->schema([self::reason()])
                ->action(function (SupplierProduct $record, array $data) {
                    abort_unless(self::allowed(), 403);
                    self::hide($record, $data['reason']);
                    Notification::make()->title('Producto oculto')->success()->send();
                }),
            Action::make('retire')->label('Retirar')->icon('heroicon-o-archive-box-x-mark')->color('danger')
                ->visible(fn (SupplierProduct $record) => self::allowed() && $record->status !== SupplierProduct::RETIRED)
                ->schema([self::reason()])->modalDescription('La publicación pasa a «retirada» y queda oculta por moderación: el proveedor no puede volver a publicarla hasta que la restaures.')
                ->action(function (SupplierProduct $record, array $data) {
                    abort_unless(self::allowed(), 403);
                    $record->forceFill(['status' => SupplierProduct::RETIRED])->save();
                    self::hide($record, $data['reason'], 'retired');
                    Notification::make()->title('Publicación retirada')->success()->send();
                }),
            Action::make('unhide')->label('Restaurar')->icon('heroicon-o-eye')->color('success')
                ->visible(fn (SupplierProduct $record) => self::allowed() && $record->hidden_by_admin)
                ->requiresConfirmation()->modalDescription('Deja de estar oculta por moderación. Se ve si el proveedor la tiene publicada y su cuenta está habilitada.')
                ->action(function (SupplierProduct $record) {
                    abort_unless(self::allowed(), 403);
                    $record->forceFill(['hidden_by_admin' => false, 'hidden_reason' => null])->save();
                    // Si al volver a contar no entra en el límite de su plan, queda «fuera del límite» (no se publica de más).
                    app(PublicationLimits::class)->fitRestored($record);
                    PlatformAudit::record('marketplace.product.unhidden', $record);
                    app(MarketplaceNotifier::class)->supplier($record->supplier_id, 'Restauramos tu publicación', '«'.$record->name.'» ya no está oculta por moderación.'.match ($record->fresh()->status) {
                        SupplierProduct::PUBLISHED => '', SupplierProduct::OVER_LIMIT => ' Quedó «fuera del límite» de tu plan: publicala cuando tengas lugar.', default => ' Publicala de nuevo cuando quieras.'
                    }, '/proveedores/catalogo/'.$record->id, 'product:'.$record->id.':restored:'.now()->timestamp);
                    Notification::make()->title('Restaurada (se ve si el proveedor la tiene publicada)')->success()->send();
                }),
            Action::make('feature')->label('Destacar')->icon('heroicon-o-star')
                ->visible(fn (SupplierProduct $record) => self::allowed() && ! $record->hidden_by_admin)
                ->fillForm(fn () => ['until' => now()->addDays(30)->toDateString()])
                ->schema([DatePicker::make('until')->label('Destacado hasta')->required()->native(false)->minDate(now()->addDay()->startOfDay())->maxDate(now()->addYear())])
                ->modalDescription('Sin cobro (queda en el historial como «asignado por Ascento»). No saltea la moderación ni la relevancia de la búsqueda.')
                ->action(function (SupplierProduct $record, array $data) {
                    abort_unless(self::allowed(), 403);
                    $until = Carbon::parse($data['until'])->endOfDay();
                    abort_if($until->isPast() || $until->gt(now()->addYear()->endOfDay()), 422);
                    app(FeaturedPlacements::class)->grant(auth()->user(), $record, $until);
                    Notification::make()->title('Destacado hasta el '.$until->format('d/m/Y'))->success()->send();
                }),
            Action::make('unfeature')->label('Quitar destacado')->icon('heroicon-o-star')->color('gray')
                ->visible(fn (SupplierProduct $record) => self::allowed() && FeaturedPlacement::where('supplier_product_id', $record->id)->where('status', FeaturedPlacement::ACTIVE)->exists())
                ->schema([Textarea::make('reason')->label('Motivo')->rows(2)->maxLength(500)->required()])
                ->action(function (SupplierProduct $record, array $data) {
                    abort_unless(self::allowed(), 403);
                    FeaturedPlacement::where('supplier_product_id', $record->id)->where('status', FeaturedPlacement::ACTIVE)->get()
                        ->each(fn (FeaturedPlacement $p) => app(FeaturedPlacements::class)->cancel(auth()->user(), $p, $data['reason']));
                    Notification::make()->title('Ya no está destacado')->success()->send();
                }),
        ];
    }

    public static function hide(SupplierProduct $product, string $reason, string $action = 'hidden'): void
    {
        $product->forceFill(['hidden_by_admin' => true, 'hidden_reason' => trim($reason)])->save();
        app(FeaturedPlacements::class)->endForProduct($product, 'La publicación fue '.($action === 'retired' ? 'retirada' : 'ocultada').' por moderación.');
        PlatformAudit::record('marketplace.product.'.$action, $product, ['reason' => $reason]);
        app(MarketplaceNotifier::class)->supplier($product->supplier_id,
            $action === 'retired' ? 'Retiramos una publicación' : 'Ocultamos una publicación',
            '«'.$product->name.'» '.($action === 'retired' ? 'fue retirada' : 'dejó de verse en el catálogo').' por moderación. Motivo: '.trim($reason).' Si creés que es un error, respondé este correo o escribinos.',
            '/proveedores/catalogo/'.$product->id, 'product:'.$product->id.':'.$action.':'.now()->timestamp);
    }

    /** @return list<Action> */
    public static function reportActions(): array
    {
        $close = function (MarketplaceReport $record, string $status, array $data) {
            abort_unless(self::allowed() && $record->status === 'open', 403);
            if ($status === 'resolved' && ($data['hide'] ?? false) && $record->product && ! $record->product->hidden_by_admin) {
                self::hide($record->product, $data['resolution']);
            }
            $record->forceFill(['status' => $status, 'resolution' => trim((string) ($data['resolution'] ?? '')) ?: null, 'resolved_by' => auth()->id(), 'resolved_at' => now()])->save();
            PlatformAudit::record('marketplace.report.'.$status, $record, ['hide' => (bool) ($data['hide'] ?? false)], 'Reporte #'.$record->id);
            Notification::make()->title($status === 'resolved' ? 'Reporte resuelto' : 'Reporte descartado')->success()->send();
        };

        return [
            Action::make('resolve')->label('Resolver')->icon('heroicon-o-check')->color('success')
                ->visible(fn (MarketplaceReport $record) => self::allowed() && $record->status === 'open')
                ->schema([
                    Textarea::make('resolution')->label('Qué se hizo')->rows(2)->maxLength(500)->required(),
                    Toggle::make('hide')->label('Ocultar el producto (el motivo es el texto de arriba)')->default(true),
                ])
                ->action(fn (MarketplaceReport $record, array $data) => $close($record, 'resolved', $data)),
            Action::make('dismiss')->label('Descartar')->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn (MarketplaceReport $record) => self::allowed() && $record->status === 'open')
                ->schema([Textarea::make('resolution')->label('Por qué se descarta')->rows(2)->maxLength(500)])
                ->action(fn (MarketplaceReport $record, array $data) => $close($record, 'dismissed', $data)),
        ];
    }
}
