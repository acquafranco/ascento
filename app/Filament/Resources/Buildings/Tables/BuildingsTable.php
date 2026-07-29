<?php

namespace App\Filament\Resources\Buildings\Tables;

use App\Models\User;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;

class BuildingsTable
{
    public static function configure(
        Table $table
    ): Table
    {
        return $table
            ->columns([

                TextColumn::make('name')
                    ->label('Edificio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('address')
                    ->label('Dirección')
                    ->searchable(),

                TextColumn::make('locality')
                    ->label('Localidad')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('neighborhood')
                    ->label('Barrio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable(),

                TextColumn::make('elevator_count')
                    ->label('Asc.')
                    ->sortable(),

                TextColumn::make('freight_elevator_count')
                    ->label('Mont.')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('technicians')
                ->label('Técnico')
                ->state(function ($record) {

                    if ($record->users->isEmpty()) {
                        return 'Sin asignar';
                    }

                    return $record->users
                        ->map(function ($user) {

                            $tipo = match ($user->pivot->type) {
                                'maintenance' => 'Mantenimiento',
                                'inspection' => 'Inspección',
                                default => '',
                            };

                            return "{$user->name} ({$tipo})";
                        })
                        ->implode(', ');
                })
                ->badge()
                ->color('success'),
            ])

            ->recordActions([

                EditAction::make(),

                /*
                |--------------------------------------------------------------------------
                | ASIGNAR EMPLEADO
                |--------------------------------------------------------------------------
                */

                Action::make('assignTechnician')
                    ->label('Asignar empleado')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')

                    ->form([

                        Select::make('user_ids')
                            ->label('Empleados')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->options(
                                User::query()
                                    ->where('company_id', auth()->user()->company_id)
                                    ->where('role', '!=', 'admin')
                                    ->pluck('name', 'id')
                                    ->toArray()
                            ),

                                                Select::make('type')
                                                    ->label('Trabajo')
                                                    ->options([
                                                        'maintenance'
                                                            => 'Mantenimiento',

                                                        'inspection'
                                                            => 'Inspección',
                                                    ])
                                                    ->required(),

                                            ])

                                        ->action(function (array $data, $record) {

                            /*
                            |--------------------------------------------------------------------------
                            | INSPECCIÓN
                            |--------------------------------------------------------------------------
                            */

                            if ($data['type'] === 'inspection') {

                                if (count($data['user_ids']) > 1) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Solo puede existir un inspector por edificio.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                if (
                                    $record->users()
                                        ->wherePivot('type', 'inspection')
                                        ->exists()
                                ) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Este edificio ya tiene un inspector asignado.')
                                        ->danger()
                                        ->send();

                                    return;
                                }
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | MANTENIMIENTO
                            |--------------------------------------------------------------------------
                            */

                            if ($data['type'] === 'maintenance') {

                                if (count($data['user_ids']) > 2) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Solo pueden asignarse hasta dos técnicos de mantenimiento.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $actuales = $record->users()
                                    ->wherePivot('type', 'maintenance')
                                    ->count();

                                if ($actuales + count($data['user_ids']) > 2) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Este edificio ya tiene el máximo de técnicos de mantenimiento.')
                                        ->danger()
                                        ->send();

                                    return;
                                }
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | ASIGNAR
                            |--------------------------------------------------------------------------
                            */

                            foreach ($data['user_ids'] as $userId) {

                                $existe = $record->users()
                                    ->where('users.id', $userId)
                                    ->wherePivot('type', $data['type'])
                                    ->exists();

                                if (! $existe) {

                                    $record->users()->attach(
                                        $userId,
                                        [
                                            'type' => $data['type'],
                                        ]
                                    );

                                }

                            }

                                    \Filament\Notifications\Notification::make()
                                        ->title('Empleados asignados correctamente.')
                                        ->success()
                                        ->send();

                                }),
                                                /*
                                                |--------------------------------------------------------------------------
                                                | QUITAR EMPLEADO
                                                |--------------------------------------------------------------------------
                                                */

                                                Action::make('removeTechnician')
                                                    ->label('Quitar asignación')
                                                    ->icon('heroicon-o-user-minus')
                                                    ->color('danger')

                                                    ->form([

                                    Select::make('assignment')
                                        ->label('Asignación')
                                        ->searchable()
                                        ->required()
                                        ->options(function ($record) {

                                            return $record->users
                                                ->mapWithKeys(function ($user) {

                                                    return [

                                                        $user->id.'-'.$user->pivot->type =>

                                                            $user->name.' • '.

                                                            (
                                                                $user->pivot->type === 'maintenance'
                                                                    ? 'Mantenimiento'
                                                                    : 'Inspección'
                                                            ),

                                                    ];

                                                });

                                        }),

                                ])

                                        ->action(function (array $data, $record) {

                            [$userId, $type] = explode('-', $data['assignment']);

                            $record->users()
                                ->wherePivot('type', $type)
                                ->detach($userId);

                        })

                    ->successNotificationTitle(
                        'Asignación eliminada'
                    ),
            ])
                ->filters([
                    SelectFilter::make('locality')
                        ->label('Localidad')
                        ->options(
                            \App\Models\Building::query()
                                ->where('company_id', auth()->user()->company_id)
                                ->whereNotNull('locality')
                                ->pluck('locality','locality')
                                ->toArray()
                        )

                ])
            ->toolbarActions([

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),

            ]);
    }
}
