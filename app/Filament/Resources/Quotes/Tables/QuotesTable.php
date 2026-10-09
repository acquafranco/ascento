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

                ActionGroup::make([

                    Action::make('publico')
                        ->label('Abrir presupuesto')
                        ->icon('heroicon-o-globe-alt')
                        ->url(fn ($record) => route('quotes.public', [
                            'company' => $record->company->slug,
                            'token' => $record->public_token,
                        ]))
                        ->openUrlInNewTab(),

                   Action::make('whatsapp')
    ->label('Enviar por WhatsApp')
    ->icon('heroicon-o-chat-bubble-left-right')
    ->color('success')
    ->url(function ($record) {

        $url = route('quotes.public', [
            'company' => $record->company->slug,
            'token' => $record->public_token,
        ]);

        // 🔥 SEGURIDAD: evitar null
        $cliente = $record->client?->name ?? 'Cliente';

        $telefonoRaw = $record->client?->phone;

        if (!$telefonoRaw) {
            return null; // no hay teléfono → no abre WhatsApp
        }

        $telefono = preg_replace('/\D/', '', $telefonoRaw);

        // sacar 0 inicial si existe
        if (str_starts_with($telefono, '0')) {
            $telefono = substr($telefono, 1);
        }

        // 🇦🇷 Argentina formato correcto
        $telefono = '549' . $telefono;

        $monto = '$ ' . number_format($record->amount, 0, ',', '.');

        $mensaje =
"Hola {$cliente}, ¿cómo estás?

Esperamos que te encuentres muy bien.

Te enviamos el presupuesto correspondiente al siguiente trabajo:

📋 {$record->title}

💰 Importe: {$monto}

Podés visualizarlo aquí:
{$url}

Muchas gracias.";

        return "https://wa.me/{$telefono}?text=" . urlencode($mensaje);

    })
    ->openUrlInNewTab(),
                    Action::make('mail')
            ->label('Enviar por Email')
            ->icon('heroicon-o-envelope')
            ->color('info')
            ->url(function ($record) {

                $url = route('quotes.public', [
                    'company' => $record->company->slug,
                    'token' => $record->public_token,
                ]);

                $cliente = $record->building?->client?->name ?? 'cliente';

                $monto = '$ ' . number_format($record->amount, 0, ',', '.');

                $asunto = "Presupuesto - {$record->title}";

                $cuerpo =
                "Hola {$cliente},

                Adjuntamos el presupuesto solicitado.

                Trabajo:
                {$record->title}

                Monto:
                {$monto}

                Podés consultarlo desde el siguiente enlace:

                {$url}

                Quedamos a disposición por cualquier consulta.

                Saludos cordiales.";

                        return 'mailto:?subject=' .
                            rawurlencode($asunto) .
                            '&body=' .
                            rawurlencode($cuerpo);

                    }),

                ])
                ->label('Compartir')
                ->icon('heroicon-o-share'),

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
