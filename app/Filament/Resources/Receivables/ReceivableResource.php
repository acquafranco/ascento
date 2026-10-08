<?php

namespace App\Filament\Resources\Receivables;

use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Pages\ViewReceivable;
use App\Filament\Resources\Receivables\RelationManagers\PaymentsRelationManager;
use App\Models\Receivable;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Cobranzas: obligaciones de cobro (cuenta corriente interna). */
class ReceivableResource extends Resource
{
    use ScopedToCurrentCompany;

    protected static ?string $model = Receivable::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Cobranzas';

    protected static ?string $modelLabel = 'Cobro';

    protected static ?string $pluralModelLabel = 'Cobranzas';

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $overdue = static::getEloquentQuery()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Cobros vencidos';
    }

    // Se crean y modifican solo desde ReceivableService (acciones).
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'partial' => 'info',
            'overdue' => 'danger',
            'void' => 'gray',
            default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'building']))
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('client.name')->label('Cliente')->searchable()->sortable(),
                TextColumn::make('building.name')->label('Edificio')
                    ->formatStateUsing(fn ($state, Receivable $r) => trim($r->building?->name.' '.$r->building?->address))->placeholder('—')->toggleable(),
                TextColumn::make('concept')->label('Concepto')->searchable()->wrap()
                    ->description(fn (Receivable $r) => Receivable::SOURCES[$r->source] ?? null),
                TextColumn::make('amount')->label('Importe')->money('ARS', locale: 'es_AR')->sortable(),
                TextColumn::make('balance')->label('Saldo')
                    ->state(fn (Receivable $r) => $r->balance())
                    ->money('ARS', locale: 'es_AR'),
                TextColumn::make('due_date')->label('Vencimiento')->date('d/m/Y')->sortable()
                    ->color(fn (Receivable $r) => $r->isOverdue() ? 'danger' : null),
                TextColumn::make('status')->label('Estado')->badge()
                    ->state(fn (Receivable $r) => $r->displayStatusLabel())
                    ->color(fn (Receivable $r) => static::statusColor($r->displayStatus())),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Estado')
                    ->options([
                        'open' => 'Pendientes y vencidas',
                        'pending' => 'Pendientes (sin vencer)',
                        'overdue' => 'Vencidas',
                        'partial' => 'Parcialmente pagadas',
                        'paid' => 'Pagadas',
                        'void' => 'Anuladas',
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->open(),
                        'pending' => $query->open()->whereDate('due_date', '>=', today()),
                        'overdue' => $query->overdue(),
                        'partial' => $query->where('status', Receivable::PARTIAL),
                        'paid' => $query->where('status', Receivable::PAID),
                        'void' => $query->where('status', Receivable::VOID),
                        default => $query,
                    }),
                SelectFilter::make('client_id')->label('Cliente')
                    ->relationship('client', 'name', fn ($query) => $query->where('company_id', CompanyContext::currentId()))
                    ->searchable()->preload(),
                Filter::make('period')
                    ->label('Vencimiento')
                    ->schema([
                        DatePicker::make('from')->label('Vence desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Vence hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '<=', $d))),
            ])
            ->recordActions([
                ReceivableActions::pay(),
                ViewAction::make()->label('Ver'),
            ])
            ->emptyStateHeading('No hay cobros con este filtro');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('client.name')->label('Cliente'),
                TextEntry::make('building.name')->label('Edificio')
                    ->formatStateUsing(fn ($state, Receivable $r) => trim($r->building?->name.' '.$r->building?->address))->placeholder('—'),
                TextEntry::make('status')->label('Estado')->badge()
                    ->state(fn (Receivable $r) => $r->displayStatusLabel())
                    ->color(fn (Receivable $r) => static::statusColor($r->displayStatus())),
                TextEntry::make('concept')->label('Concepto')->columnSpan(2),
                TextEntry::make('source')->label('Origen')
                    ->formatStateUsing(fn ($state, Receivable $r) => (Receivable::SOURCES[$state] ?? $state).($r->quote_id ? ' #'.$r->quote_id : '')),
                TextEntry::make('amount')->label('Importe')->money('ARS', locale: 'es_AR'),
                TextEntry::make('paid_amount')->label('Pagado')->money('ARS', locale: 'es_AR'),
                TextEntry::make('balance')->label('Saldo')->state(fn (Receivable $r) => $r->balance())->money('ARS', locale: 'es_AR'),
                TextEntry::make('due_date')->label('Vencimiento')->date('d/m/Y'),
                TextEntry::make('notes')->label('Observaciones')->placeholder('—')->columnSpan(2),
                TextEntry::make('void_reason')->label('Motivo de anulación')->visible(fn (Receivable $r) => $r->status === Receivable::VOID),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceivables::route('/'),
            'view' => ViewReceivable::route('/{record}'),
        ];
    }
}
