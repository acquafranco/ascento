<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListSuppliers;
use App\Filament\Resources\Marketplace\Pages\ViewSupplier;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierSubscription;
use App\Services\Marketplace\PublicationLimits;
use App\Support\Countries;
use App\Support\Marketplace\Catalog;
use App\Support\Marketplace\Money;
use App\Support\Marketplace\SupplierStanding;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Proveedores del marketplace de repuestos (solo SuperAdmin): revisar,
 * aprobar, rechazar, suspender, reactivar, notas internas, plan (gratuito o
 * Premium) y uso del límite, cobros, destacados y el registro de acciones.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Proveedores de repuestos';

    protected static ?string $modelLabel = 'proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores de repuestos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'marketplace/proveedores';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return MarketplaceModeration::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::canAccess() ? Supplier::where('status', Supplier::PENDING)->count() : 0;

        return $pending ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pendientes de aprobación';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Proveedor')->description(fn (Supplier $r) => Countries::name($r->country).' · '.$r->tax_id)->searchable(['name', 'legal_name', 'tax_id']),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => Supplier::STATUSES[$state] ?? $state)->badge()
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                TextColumn::make('standing')->label('Estado')->state(fn (Supplier $r) => SupplierStanding::label(SupplierStanding::of($r)))
                    ->badge()->color(fn (Supplier $r) => match (SupplierStanding::of($r)) {
                        'premium' => 'warning', 'free' => 'success', default => 'gray'
                    }),
                TextColumn::make('usage')->label('Publicados')->state(function (Supplier $r) {
                    $u = PublicationLimits::usage($r, once(fn () => MarketplaceSettings::current()));

                    return $u['published'].' de '.$u['limit'].($u['over_limit'] ? ' · '.$u['over_limit'].' fuera del límite' : '');
                }),
                TextColumn::make('products_count')->label('Productos')->counts('products'),
                TextColumn::make('created_at')->label('Alta')->dateTime('d/m/Y')->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('subscription'))
            ->filters([
                SelectFilter::make('status')->label('Moderación')->options(Supplier::STATUSES),
                SelectFilter::make('plan')->label('Plan')->options([
                    'premium' => 'Premium activo', 'free' => 'Plan gratuito', 'pending' => 'Premium pendiente de pago',
                    'canceled' => 'Premium cancelado', 'past_due' => 'Cobro de Premium rechazado', 'over_limit' => 'Con productos fuera del límite',
                ])->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                    'premium' => $query->whereHas('subscription', fn ($q) => $q->where('current_period_end', '>', now())),
                    'free' => $query->whereDoesntHave('subscription', fn ($q) => $q->where('current_period_end', '>', now())),
                    'pending' => $query->whereHas('subscription', fn ($q) => $q->where('status', SupplierSubscription::PENDING)),
                    'canceled' => $query->whereHas('subscription', fn ($q) => $q->where('status', SupplierSubscription::CANCELED)),
                    'past_due' => $query->whereHas('subscription', fn ($q) => $q->where('status', SupplierSubscription::PAST_DUE)),
                    'over_limit' => $query->whereHas('products', fn ($q) => $q->where('status', SupplierProduct::OVER_LIMIT)),
                    default => $query,
                }),
            ])
            ->recordActions(MarketplaceModeration::supplierActions())
            ->recordUrl(fn (Supplier $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuenta')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('status')->label('Estado')->formatStateUsing(fn ($state) => Supplier::STATUSES[$state] ?? $state)->badge(),
                TextEntry::make('status_reason')->label('Motivo')->placeholder('—'),
                TextEntry::make('created_at')->label('Alta')->dateTime('d/m/Y H:i'),
                TextEntry::make('name')->label('Nombre comercial'),
                TextEntry::make('legal_name')->label('Razón social')->placeholder('—'),
                TextEntry::make('tax_id')->label('Identificación fiscal')->formatStateUsing(fn ($state, Supplier $r) => Countries::taxIdLabel($r->country).' '.$state.' ('.Countries::name($r->country).')'),
                TextEntry::make('contact_email')->label('Correo de ventas')->copyable(),
                TextEntry::make('contact_phone')->label('Teléfono'),
                TextEntry::make('website')->label('Sitio')->placeholder('—'),
                TextEntry::make('coverage')->label('Cobertura'),
                TextEntry::make('categories')->label('Rubros')->state(fn (Supplier $r) => collect($r->categories)->map(fn ($c) => Catalog::category($c))->implode(', ') ?: '—'),
                TextEntry::make('brands')->label('Marcas')->state(fn (Supplier $r) => implode(', ', $r->brands ?? []) ?: '—'),
                TextEntry::make('users')->label('Usuarios')->state(fn (Supplier $r) => $r->users()->pluck('email')->implode(', ')),
                TextEntry::make('operations')->label('Operaciones completadas')->state(fn (Supplier $r) => $r->completedOperations()),
                TextEntry::make('description')->label('Descripción')->placeholder('—')->columnSpanFull(),
                TextEntry::make('admin_notes')->label('Notas administrativas (no las ve el proveedor)')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Plan y publicaciones')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('standing')->label('Estado')->state(fn (Supplier $r) => SupplierStanding::label($r->standing()))->badge(),
                TextEntry::make('premium')->label('Premium')->state(fn (Supplier $r) => SupplierStanding::premiumDetail($r))->placeholder('No contratado'),
                TextEntry::make('usage')->label('Publicaciones')->state(function (Supplier $r) {
                    $u = PublicationLimits::usage($r);

                    return "{$u['published']} publicados de {$u['limit']} · {$u['drafts']} borradores · {$u['over_limit']} fuera del límite";
                }),
                TextEntry::make('currency')->label('Moneda de sus precios'),
                TextEntry::make('subscription.amount')->label('Importe de Premium')->state(fn (Supplier $r) => $r->subscription ? Money::format((float) $r->subscription->amount, $r->subscription->currency) : null)->placeholder('—'),
                TextEntry::make('subscription.current_period_end')->label('Premium pagado hasta')->dateTime('d/m/Y')->placeholder('—'),
            ]),
            Section::make('Cobros, destacados y acciones administrativas')->columnSpanFull()->schema([
                ViewEntry::make('history')->view('filament.marketplace.supplier-history')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSuppliers::route('/'), 'view' => ViewSupplier::route('/{record}')];
    }
}
