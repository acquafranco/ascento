<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListMarketplaceRequests;
use App\Filament\Resources\Marketplace\Pages\ViewMarketplaceRequest;
use App\Models\Marketplace\MarketplaceRequest;
use App\Services\Marketplace\MarketplaceRequests;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Operaciones del marketplace (solo SuperAdmin): todos los pedidos de
 * cotización entre empresas y proveedores, con productos, importes
 * cotizados, vencimientos, historial e incidencias. El SuperAdmin puede
 * resolver una incidencia o anular un pedido abierto con motivo (visible
 * para las dos partes y auditado); nunca cambia importes ni borra historial.
 */
class MarketplaceRequestResource extends Resource
{
    protected static ?string $model = MarketplaceRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Pedidos de repuestos (global)';

    protected static ?string $modelLabel = 'pedido de repuestos';

    protected static ?string $pluralModelLabel = 'Pedidos de repuestos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'marketplace/pedidos';

    protected static ?string $recordTitleAttribute = 'reference';

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
        $open = static::canAccess() ? MarketplaceRequest::whereNotNull('incident_at')->whereNull('incident_resolved_at')->count() : 0;

        return $open ? (string) $open : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Incidencias abiertas';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('supplier:id,name')->withCount('items'))
            ->columns([
                TextColumn::make('reference')->label('Pedido')->searchable()->description(fn (MarketplaceRequest $r) => $r->created_at->format('d/m/Y H:i')),
                TextColumn::make('buyer')->label('Empresa compradora')->state(fn (MarketplaceRequest $r) => $r->buyerLabel())->searchable(['buyer_name']),
                TextColumn::make('supplier.name')->label('Proveedor')->searchable(),
                TextColumn::make('items_count')->label('Productos'),
                TextColumn::make('quoted_total')->label('Cotizado')->state(fn (MarketplaceRequest $r) => $r->quoted_total !== null ? $r->money((float) $r->quoted_total) : '—')
                    ->description(fn (MarketplaceRequest $r) => $r->quoteExpiresAt() ? ($r->quoteExpired() ? 'Vencida el ' : 'Vence el ').$r->quoteExpiresAt()->format('d/m/Y') : null),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => MarketplaceRequest::STATUSES[$state] ?? $state)->badge()
                    ->color(fn ($state) => match ($state) {
                        'completed' => 'success', 'accepted' => 'warning', 'quoted' => 'info', 'sent' => 'gray', default => 'danger'
                    }),
                TextColumn::make('incident')->label('Incidencia')->state(fn (MarketplaceRequest $r) => $r->hasOpenIncident() ? 'Abierta' : ($r->incident_at ? 'Resuelta' : '—'))
                    ->badge()->color(fn (MarketplaceRequest $r) => $r->hasOpenIncident() ? 'danger' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(MarketplaceRequest::STATUSES),
                SelectFilter::make('supplier_id')->label('Proveedor')->relationship('supplier', 'name')->searchable(),
                TernaryFilter::make('incidencia')->label('Incidencia abierta')->queries(
                    true: fn ($query) => $query->whereNotNull('incident_at')->whereNull('incident_resolved_at'),
                    false: fn ($query) => $query->where(fn ($q) => $q->whereNull('incident_at')->orWhereNotNull('incident_resolved_at')),
                ),
            ])
            ->recordUrl(fn (MarketplaceRequest $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pedido')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('reference')->label('Referencia'),
                TextEntry::make('status')->label('Estado')->formatStateUsing(fn ($state) => MarketplaceRequest::STATUSES[$state] ?? $state)->badge(),
                TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
                TextEntry::make('buyer')->label('Empresa compradora')->state(fn (MarketplaceRequest $r) => $r->buyerLabel()),
                TextEntry::make('supplier.name')->label('Proveedor')->url(fn (MarketplaceRequest $r) => SupplierResource::getUrl('view', ['record' => $r->supplier_id])),
                TextEntry::make('quoted_total')->label('Total cotizado')->state(fn (MarketplaceRequest $r) => $r->quoted_total !== null ? $r->money((float) $r->quoted_total) : '—')
                    ->helperText('Cotización entre las partes: Ascento no la cobra ni la cuenta como ingreso.'),
                TextEntry::make('expires')->label('Validez')->state(fn (MarketplaceRequest $r) => $r->quoteExpiresAt()?->format('d/m/Y').($r->quoteExpired() ? ' (vencida)' : ''))->placeholder('—'),
                TextEntry::make('accepted_at')->label('Aceptada')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('completed_at')->label('Recepción confirmada')->dateTime('d/m/Y H:i')->placeholder('—'),
            ]),
            Section::make('Productos e historial')->columnSpanFull()->schema([
                ViewEntry::make('detail')->view('filament.marketplace.request-detail')->columnSpanFull(),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function adminActions(): array
    {
        return [
            Action::make('resolveIncident')->label('Resolver incidencia')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (MarketplaceRequest $record) => static::canAccess() && $record->hasOpenIncident())
                ->schema([Textarea::make('note')->label('Respuesta (la ven las dos partes)')->rows(3)->maxLength(2000)->required()])
                ->action(function (MarketplaceRequest $record, array $data) {
                    app(MarketplaceRequests::class)->resolveIncident(auth()->user(), $record, $data['note']);
                    Notification::make()->title('Incidencia resuelta')->success()->send();
                }),
            Action::make('adminCancel')->label('Anular pedido')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (MarketplaceRequest $record) => static::canAccess() && $record->isOpen())
                ->schema([Textarea::make('reason')->label('Motivo (lo ven las dos partes)')->rows(3)->maxLength(2000)->required()])
                ->modalDescription('Para incidencias administrativas (fraude, cuenta cerrada, pedido imposible). No cambia importes ni borra el historial: queda un evento «Cancelado» por Ascento y se avisa a las dos partes.')
                ->action(function (MarketplaceRequest $record, array $data) {
                    app(MarketplaceRequests::class)->adminCancel(auth()->user(), $record, $data['reason']);
                    Notification::make()->title('Pedido anulado')->success()->send();
                }),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketplaceRequests::route('/'), 'view' => ViewMarketplaceRequest::route('/{record}')];
    }
}
