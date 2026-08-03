<?php

namespace App\Filament\Resources\Reports\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use App\Models\Building;
use Filament\Forms\Components\FileUpload;

class ReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('building_id')
                    ->label('Edificio')
                    ->relationship('building', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(),
                Select::make('elevator_number')
                    ->label('Ascensor')
                    ->options(function (callable $get): array {
                        $building = Building::find($get('building_id'));

                        if (! $building) {
                            return [];
                        }

                        $options = [];

                        for ($i = 1; $i <= ($building->elevator_count ?? 0); $i++) {
                            $options[(string) $i] = 'Ascensor ' . $i;
                        }

                        for ($i = 1; $i <= ($building->freight_elevator_count ?? 0); $i++) {
                            $options['M' . $i] = 'Montacargas ' . $i;
                        }

                        return $options;
                    })
                    ->live()
                    ->required(),
                FileUpload::make('photo')
                    ->label('Foto')
                    ->image()
                    ->directory(fn () => 'reports/' . auth()->user()->company_id),
                Textarea::make('description')
                    ->label('Descripción')
                    ->required()
                    ->columnSpanFull(),
                Select::make('priority')
                    ->label('Prioridad')
                    ->options(['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Critica'])
                    ->default('baja')
                    ->required(),
                Select::make('status')
                    ->label('Estado')
                    ->options(['pendiente' => 'Pendiente', 'en_revision' => 'En revision', 'resuelto' => 'Resuelto'])
                    ->default('pendiente')
                    ->required(),
            ]);
    }
}
