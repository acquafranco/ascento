<?php

namespace App\Filament\Resources\Quotes\Tables;

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
                    ->label('Monto')
                    ->money('ARS')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {

                        'pending' => 'Pendiente',
                        'sent' => 'Enviado',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',

                        default => $state,

                    })
                    ->color(fn (string $state) => match ($state) {

                        'pending' => 'warning',
                        'sent' => 'info',
                        'approved' => 'success',
                        'rejected' => 'danger',

                        default => 'gray',

                    })
                    ->sortable(),

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
                    ->options([

                        'pending' => 'Pendiente',
                        'sent' => 'Enviado',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',

                    ]),

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

                ViewAction::make()
                    ->label('Ver'),

                EditAction::make()
                    ->label('Editar'),

                ActionGroup::make([

                    Action::make('publico')
                        ->label('Abrir presupuesto')
                        ->icon('heroicon-o-globe-alt')
                        ->url(fn ($record) => route('quotes.public', [
                            'token' => $record->public_token,
                        ]))
                        ->openUrlInNewTab(),

                   Action::make('whatsapp')
    ->label('Enviar por WhatsApp')
    ->icon('heroicon-o-chat-bubble-left-right')
    ->color('success')
    ->url(function ($record) {

        $url = route('quotes.public', [
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
                            urlencode($asunto) .
                            '&body=' .
                            urlencode($cuerpo);

                    }),

                ])
                ->label('Compartir')
                ->icon('heroicon-o-share'),

            ])

            ->toolbarActions([

                BulkActionGroup::make([

                    DeleteBulkAction::make(),

                ]),

            ]);
    }
}
