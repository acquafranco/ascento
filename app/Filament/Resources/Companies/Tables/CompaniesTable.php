<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Support\ManualSubscriptionActivator;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Support\CompanyContext;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('business_name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('cuit')
                    ->searchable(),
                TextColumn::make('tax_condition')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('address')
                    ->searchable(),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('province')
                    ->searchable(),
                TextColumn::make('logo')
                    ->searchable(),
                TextColumn::make('primary_color')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('whatsapp_connected')
                    ->boolean(),



            TextColumn::make('subscription_status')

                ->label('Suscripción')

                ->state(function ($record) {

                    $status = $record->latestSubscription?->status;

                    return match ($status) {

                        'authorized',

                        'active',

                        'trialing' => 'Activa',

                        'pending' => 'Pendiente',

                        'paused' => 'Pausada',

                        'canceled',

                        'cancelled' => 'Cancelada',

                        default => 'Sin suscripción',

                    };

                })

                ->badge()

                ->color(function ($state) {

                    return match ($state) {

                        'Activa' => 'success',

                        'Pendiente',

                        'Pausada' => 'warning',

                        'Cancelada',

                        'Sin suscripción' => 'danger',

                        default => 'gray',

                    };

                }),

            TextColumn::make('days_remaining')

                ->label('Días restantes')

                ->state(function ($record) {

                    $days = ManualSubscriptionActivator::daysRemaining($record);

                    if ($days === null) {
                        return 'Sin acceso';
                    }

                    if ($record->latestSubscription === null && $record->onTrial()) {
                        return "{$days} (trial)";
                    }

                    return (string) $days;

                })

                ->badge()

                ->color(function ($record) {

                    $days = ManualSubscriptionActivator::daysRemaining($record);

                    return match (true) {

                        $days === null => 'danger',

                        $days <= 3 => 'warning',

                        default => 'success',

                    };

                }),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('activarPago')
                    ->label('Activar pago (+ días)')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->schema([
                        TextInput::make('days')
                            ->label('Días a activar')
                            ->numeric()
                            ->default(30)
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Confirma que entró un pago: activa el acceso y suma los días al período que ya tuviera vigente (no lo resetea).')
                    ->action(function ($record, array $data) {
                        $subscription = ManualSubscriptionActivator::activate(
                            $record,
                            (int) $data['days']
                        );

                        Notification::make()
                            ->title('Empresa activada')
                            ->body(
                                $record->name . ' tiene acceso hasta '
                                . $subscription->current_period_end->format('d/m/Y')
                            )
                            ->success()
                            ->send();
                    }),

                Action::make('pausarManual')
                    ->label('Pausar acceso')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn ($record) => in_array($record->latestSubscription?->status, ['active', 'authorized', 'trialing'], true))
                    ->requiresConfirmation()
                    ->modalDescription('Corta el acceso ya mismo. No toca los días pagados — si después reanudás, los recupera.')
                    ->action(function ($record) {
                        $subscription = ManualSubscriptionActivator::pause($record);

                        if (!$subscription) {
                            Notification::make()
                                ->title('No hay ninguna suscripción para pausar')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Acceso pausado')
                            ->body($record->name . ' ya no puede entrar a la app.')
                            ->warning()
                            ->send();
                    }),

                Action::make('reanudarManual')
                    ->label('Reanudar (sin sumar días)')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->latestSubscription?->status === 'paused')
                    ->requiresConfirmation()
                    ->modalDescription('Reactiva el acceso usando el período que ya tenía pagado, sin sumar días nuevos.')
                    ->action(function ($record) {
                        $subscription = ManualSubscriptionActivator::resume($record);

                        if (!$subscription) {
                            Notification::make()
                                ->title('No se puede reanudar')
                                ->body('Ya no le queda período vigente — usá "Activar pago" para sumarle días nuevos.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Acceso reanudado')
                            ->body(
                                $record->name . ' recuperó el acceso hasta '
                                . $subscription->current_period_end->format('d/m/Y')
                            )
                            ->success()
                            ->send();
                    }),

                Action::make('entrar')
                    ->label('Entrar')
                    ->icon('heroicon-o-arrow-right')
                    ->action(function ($record) {
                        session([
                            'selected_company_id' => $record->id,
                        ]);

                        return redirect()->to('/admin');
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);


    }
}
