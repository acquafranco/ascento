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

                TextColumn::make('users.name')
                    ->label('Técnico')
                    ->badge()
                    ->separator(',')
                    ->placeholder('Sin asignar')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
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

                    ->action(function (
                        array $data,
                        $record
                    ) {

                        $exists = $record->users()
                            ->where('users.id',$data['user_id'])
                            ->wherePivot('type',$data['type'])
                            ->exists();

                        if(!$exists){

                            $record->users()->attach(
                                $data['user_id'],
                                [
                                    'type'=>$data['type']
                                ]
                            );

                        }
                    })

                    ->successNotificationTitle(
                        'Empleado asignado'
                    ),

                /*
                |--------------------------------------------------------------------------
                | QUITAR EMPLEADO
                |--------------------------------------------------------------------------
                */

                Action::make('removeTechnician')
                    ->label('Quitar empleado')
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
                                    ->toArray()
                            )

                            ->searchable()
                            ->required(),

                    ])

                    ->action(function (
                        array $data,
                        $record
                    ) {

                        $record->users()
                            ->detach(
                                $data['user_id']
                            );
                    })

                    ->successNotificationTitle(
                        'Empleado quitado'
                    ),
            ])

            ->toolbarActions([

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),

            ]);
    }
}
