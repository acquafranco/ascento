<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListSuppliers;
use App\Filament\Resources\Marketplace\Pages\ViewSupplier;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
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
 * aprobar, rechazar, suspender, reactivar, notas internas, estado comercial
 * (prueba y suscripción), cobros, destacados y el registro de acciones.
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
                TextColumn::make('standing')->label('Estado comercial')->state(fn (Supplier $r) => SupplierStanding::label(SupplierStanding::of($r, once(fn () => MarketplaceSettings::current()))))
                    ->badge()->color(fn (Supplier $r) => SupplierStanding::operating($r, once(fn () => MarketplaceSettings::current())) ? 'success' : 'gray')
                    ->description(fn (Supplier $r) => $r->trial_ends_at ? 'Prueba hasta '.$r->trial_ends_at->format('d/m/Y') : null),
                TextColumn::make('products_count')->label('Productos')->counts('products'),
                TextColumn::make('created_at')->label('Alta')->dateTime('d/m/Y')->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('subscription'))
            ->filters([
                SelectFilter::make('status')->label('Moderación')->options(Supplier::STATUSES),
                SelectFilter::make('comercial')->label('Estado comercial')->options([
                    'trial' => 'En prueba', 'trial_soon' => 'Prueba vence en 7 días', 'active' => 'Suscripción activa', 'pending' => 'Suscripción pendiente de pago',
                    'canceled' => 'Suscripción cancelada', 'past_due' => 'Cobro rechazado', 'operating' => 'Habilitados (catálogo visible)', 'not_operating' => 'Aprobados sin habilitación (prueba o suscripción vencida)',
                ])->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                    'trial' => $query->where('status', Supplier::APPROVED)->where('trial_ends_at', '>', now()),
                    'trial_soon' => $query->where('status', Supplier::APPROVED)->whereBetween('trial_ends_at', [now(), now()->addDays(7)]),
                    'active' => $query->whereHas('subscription', fn ($q) => $q->where('current_period_end', '>', now())),
                    'pending' => $query->whereHas('subscription', fn ($q) => $q->where('status', SupplierSubscription::PENDING)),
                    'canceled' => $query->whereHas('subscription', fn ($q) => $q->where('status', SupplierSubscription::CANCELED)),
                    'past_due' => $query->whereHas('subscription', fn ($q) => $q->where('status', SupplierSubscription::PAST_DUE)),
                    'operating' => $query->operating(),
                    'not_operating' => $query->where('status', Supplier::APPROVED)->whereNull('closed_at')->whereNotIn('id', Supplier::operating()->select('suppliers.id')),
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
            Section::make('Estado comercial')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('standing')->label('Estado')->state(fn (Supplier $r) => SupplierStanding::label($r->standing()))->badge(),
                TextEntry::make('trial')->label('Prueba')->state(fn (Supplier $r) => $r->trial_ends_at ? $r->trial_started_at?->format('d/m/Y').' al '.$r->trial_ends_at->format('d/m/Y') : 'Empieza al aprobarlo'),
                TextEntry::make('currency')->label('Moneda de sus precios'),
                TextEntry::make('subscription.status')->label('Suscripción')->formatStateUsing(fn ($state) => SupplierSubscription::STATUSES[$state] ?? $state)->placeholder('Sin suscripción'),
                TextEntry::make('subscription.amount')->label('Importe mensual')->state(fn (Supplier $r) => $r->subscription ? Money::format((float) $r->subscription->amount, $r->subscription->currency) : null)->placeholder('—'),
                TextEntry::make('subscription.current_period_end')->label('Pagada hasta')->dateTime('d/m/Y')->placeholder('—'),
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
