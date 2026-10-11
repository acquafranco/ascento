<?php

namespace App\Filament\Resources\Quotes;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Quote;
use App\Models\Receivable;
use App\Services\Billing\ReceivableService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Presupuesto aprobado → obligación de cobro. Solo por acción explícita
 * (nunca al ver o editar el presupuesto) y una sola vez.
 */
class QuoteBillingActions
{
    public static function activeReceivable(Quote $quote): ?Receivable
    {
        // En listados viene precargado (sin una consulta por fila).
        return $quote->relationLoaded('receivables')
            ? $quote->receivables->firstWhere(fn (Receivable $r) => $r->status !== Receivable::VOID)
            : $quote->receivables()->where('status', '!=', Receivable::VOID)->first();
    }

    public static function generate(): Action
    {
        return Action::make('generateReceivable')
            ->label('Generar cobro')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Quote $record) => $record->status === 'approved' && ! static::activeReceivable($record))
            ->modalHeading(fn (Quote $record) => 'Generar cobro del presupuesto #'.$record->id)
            ->modalSubmitActionLabel('Generar cobro')
            ->schema(fn (Quote $record) => [
                TextInput::make('concept')->label('Concepto')->required()->maxLength(255)
                    ->default('Presupuesto #'.$record->id.' — '.$record->title),
                TextInput::make('amount')->label('Importe')->numeric()->minValue(0.01)->prefix('$')->required()
                    ->default((float) $record->amount),
                DatePicker::make('due_date')->label('Vencimiento')->required()->native(false)->displayFormat('d/m/Y')
                    ->default(today()->addDays(10)),
            ])
            ->action(function (Quote $record, array $data) {
                $receivable = app(ReceivableService::class)->fromQuote(
                    $record,
                    $data['concept'],
                    (float) $data['amount'],
                    Carbon::parse($data['due_date']),
                    auth()->user(),
                );
                $record->log('receivable', '$ '.number_format((float) $data['amount'], 2, ',', '.'));

                Notification::make()
                    ->title('Cobro generado')
                    ->body($receivable->concept)
                    ->success()
                    ->actions([Action::make('see')->label('Ver cobro')->url(ReceivableResource::getUrl('view', ['record' => $receivable]))])
                    ->send();
            });
    }

    public static function view(): Action
    {
        return Action::make('viewReceivable')
            ->label('Ver cobro')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->visible(fn (Quote $record) => static::activeReceivable($record) !== null)
            ->url(fn (Quote $record) => ($receivable = static::activeReceivable($record))
                ? ReceivableResource::getUrl('view', ['record' => $receivable])
                : null);
    }
}
