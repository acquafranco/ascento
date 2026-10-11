<?php

namespace App\Filament\Resources\Quotes\Tables;

use App\Models\Quote;

use App\Filament\Resources\Quotes\QuoteBillingActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;

use Filament\Tables\Table;

use Filament\Tables\Columns\TextColumn;

use Filament\Tables\Filters\SelectFilter;

class QuotesTable
{
    public static function configure(Table $table): Table
    {
        return $table

            // Eager loading de lo que usan las columnas/acciones (evita N+1).
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['building.client', 'client', 'company', 'receivables']))
            ->defaultSort('created_at', 'desc')

            ->columns([

                TextColumn::make('building.name')
                    ->label('Edificio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('number')
                    ->label('N°')
                    ->formatStateUsing(fn ($state, $record) => $record->numberLabel())
                    ->sortable()
                    ->searchable(),

                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Total')
                    ->money('ARS')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    // Incluye "Vencido" (calculado con la fecha de validez).
                    ->state(fn (Quote $record) => $record->displayStatus())
                    ->formatStateUsing(fn (Quote $record) => $record->displayStatusLabel())
                    ->color(fn (string $state) => Quote::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('status', $direction)),

                TextColumn::make('valid_until')
                    ->label('Válido hasta')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {

                        'low' => 'Baja',
                        'normal' => 'Normal',
                        'high' => 'Alta',
                        'urgent' => 'Urgente',

                        default => $state,

                    })
                    ->color(fn (string $state) => match ($state) {

                        'low' => 'gray',
                        'normal' => 'info',
                        'high' => 'warning',
                        'urgent' => 'danger',

                        default => 'gray',

                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

            ])

            ->filters([

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([...Quote::STATUSES, Quote::EXPIRED => 'Vencido'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        Quote::EXPIRED => $query->expired(),
                        Quote::DRAFT, Quote::SENT => $query->where('status', $data['value'])
                            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today())),
                        default => $query->where('status', $data['value']),
                    }),

                SelectFilter::make('priority')
                    ->label('Prioridad')
                    ->options([

                        'low' => 'Baja',
                        'normal' => 'Normal',
                        'high' => 'Alta',
                        'urgent' => 'Urgente',

                    ]),

            ])

            ->recordActions([

                QuoteBillingActions::generate(),
                QuoteBillingActions::view(),

                ViewAction::make()
                    ->label('Ver'),

                EditAction::make()
                    ->label('Editar'),

                // Envío y enlaces: desde la vista del presupuesto (enlace firmado
                // con vencimiento y registro en el historial).
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record) => route('quotes.pdf', $record))
                    ->openUrlInNewTab(),


            ])

            ->toolbarActions([

                BulkActionGroup::make([

                    DeleteBulkAction::make(),

                ]),

            ])
            // Compartir con el portal del cliente (privado por defecto).
            ->pushColumns([\App\Filament\Support\ClientSharing::column()])
            ->pushToolbarActions([\Filament\Actions\BulkActionGroup::make(\App\Filament\Support\ClientSharing::bulkActions())->label('Portal del cliente')]);
    }
}
