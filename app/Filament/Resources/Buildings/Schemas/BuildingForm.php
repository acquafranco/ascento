<?php

namespace App\Filament\Resources\Buildings\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class BuildingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Select::make('client_id')
                ->relationship('client', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->label('Cliente'),

            TextInput::make('name')
                ->required()
                ->label('Nombre de calle'),

            TextInput::make('address')
                ->required()
                ->label('Dirección (Numero)'),

            Grid::make(2)
                ->schema([
                    TextInput::make('contact_person')
                        ->label('Contacto'),

                    TextInput::make('phone')
                        ->tel()
                        ->label('Teléfono'),
                ]),

            Grid::make(2)
                ->schema([

                    TextInput::make('elevator_count')
                        ->integer()
                        ->default(0)
                        ->minValue(0)
                        ->required()
                        ->live()
                        ->label('Ascensores')
                        ->afterStateUpdated(fn (Get $get, Set $set) =>
                            self::syncElevators($get, $set)
                        ),

                    TextInput::make('freight_elevator_count')
                        ->integer()
                        ->default(0)
                        ->minValue(0)
                        ->required()
                        ->live()
                        ->label('Montacargas')
                        ->afterStateUpdated(fn (Get $get, Set $set) =>
                            self::syncElevators($get, $set)
                        ),

                    TextInput::make('traction_elevator_count')
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->live()
                        ->label('Ascensores de tracción')

                        ->formatStateUsing(fn ($state) => $state ?: null)

                        ->dehydrateStateUsing(fn ($state) =>
                            $state === null ? 0 : $state
                        )

                        ->afterStateUpdated(fn (Get $get, Set $set) =>
                            self::syncElevators($get, $set)
                        ),

                    TextInput::make('hydraulic_elevator_count')
                        ->disabled()
                        ->dehydrated()
                        ->default(0)
                        ->label('Ascensores hidráulicos'),
                ]),

            Textarea::make('notes')
                ->columnSpanFull()
                ->label('Observaciones'),

            Toggle::make('is_active')
                ->default(true)
                ->label('Activo'),
        ]);
    }

    /**
     * 🔥 LÓGICA CENTRALIZADA
     */
    private static function syncElevators(Get $get, Set $set): void
    {
        $ascensores = (int) $get('elevator_count');
        $montacargas = (int) $get('freight_elevator_count');

        $total = $ascensores + $montacargas;

        $traction = min(
            (int) $get('traction_elevator_count'),
            $total
        );

        $hydraulic = max(0, $total - $traction);

        $set('traction_elevator_count', $traction);
        $set('hydraulic_elevator_count', $hydraulic);
    }
}
