<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
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

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->recordActions([

                // 🔥 VER USUARIO (correcto en Filament)
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil'),

            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Eliminar seleccionados'),
                ]),
            ]);
    }
}
