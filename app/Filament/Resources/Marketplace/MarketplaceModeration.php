<?php

namespace App\Filament\Resources\Marketplace;

use App\Models\Marketplace\MarketplaceReport;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Services\Activity\PlatformAudit;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Acciones de moderación del marketplace (solo SuperAdmin). Cada una vuelve a
 * verificar el rol, valida el estado de partida y queda en PlatformAudit.
 * Destacar no cambia la moderación: un producto oculto sigue oculto.
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
            Notification::make()->title($title)->success()->send();
        };

        return [
            Action::make('approve')->label('Aprobar')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Supplier $record) => self::allowed() && in_array($record->status, [Supplier::PENDING, Supplier::REJECTED], true))
                ->requiresConfirmation()->modalDescription('Su catálogo publicado pasa a verse para todas las empresas.')
                ->action(fn (Supplier $record) => $move($record, Supplier::APPROVED, null, 'Proveedor aprobado')),
            Action::make('reject')->label('Rechazar')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Supplier $record) => self::allowed() && $record->status === Supplier::PENDING)
                ->schema([self::reason()])
                ->action(fn (Supplier $record, array $data) => $move($record, Supplier::REJECTED, $data['reason'], 'Proveedor rechazado')),
            Action::make('suspend')->label('Suspender')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (Supplier $record) => self::allowed() && $record->status === Supplier::APPROVED)
                ->schema([self::reason()])->modalDescription('Su catálogo deja de verse y no puede cotizar. Los pedidos quedan como están.')
                ->action(fn (Supplier $record, array $data) => $move($record, Supplier::SUSPENDED, $data['reason'], 'Proveedor suspendido')),
            Action::make('reactivate')->label('Reactivar')->icon('heroicon-o-arrow-path')->color('success')
                ->visible(fn (Supplier $record) => self::allowed() && $record->status === Supplier::SUSPENDED)
                ->requiresConfirmation()
                ->action(fn (Supplier $record) => $move($record, Supplier::APPROVED, null, 'Proveedor reactivado')),
        ];
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
            Action::make('unhide')->label('Volver a mostrar')->icon('heroicon-o-eye')->color('success')
                ->visible(fn (SupplierProduct $record) => self::allowed() && $record->hidden_by_admin)
                ->requiresConfirmation()
                ->action(function (SupplierProduct $record) {
                    abort_unless(self::allowed(), 403);
                    $record->forceFill(['hidden_by_admin' => false, 'hidden_reason' => null])->save();
                    PlatformAudit::record('marketplace.product.unhidden', $record);
                    Notification::make()->title('Producto visible otra vez (si el proveedor lo tiene publicado)')->success()->send();
                }),
            Action::make('feature')->label('Destacar')->icon('heroicon-o-star')
                ->visible(fn (SupplierProduct $record) => self::allowed())
                ->fillForm(fn (SupplierProduct $record) => ['until' => $record->featured_until?->toDateString() ?? now()->addDays(30)->toDateString()])
                ->schema([DatePicker::make('until')->label('Destacado hasta')->required()->native(false)->minDate(now()->addDay()->startOfDay())->maxDate(now()->addYear())])
                ->modalDescription('Aparece primero en el catálogo. No saltea la moderación ni la aprobación del proveedor.')
                ->action(function (SupplierProduct $record, array $data) {
                    abort_unless(self::allowed(), 403);
                    $until = Carbon::parse($data['until'])->endOfDay();
                    abort_if($until->isPast() || $until->gt(now()->addYear()->endOfDay()), 422);
                    $record->forceFill(['featured_until' => $until])->save();
                    PlatformAudit::record('marketplace.product.featured', $record, ['until' => $until->toDateString()]);
                    Notification::make()->title('Destacado hasta el '.$until->format('d/m/Y'))->success()->send();
                }),
            Action::make('unfeature')->label('Quitar destacado')->icon('heroicon-o-star')->color('gray')
                ->visible(fn (SupplierProduct $record) => self::allowed() && $record->featured_until !== null)
                ->requiresConfirmation()
                ->action(function (SupplierProduct $record) {
                    abort_unless(self::allowed(), 403);
                    $record->forceFill(['featured_until' => null])->save();
                    PlatformAudit::record('marketplace.product.unfeatured', $record);
                    Notification::make()->title('Ya no está destacado')->success()->send();
                }),
        ];
    }

    public static function hide(SupplierProduct $product, string $reason): void
    {
        $product->forceFill(['hidden_by_admin' => true, 'hidden_reason' => trim($reason)])->save();
        PlatformAudit::record('marketplace.product.hidden', $product, ['reason' => $reason]);
    }

    /** @return list<Action> */
    public static function reportActions(): array
    {
        $close = function (MarketplaceReport $record, string $status, array $data) {
            abort_unless(self::allowed() && $record->status === 'open', 403);
            if ($status === 'resolved' && ($data['hide'] ?? false) && $record->product) {
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
