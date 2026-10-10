<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\Quotes\QuoteBillingActions;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Services\Quotes\QuoteSender;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Presupuesto en el panel: el documento tal como lo ve el cliente y acciones
 * claras. Solo se muestran las que se pueden usar en el estado actual:
 * Enviar / PDF / Enlace (propuestas abiertas), cambios de estado permitidos,
 * Duplicar (siempre) y Editar (solo borrador o enviado sin cobro).
 */
class ViewQuote extends ViewRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::Quotes;

    protected static string $resource = QuoteResource::class;

    public function getTitle(): string
    {
        return 'Presupuesto '.$this->record->numberLabel();
    }

    public function getSubheading(): ?string
    {
        return $this->record->title;
    }

    private function statusAction(string $name, string $status, string $label, string $color, string $icon, string $confirm): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription($confirm)
            ->visible(fn () => in_array($status, $this->record->allowedTransitions(), true))
            ->action(function () use ($status, $label) {
                abort_unless(in_array($status, $this->record->allowedTransitions(), true), 403);
                $this->record->forceFill(['status' => $status])->save();
                Notification::make()->title($label.': listo')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label('Enviar al cliente')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn () => in_array($this->record->status, [Quote::DRAFT, Quote::SENT, 'pending'], true))
                ->modalHeading(fn () => 'Enviar '.$this->record->numberLabel())
                ->modalDescription('Le llega un correo con el resumen, el total, un botón para ver el presupuesto y el PDF adjunto. Si es un borrador, pasa a "Enviado".')
                ->fillForm(fn () => ['email' => $this->record->client?->email])
                ->schema([
                    TextInput::make('email')->label('Email del cliente')->email()->required()->maxLength(255),
                    Textarea::make('message')->label('Mensaje (opcional)')->rows(3)->maxLength(1000),
                ])
                ->action(function (array $data) {
                    try {
                        $ok = app(QuoteSender::class)->send($this->record, $data['email'], $data['message'] ?? null, auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title('No se pudo enviar')->body(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $ok
                        ? Notification::make()->title('Presupuesto enviado')->body('Se envió a '.$data['email'].'.')->success()->send()
                        : Notification::make()->title('No se pudo enviar el correo')->body('Probá de nuevo en unos minutos. El presupuesto quedó como "Enviado".')->warning()->send();
                }),

            Action::make('pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('quotes.pdf', $this->record))
                ->openUrlInNewTab(),

            ActionGroup::make([
                Action::make('link')
                    ->label('Copiar enlace para el cliente')
                    ->icon('heroicon-o-link')
                    ->visible(fn () => $this->record->isPubliclyVisible())
                    ->modalHeading('Enlace para el cliente')
                    ->modalDescription('Enlace seguro con vencimiento. Podés pegarlo en WhatsApp o en un correo.')
                    ->fillForm(fn () => ['url' => $this->record->signedPublicUrl()])
                    ->schema([TextInput::make('url')->label('Enlace')->readOnly()->copyable()])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->after(fn () => $this->record->log('link')),
                Action::make('whatsapp')
                    ->label('Enviar por WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn () => $this->record->isPubliclyVisible() && filled($this->record->client?->phone))
                    ->url(function () {
                        $phone = preg_replace('/\D/', '', (string) $this->record->client?->phone);
                        $phone = '549'.ltrim($phone, '0');
                        $text = 'Hola'.($this->record->client?->name ? ' '.$this->record->client->name : '').', te enviamos el presupuesto '.$this->record->numberLabel()
                            .' ('.$this->record->title.') por $ '.number_format((float) $this->record->amount, 2, ',', '.').': '.$this->record->signedPublicUrl();

                        return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
                    })
                    ->openUrlInNewTab(),
                Action::make('revoke')
                    ->label('Anular enlaces enviados')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Los enlaces que ya mandaste dejan de funcionar. Para que el cliente lo vea, enviáselo de nuevo.')
                    ->action(function () {
                        app(QuoteSender::class)->revokeLinks($this->record, auth()->user());
                        Notification::make()->title('Enlaces anulados')->success()->send();
                    }),
            ])->label('Compartir')->icon('heroicon-o-share')->button()->color('gray'),

            ActionGroup::make([
                $this->statusAction('markSent', Quote::SENT, 'Marcar como enviado', 'info', 'heroicon-o-check', 'Usalo si se lo entregaste por otro medio.'),
                $this->statusAction('approve', Quote::APPROVED, 'Marcar como aprobado', 'success', 'heroicon-o-hand-thumb-up', 'El cliente lo aceptó. Después podés generar el cobro.'),
                $this->statusAction('reject', Quote::REJECTED, 'Marcar como rechazado', 'danger', 'heroicon-o-hand-thumb-down', 'El cliente no lo aceptó.'),
                $this->statusAction('void', Quote::VOID, 'Anular', 'gray', 'heroicon-o-x-circle', 'Queda anulado y no se puede volver a usar (podés duplicarlo).'),
            ])->label('Cambiar estado')->icon('heroicon-o-arrows-right-left')->button()->color('gray')
                ->visible(fn () => $this->record->allowedTransitions() !== []),

            QuoteBillingActions::generate(),
            QuoteBillingActions::view(),

            Action::make('duplicate')
                ->label('Duplicar')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Crea un borrador nuevo con los mismos ítems y condiciones.')
                ->action(function () {
                    $copy = DB::transaction(function () {
                        $source = $this->record->load('items');
                        $copy = $source->replicate(['public_token', 'number', 'status', 'sent_at', 'sent_to', 'voided_at', 'shared_with_client', 'shared_at', 'issued_at']);
                        $copy->forceFill(['status' => Quote::DRAFT, 'created_by' => auth()->id(), 'issued_at' => today(), 'shared_with_client' => false])->save();

                        foreach ($source->items as $item) {
                            $copy->items()->create($item->only(['position', 'concept', 'description', 'quantity', 'unit_price']));
                        }

                        $copy->refreshTotal();
                        $copy->log('duplicated', 'Copia de '.$source->numberLabel());

                        return $copy;
                    });

                    $this->redirect(QuoteResource::getUrl('edit', ['record' => $copy]));
                }),

            EditAction::make()->visible(fn () => QuoteResource::canEdit($this->record)),
        ];
    }
}
