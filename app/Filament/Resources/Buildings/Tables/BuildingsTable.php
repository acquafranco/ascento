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

                        Select::make('user_id')
                            ->label('Empleado')

                            ->options(
                                User::query()

                                    // NO admins
                                    ->where(
                                        'role',
                                        '!=',
                                        'admin'
                                    )

                                    ->pluck(
                                        'name',
                                        'id'
                                    )
                                    ->toArray()
                            )

                            ->searchable()
                            ->required(),

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

    $exists = $record->users()
        ->where('users.id', $data['user_id'])
        ->wherePivot('type', $data['type'])
        ->exists();

    if ($exists) {

        \Filament\Notifications\Notification::make()
            ->title('Este empleado ya tiene este edificio asignado para ese trabajo.')
            ->danger()
            ->send();

        return;
    }

    $record->users()->attach(
        $data['user_id'],
        [
            'type' => $data['type'],
        ]
    );

    \Filament\Notifications\Notification::make()
        ->title('Empleado asignado correctamente.')
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

                        Select::make('user_id')
                            ->label('Empleado')
                            ->options(
                                fn ($record) =>
                                    $record->users()
                                        ->pluck(
                                            'name',
                                            'users.id'
                                        )
                            )
                            ->searchable()
                            ->required(),


                        Select::make('type')
                            ->label('Trabajo')
                            ->options([
                                'maintenance' => 'Mantenimiento',
                                'inspection' => 'Inspección',
                            ])
                            ->required(),

                    ])

                    ->action(function(array $data, $record){

                        $record->users()
                            ->wherePivot(
                                'type',
                                $data['type']
                            )
                            ->detach(
                                $data['user_id']
                            );

                    })

                    ->successNotificationTitle(
                        'Asignación eliminada'
                    ),
            ])

            ->toolbarActions([

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),

            ]);
    }
}
