<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->withCount('pushSubscriptions'))
            ->columns([

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Correo electrónico')
                    ->searchable(),

                TextColumn::make('role')
                    ->label('Rol')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'admin' => 'Administrador',
                        'user' => 'Usuario',
                        default => ucfirst($state),
                    })
                    ->badge(),

                TextColumn::make('job_type')
            ->label('Tipo de trabajo')
            ->formatStateUsing(fn ($state) => match (strtolower($state)) {

                'technician', 'technico' => 'Técnico',
                'client' => 'Cliente',

                'electrician', 'electricista' => 'Electricista',

                null, '' => 'Sin definir',

                default => ucfirst($state),
            })
            ->badge(),

                // ¿Le llegan los avisos de órdenes nuevas al celular?
                TextColumn::make('push_subscriptions_count')
                    ->label('Avisos al celular')
                    ->formatStateUsing(fn ($state, $record) => match (true) {
                        $record->role !== 'technician' => '—',
                        blank(config('webpush.vapid.public_key')) => 'No disponible',
                        $state > 0 => 'Activados'.($state > 1 ? " ({$state} dispositivos)" : ''),
                        default => 'Sin activar',
                    })
                    ->badge()
                    ->color(fn ($state, $record) => $record->role !== 'technician' ? 'gray' : ($state > 0 ? 'success' : 'warning'))
                    ->tooltip('El técnico los activa desde Ascento en su celular (Inicio o Perfil).'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                TrashedFilter::make()->label('Desactivados'),
            ])
            ->recordActions([
                RestoreAction::make()->label('Reactivar'),

                // 🔥 VER USUARIO (correcto en Filament)
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil'),

            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Desactivar seleccionados')
                        ->modalHeading('Desactivar seleccionados')
                        ->modalSubmitActionLabel('Desactivar'),
                    RestoreBulkAction::make()->label('Reactivar seleccionados'),
                ]),
            ]);
    }
}
