<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [

            Action::make('publico')
                ->label('Abrir presupuesto')
                ->icon('heroicon-o-globe-alt')
                ->url(fn () => route('quotes.public', [
                    'token' => $this->record->public_token,
                ]))
                ->openUrlInNewTab(),

            Action::make('whatsapp')
                ->label('Enviar por WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->url(function () {

                    $url = route('quotes.public', [
                        'token' => $this->record->public_token,
                    ]);

                    $cliente = $this->record->client?->name ?? 'Cliente';

                    $telefono = $this->record->client?->phone;

                    if (!$telefono) {
                        return null;
                    }

                    $telefono = preg_replace('/\D/', '', $telefono);

                    if (str_starts_with($telefono, '0')) {
                        $telefono = substr($telefono, 1);
                    }

                    $telefono = '549' . $telefono;

                    $importe = '$ ' . number_format($this->record->amount, 0, ',', '.');

                    $mensaje =
                        "Hola {$cliente}, ¿cómo estás?

                        Esperamos que te encuentres muy bien.

                        Te enviamos el presupuesto solicitado para el siguiente trabajo:

                        📋 {$this->record->title}

                        💰 Importe: {$importe}

                        Podés ver el presupuesto completo en el siguiente enlace:
                        {$url}

                        Muchas gracias por confiar en nosotros.";

                    return "https://wa.me/{$telefono}?text=" . urlencode($mensaje);

                })
                ->openUrlInNewTab(),

            Action::make('correo')
                ->label('Enviar por correo')
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->url(function () {

                    $url = route('quotes.public', [
                        'token' => $this->record->public_token,
                    ]);

                    $cliente = $this->record->client?->name ?? 'Cliente';

                    $importe = '$ ' . number_format($this->record->amount, 0, ',', '.');

                    $asunto = "Presupuesto - {$this->record->title}";

                    $cuerpo =
                        "Hola {$cliente}, ¿cómo estás?

                        Esperamos que te encuentres muy bien.

                        Adjuntamos el presupuesto solicitado.

                        Trabajo:
                        {$this->record->title}

                        Importe:
                        {$importe}

                        Podés ver el presupuesto completo en el siguiente enlace:
                        {$url}

                        Quedamos a disposición por cualquier consulta.

                        Muchas gracias.

                        Saludos cordiales.";

                    return 'mailto:' .
                        $this->record->client?->email .
                        '?asunto=' . urlencode($asunto) .
                        '&cuerpo=' . urlencode($cuerpo);

                }),

            EditAction::make(),

        ];
    }
}
