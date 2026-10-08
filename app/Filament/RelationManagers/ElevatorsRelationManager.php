<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\Elevators\ElevatorResource;
use App\Models\Elevator;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Equipos del edificio con acceso a su legajo. */
class ElevatorsRelationManager extends RelationManager
{
    protected static string $relationship = 'elevators';

    protected static ?string $title = 'Ascensores';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('is_active', true))
            ->columns([
                TextColumn::make('label')->label('Equipo'),
                TextColumn::make('manufacturer')->label('Fabricante / modelo')->formatStateUsing(fn ($state, Elevator $r) => trim($r->manufacturer.' '.$r->model))->placeholder('—'),
                TextColumn::make('ficha')->label('Ficha')->badge()
                    ->state(fn (Elevator $r) => $r->missingEssentials() ? 'Incompleta' : 'Completa')
                    ->color(fn (string $state) => $state === 'Completa' ? 'success' : 'warning'),
            ])
            ->emptyStateHeading('Sin equipos')
            ->emptyStateDescription('Cargá la cantidad de ascensores y montacargas del edificio y se crean solos.')
            ->recordActions([
                Action::make('open')->label('Ver legajo')->icon('heroicon-o-document-text')
                    ->url(fn (Elevator $record) => ElevatorResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
