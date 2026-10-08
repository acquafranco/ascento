<?php

namespace App\Filament\Resources\Receivables;

use App\Models\Building;
use App\Models\Client;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Billing\ReceivableService;
use App\Services\Billing\ServiceBillingService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Acciones de cobranzas reutilizables (listado, detalle, cliente, edificio).
 * Todas pasan por ReceivableService.
 */
class ReceivableActions
{
    public static function money(float|string|null $value): string
    {
        return '$'.number_format((float) $value, 2, ',', '.');
    }

    public static function pay(): Action
    {
        return Action::make('pay')
            ->label('Registrar pago')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Receivable $record) => $record->isOpen())
            ->modalHeading(fn (Receivable $record) => 'Registrar pago: '.$record->concept)
            ->modalSubmitActionLabel('Registrar pago')
            ->modalDescription(fn (Receivable $record) => 'Importe '.static::money($record->amount).' · Pagado '.static::money($record->paid_amount).' · Saldo '.static::money($record->balance()))
            ->schema(fn (Receivable $record) => [
                TextInput::make('amount')->label('Importe')->numeric()->prefix('$')->required()
                    ->minValue(0.01)->maxValue($record->balance())->default($record->balance())
                    ->helperText('Podés registrar un pago parcial.'),
                DatePicker::make('paid_at')->label('Fecha')->default(today())->required()->native(false)->displayFormat('d/m/Y')->maxDate(today()),
                Select::make('method')->label('Medio de pago')->options(ReceivablePayment::METHODS)->default('transfer')->required()->native(false),
                TextInput::make('notes')->label('Observación')->maxLength(255),
            ])
            ->action(function (Receivable $record, array $data) {
                app(ReceivableService::class)->registerPayment(
                    $record,
                    (float) $data['amount'],
                    Carbon::parse($data['paid_at']),
                    $data['method'],
                    $data['notes'] ?? null,
                    auth()->user(),
                );

                $record->refresh();

                Notification::make()
                    ->title($record->status === Receivable::PAID ? 'Pago registrado: quedó saldada' : 'Pago parcial registrado')
                    ->body($record->status === Receivable::PAID ? null : 'Saldo pendiente: '.static::money($record->balance()))
                    ->success()
                    ->send();
            });
    }

    public static function void(): Action
    {
        return Action::make('void')
            ->label('Anular')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Receivable $record) => $record->isOpen() && (float) $record->paid_amount == 0.0)
            ->requiresConfirmation()
            ->modalDescription('La obligación queda anulada (no se borra). Solo se pueden anular las que no tienen pagos.')
            ->schema([
                TextInput::make('reason')->label('Motivo')->required()->maxLength(255),
            ])
            ->action(function (Receivable $record, array $data) {
                app(ReceivableService::class)->void($record, $data['reason']);

                Notification::make()->title('Obligación anulada')->success()->send();
            });
    }

    /** Cobro manual ("otros conceptos"). */
    public static function createManual(?Client $client = null, ?Building $building = null): Action
    {
        $companyId = fn () => CompanyContext::currentId();

        return Action::make('createManual')
            ->label('Nuevo cobro')
            ->icon('heroicon-o-plus')
            ->modalHeading('Nuevo cobro manual')
            ->modalSubmitActionLabel('Crear cobro')
            ->schema([
                Select::make('client_id')
                    ->label('Cliente')
                    ->options(fn () => Client::where('company_id', $companyId())->withoutTrashed()->orderBy('name')->pluck('name', 'id'))
                    ->default($client?->id ?? $building?->client_id)
                    ->disabled($client !== null || $building !== null)
                    ->dehydrated()
                    ->searchable()->required()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('building_id', null)),
                Select::make('building_id')
                    ->label('Edificio (opcional)')
                    ->options(fn (Get $get) => Building::where('company_id', $companyId())->where('client_id', $get('client_id'))->withoutTrashed()
                        ->get()->mapWithKeys(fn (Building $b) => [$b->id => trim("{$b->name} {$b->address}")]))
                    ->default($building?->id)
                    ->searchable(),
                TextInput::make('concept')->label('Concepto')->required()->maxLength(255)->placeholder('Ej: Reparación de puerta de cabina'),
                TextInput::make('amount')->label('Importe')->numeric()->minValue(0.01)->prefix('$')->required(),
                DatePicker::make('due_date')->label('Vencimiento')->default(today()->addDays(10))->required()->native(false)->displayFormat('d/m/Y'),
                Textarea::make('notes')->label('Observaciones')->rows(2),
            ])
            ->action(function (array $data) use ($companyId) {
                $client = Client::where('company_id', $companyId())->findOrFail($data['client_id']);

                app(ReceivableService::class)->createManual(
                    $client,
                    $data['building_id'] ? (int) $data['building_id'] : null,
                    $data['concept'],
                    (float) $data['amount'],
                    Carbon::parse($data['due_date']),
                    auth()->user(),
                    $data['notes'] ?? null,
                );

                Notification::make()->title('Cobro creado')->success()->send();
            });
    }

    public static function generateFromServices(): Action
    {
        return Action::make('generateServices')
            ->label('Generar cobros de servicios')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Crea los cobros de los servicios activos hasta el período actual. Los que ya existen no se duplican.')
            ->visible(fn () => CompanyContext::currentId() !== null)
            ->action(function () {
                $companyId = CompanyContext::currentId();
                abort_if($companyId === null, 403);

                $created = app(ServiceBillingService::class)->generateAll($companyId);

                Notification::make()
                    ->title($created > 0 ? "Se generaron {$created} cobros" : 'No había cobros nuevos para generar')
                    ->success()
                    ->send();
            });
    }
}
