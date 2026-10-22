<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListFeaturedPlacements;
use App\Models\Marketplace\FeaturedPlacement;
use App\Services\Marketplace\FeaturedPlacements;
use App\Support\Marketplace\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Publicaciones destacadas (compradas o asignadas): historial y cancelación con motivo (solo SuperAdmin). */
class FeaturedPlacementResource extends Resource
{
    protected static ?string $model = FeaturedPlacement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Destacados de repuestos';

    protected static ?string $modelLabel = 'destacado';

    protected static ?string $pluralModelLabel = 'Destacados de repuestos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'marketplace/destacados';

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
        $review = static::canAccess() ? FeaturedPlacement::where('status', FeaturedPlacement::NEEDS_REVIEW)->count() : 0;

        return $review ? (string) $review : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('supplier:id,name'))
            ->columns([
                TextColumn::make('product_name')->label('Producto')->searchable()->description(fn (FeaturedPlacement $r) => $r->supplier?->name),
                TextColumn::make('source')->label('Origen')->formatStateUsing(fn ($state, FeaturedPlacement $r) => $r->sourceLabel()),
                TextColumn::make('period')->label('Período')->state(fn (FeaturedPlacement $r) => $r->starts_at ? $r->starts_at->format('d/m/Y').' al '.$r->ends_at->format('d/m/Y') : $r->days.' días (sin empezar)'),
                TextColumn::make('amount')->label('Precio')->state(fn (FeaturedPlacement $r) => $r->amount !== null ? Money::format((float) $r->amount, $r->currency) : 'Sin cargo'),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state, FeaturedPlacement $r) => $r->statusLabel())->badge()
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'pending_payment' => 'warning', 'needs_review' => 'danger', default => 'gray'
                    })
                    ->description(fn (FeaturedPlacement $r) => $r->status_reason),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(FeaturedPlacement::STATUSES),
                SelectFilter::make('source')->label('Origen')->options(['purchase' => 'Contratado', 'admin' => 'Asignado por Ascento']),
            ])
            ->recordActions([
                Action::make('cancel')->label('Cancelar')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (FeaturedPlacement $record) => static::canAccess() && in_array($record->status, [FeaturedPlacement::ACTIVE, FeaturedPlacement::PENDING_PAYMENT, FeaturedPlacement::NEEDS_REVIEW], true))
                    ->schema([Textarea::make('reason')->label('Motivo (lo ve el proveedor si lo contrató)')->rows(2)->maxLength(500)->required()])
                    ->modalDescription('Deja de mostrarse como destacado. Si hubo un pago, la devolución se gestiona aparte en Mercado Pago (no se hace sola).')
                    ->action(function (FeaturedPlacement $record, array $data) {
                        app(FeaturedPlacements::class)->cancel(auth()->user(), $record, $data['reason']);
                        Notification::make()->title('Destacado cancelado')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFeaturedPlacements::route('/')];
    }
}
