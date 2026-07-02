<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Actions\Action;

class DeliveryNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveryNotes';

    protected static ?string $relatedResource = DeliveryNoteResource::class;

    public function table(Table $table): Table

    {

        return $table

            ->columns([

                Tables\Columns\TextColumn::make('number')

                    ->label('Remito'),

                Tables\Columns\TextColumn::make('building.name')

                    ->label('Edificio'),

                Tables\Columns\TextColumn::make('created_at')

                    ->dateTime('d/m/Y H:i'),

            ])

            ->filters([

                //

            ])

            ->actions([

    Action::make('ver')

        ->icon('heroicon-o-eye')

        ->url(fn ($record) =>
            route('delivery-notes.show', $record)
        )

        ->openUrlInNewTab(),

]);

    }
}
