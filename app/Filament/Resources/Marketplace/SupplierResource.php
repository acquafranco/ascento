<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListSuppliers;
use App\Filament\Resources\Marketplace\Pages\ViewSupplier;
use App\Models\Marketplace\Supplier;
use App\Support\Countries;
use App\Support\Marketplace\Catalog;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Proveedores del marketplace de repuestos: revisar, aprobar, rechazar, suspender (solo SuperAdmin). */
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
                TextColumn::make('products_count')->label('Productos')->counts('products'),
                TextColumn::make('created_at')->label('Alta')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label('Estado')->options(Supplier::STATUSES)])
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
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSuppliers::route('/'), 'view' => ViewSupplier::route('/{record}')];
    }
}
