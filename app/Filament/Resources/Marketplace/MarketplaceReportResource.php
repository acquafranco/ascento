<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListMarketplaceReports;
use App\Models\Marketplace\MarketplaceReport;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Reportes de publicaciones hechos por compradores (solo SuperAdmin). */
class MarketplaceReportResource extends Resource
{
    protected static ?string $model = MarketplaceReport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'Reportes de repuestos';

    protected static ?string $modelLabel = 'reporte';

    protected static ?string $pluralModelLabel = 'Reportes de repuestos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'marketplace/reportes';

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
        $open = static::canAccess() ? MarketplaceReport::where('status', 'open')->count() : 0;

        return $open ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['product.supplier:id,name', 'company:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('product.name')->label('Producto')->description(fn (MarketplaceReport $r) => $r->product?->supplier?->name)
                    ->state(fn (MarketplaceReport $r) => ($r->product?->name ?? '—').($r->product?->hidden_by_admin ? ' (oculto)' : '')),
                TextColumn::make('reason')->label('Motivo')->formatStateUsing(fn ($state, MarketplaceReport $r) => $r->reasonLabel())->description(fn (MarketplaceReport $r) => Str::limit((string) $r->details, 120)),
                TextColumn::make('company.name')->label('Reportó'),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => MarketplaceReport::STATUSES[$state] ?? $state)->badge()
                    ->color(fn ($state) => $state === 'open' ? 'warning' : 'gray')->description(fn (MarketplaceReport $r) => $r->resolution),
            ])
            ->filters([SelectFilter::make('status')->label('Estado')->options(MarketplaceReport::STATUSES)->default('open')])
            ->recordActions(MarketplaceModeration::reportActions());
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketplaceReports::route('/')];
    }
}
