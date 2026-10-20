<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Support\ClientSharing;
use App\Models\DeliveryNote;
use App\Support\DeliveryNotes\DeliveryNoteShare;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Js;

/**
 * Remito en el panel: el documento completo (con firmas) y las acciones de
 * la empresa: descargar el PDF, compartirlo (WhatsApp, correo, enlace) y
 * mostrarlo en el portal del cliente.
 */
class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    public function getTitle(): string
    {
        return 'Remito #'.DeliveryNoteShare::number($this->record);
    }

    protected function getHeaderActions(): array
    {
        /** @var DeliveryNote $note */
        $note = $this->record;

        return [
            Action::make('downloadPdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn () => DeliveryNoteShare::pdfUrl($note, download: true)),

            Action::make('openPdf')
                ->label('Ver PDF')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn () => DeliveryNoteShare::pdfUrl($note))
                ->openUrlInNewTab(),

            // Remito viejo sin enlace: se crea a pedido y aparecen las opciones.
            Action::make('createShareLink')
                ->label('Compartir')
                ->icon('heroicon-o-share')
                ->color('gray')
                ->visible(fn () => ! DeliveryNoteShare::canShare($note))
                ->action(function () use ($note) {
                    DeliveryNoteShare::ensureToken($note);
                    Notification::make()->title('Enlace creado')->body('Ya podés compartir el remito por WhatsApp, correo o copiando el enlace.')->success()->send();
                }),

            ActionGroup::make([
                Action::make('shareWhatsapp')
                    ->label('Enviar por WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn () => DeliveryNoteShare::whatsappUrl($note))
                    ->openUrlInNewTab(),
                Action::make('shareMail')
                    ->label('Enviar por correo')
                    ->icon('heroicon-o-envelope')
                    ->url(fn () => DeliveryNoteShare::mailUrl($note)),
                Action::make('copyLink')
                    ->label('Copiar enlace')
                    ->icon('heroicon-o-link')
                    // Js::from: comillas simples y caracteres escapados (va dentro de un atributo HTML).
                    ->alpineClickHandler(fn () => 'window.navigator.clipboard.writeText('.Js::from(DeliveryNoteShare::publicUrl($note)).')'
                        .'.then(() => new FilamentNotification().title('.Js::from('Enlace copiado').').success().send())'
                        .'.catch(() => window.prompt('.Js::from('Copiá el enlace:').', '.Js::from(DeliveryNoteShare::publicUrl($note)).'))'),
                Action::make('openPublic')
                    ->label('Ver como el cliente')
                    ->icon('heroicon-o-eye')
                    ->url(fn () => DeliveryNoteShare::publicUrl($note))
                    ->openUrlInNewTab(),
            ])
                ->label('Compartir')
                ->icon('heroicon-o-share')
                ->color('gray')
                ->button()
                ->visible(fn () => DeliveryNoteShare::canShare($note)),

            Action::make('shareInPortal')
                ->label('Mostrar en el portal')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('El cliente lo va a ver en su portal (solo en los edificios que tiene habilitados) y le avisamos.')
                ->visible(fn () => ClientSharing::enabled() && ! $note->shared_with_client)
                ->action(function () use ($note) {
                    ClientSharing::apply([$note], true);
                    Notification::make()->title('Compartido en el portal del cliente')->success()->send();
                }),

            Action::make('unshareInPortal')
                ->label('Quitar del portal')
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->visible(fn () => (bool) $note->shared_with_client)
                ->action(function () use ($note) {
                    ClientSharing::apply([$note], false);
                    Notification::make()->title('Ya no se ve en el portal')->success()->send();
                }),
        ];
    }

    /**
     * Las firmas (base64, pueden pesar cientos de KB) y el token público
     * no son campos del formulario: no tienen por qué viajar al navegador
     * en el estado de Livewire. Las firmas se muestran vía el infolist.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['signature'], $data['client_signature'], $data['public_token']);

        return $data;
    }
}
