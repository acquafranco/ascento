<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListSupplierPayments;
use App\Models\Marketplace\SupplierPayment;
use App\Support\Marketplace\Money;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Cobros de Ascento a proveedores (suscripciones y destacados), tal como los
 * informó Mercado Pago. Solo los aprobados cuentan como ingreso; nunca los
 * importes de pedidos entre compradores y proveedores. Solo lectura.
 */
class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Cobros a proveedores';

    protected static ?string $modelLabel = 'cobro';

    protected static ?string $pluralModelLabel = 'Cobros a proveedores';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'marketplace/cobros';

    public static function canAccess(): bool
    {
        return MarketplaceModeration::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['supplier:id,name', 'placement:id,product_name']))
            ->columns([
                TextColumn::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i')->sortable()->description(fn (SupplierPayment $r) => $r->paid_at ? 'Acreditado '.$r->paid_at->format('d/m/Y') : null),
                TextColumn::make('supplier.name')->label('Proveedor')->searchable()
                    ->url(fn (SupplierPayment $r) => SupplierResource::getUrl('view', ['record' => $r->supplier_id])),
                TextColumn::make('kind')->label('Concepto')->formatStateUsing(fn ($state, SupplierPayment $r) => $state === 'featured' ? 'Destacado: '.($r->placement?->product_name ?? '—') : 'Suscripción'
                    .($r->period_end ? ' (hasta '.$r->period_end->format('d/m/Y').')' : '')),
                TextColumn::make('amount')->label('Importe')->state(fn (SupplierPayment $r) => Money::format($r->amount !== null ? (float) $r->amount : null, $r->currency)),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state, SupplierPayment $r) => $r->statusLabel())->badge()
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'pending' => 'warning', default => 'danger'
                    })
                    ->description(fn (SupplierPayment $r) => $r->status_detail),
                TextColumn::make('mp_payment_id')->label('Pago en Mercado Pago')->placeholder('—')->copyable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(SupplierPayment::STATUSES),
                SelectFilter::make('kind')->label('Concepto')->options(SupplierPayment::KINDS),
                SelectFilter::make('supplier_id')->label('Proveedor')->relationship('supplier', 'name')->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierPayments::route('/')];
    }
}
