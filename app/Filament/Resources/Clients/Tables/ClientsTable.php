<?php

namespace App\Filament\Resources\Clients\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([

            TextColumn::make('name')
                ->label('Nombre')
                ->searchable(),

            TextColumn::make('type')
                ->label('Tipo')
                ->badge()
                ->formatStateUsing(fn ($state) => match ($state) {
                    'hospital' => 'Hospital',
                    'consorcio' => 'Consorcio',
                    'empresa' => 'Empresa',
                    'particular' => 'Particular',
                    default => ucfirst($state),
                }),

            TextColumn::make('contact_person')
                ->label('Contacto')
                ->searchable(),

            TextColumn::make('phone')
                ->label('Teléfono')
                ->searchable(),

            TextColumn::make('email')
                ->label('Email')
                ->searchable(),

            IconColumn::make('is_active')
                ->label('Activo')
                ->boolean(),

            TextColumn::make('created_at')
                ->label('Creado')
                ->dateTime('d/m/Y H:i')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('updated_at')
                ->label('Actualizado')
                ->dateTime('d/m/Y H:i')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->filters([
                TrashedFilter::make()->label('Desactivados'),
            ])
            ->recordActions([
                RestoreAction::make()->label('Reactivar'),
            EditAction::make()->label('Editar'),
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
