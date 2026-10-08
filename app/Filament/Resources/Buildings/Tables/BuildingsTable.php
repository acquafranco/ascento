<?php

namespace App\Filament\Resources\Buildings\Tables;

use App\Models\User;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Filters\TrashedFilter;
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
            // Eager loading de lo que usan las columnas/acciones (evita N+1).
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['client', 'users']))
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

                TextColumn::make('map_color')
                    ->label('Color')
                    ->state(fn ($record) => $record->mapColorKey())
                    ->formatStateUsing(fn ($state) => \App\Models\Building::MAP_COLORS[$state][0] ?? '')
                    ->icon('heroicon-s-map-pin')
                    ->iconColor(fn ($record) => \Filament\Support\Colors\Color::hex($record->mapColorHex()))
                    ->toggleable(),

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
                RestoreAction::make()->before(\App\Support\Plans\PlanRestoreGuard::before(\App\Enums\PlanLimit::Buildings))->label('Reactivar'),

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
                            ->options(function () {
                                $user = auth()->user();

                                $companyId = $user->isSuperAdmin()
                                    ? session('selected_company_id')
                                    : $user->company_id;

                                return User::query()
                                    ->where('company_id', $companyId)
                                    ->where('role', '!=', 'admin')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            }),

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
                TrashedFilter::make()->label('Desactivados'),
                    SelectFilter::make('locality')
                        ->label('Localidad')
                        ->options(function () {
                            $user = auth()->user();

                            $companyId = $user->isSuperAdmin()
                                ? session('selected_company_id')
                                : $user->company_id;

                            return \App\Models\Building::query()
                                ->where('company_id', $companyId)
                                ->whereNotNull('locality')
                                ->pluck('locality', 'locality')
                                ->toArray();
                        })
                ])
            ->toolbarActions([

                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Desactivar seleccionados')
                        ->modalHeading('Desactivar seleccionados')
                        ->modalSubmitActionLabel('Desactivar'),
                    RestoreBulkAction::make()->before(\App\Support\Plans\PlanRestoreGuard::beforeBulk(\App\Enums\PlanLimit::Buildings))->label('Reactivar seleccionados'),
                ]),

            ]);
    }
}
