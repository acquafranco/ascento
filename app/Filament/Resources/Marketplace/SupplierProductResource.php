<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListSupplierProducts;
use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\SupplierProduct;
use App\Support\Marketplace\Catalog;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Publicaciones del marketplace: buscar, filtrar, ocultar, retirar, restaurar y destacar (solo SuperAdmin). */
class SupplierProductResource extends Resource
{
    protected static ?string $model = SupplierProduct::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Publicaciones de repuestos';

    protected static ?string $modelLabel = 'publicación';

    protected static ?string $pluralModelLabel = 'Publicaciones de repuestos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'marketplace/publicaciones';

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
        return $table->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('supplier:id,name,status')->withCount([
                'placements as running_placements' => fn ($q) => $q->running(),
                'placements as past_placements' => fn ($q) => $q->whereIn('status', [FeaturedPlacement::EXPIRED, FeaturedPlacement::CANCELLED]),
            ])->withCount(['reports as open_reports' => fn ($q) => $q->where('status', 'open')]))
            ->columns([
                TextColumn::make('name')->label('Producto')->description(fn (SupplierProduct $r) => collect([$r->brand, $r->model, $r->part_number])->filter()->implode(' · '))->searchable(['name', 'brand', 'model', 'part_number']),
                TextColumn::make('supplier.name')->label('Proveedor')->searchable(),
                TextColumn::make('category')->label('Rubro')->formatStateUsing(fn ($state) => Catalog::category($state)),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => SupplierProduct::STATUSES[$state] ?? $state)->badge(),
                IconColumn::make('hidden_by_admin')->label('Oculto')->boolean()->trueColor('danger')->falseColor('gray')->tooltip(fn (SupplierProduct $r) => $r->hidden_reason),
                TextColumn::make('featured')->label('Destacado')->state(fn (SupplierProduct $r) => $r->running_placements ? 'Vigente hasta '.$r->featured_until?->format('d/m/Y') : ($r->past_placements ? 'Vencido o cancelado' : '—'))->badge()->color(fn (SupplierProduct $r) => $r->running_placements ? 'warning' : 'gray'),
                TextColumn::make('open_reports')->label('Reportes abiertos')->badge()->color(fn ($state) => $state ? 'danger' : 'gray'),
                TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(SupplierProduct::STATUSES),
                TernaryFilter::make('hidden_by_admin')->label('Oculto por moderación'),
                SelectFilter::make('supplier_id')->label('Proveedor')->relationship('supplier', 'name')->searchable(),
                SelectFilter::make('category')->label('Rubro')->options(Catalog::CATEGORIES),
                SelectFilter::make('destacado')->label('Destacados')->options(['running' => 'Vigentes', 'past' => 'Vencidos o cancelados'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'running' => $query->whereHas('placements', fn ($q) => $q->running()),
                        'past' => $query->whereHas('placements', fn ($q) => $q->whereIn('status', [FeaturedPlacement::EXPIRED, FeaturedPlacement::CANCELLED])),
                        default => $query,
                    }),
                TernaryFilter::make('reportada')->label('Con reportes abiertos')->queries(
                    true: fn ($query) => $query->whereHas('reports', fn ($q) => $q->where('status', 'open')),
                    false: fn ($query) => $query->whereDoesntHave('reports', fn ($q) => $q->where('status', 'open')),
                ),
            ])
            ->recordActions(MarketplaceModeration::productActions());
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierProducts::route('/')];
    }
}
