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
            ->columnSpanFull()
            ->label('Cliente'),

            Grid::make(2)
    ->schema([

        TextInput::make('name')
            ->required()
            ->label('Calle')
            ->rule('regex:/^[\pL\s]+$/u')
            ->validationMessages([
                'regex' => 'Solo letras.',
            ]),

        TextInput::make('address')
            ->required()
            ->integer()
            ->inputMode('numeric')
            ->label('Número'),

    ]),

       Grid::make(2)
    ->schema([

        TextInput::make('contact_person')
            ->label('Contacto')
            ->rule('regex:/^[\pL\s]+$/u')
            ->validationMessages([
                'regex' => 'Solo letras.',
            ]),

        TextInput::make('phone')
            ->label('Teléfono')
            ->tel()
            ->inputMode('tel')
            ->rule('regex:/^[0-9+\-\s()]+$/')
            ->validationMessages([
                'regex' => 'Solo números.',
            ]),

    ]),

           Grid::make(4)
    ->schema([

        TextInput::make('elevator_count')
            ->numeric()
            ->minValue(0)
            ->placeholder('-')
            ->inputMode('numeric')
            ->extraInputAttributes(['class' => 'text-center'])
            ->live()
            ->label('Asc.')
            ->formatStateUsing(fn ($state) => blank($state) || $state == 0 ? null : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? 0 : $state)
            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncElevators($get, $set)),

        TextInput::make('freight_elevator_count')
            ->numeric()
            ->minValue(0)
            ->placeholder('-')
            ->inputMode('numeric')
            ->extraInputAttributes(['class' => 'text-center'])
            ->live()
            ->label('Mont.')
            ->formatStateUsing(fn ($state) => blank($state) || $state == 0 ? null : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? 0 : $state)
            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncElevators($get, $set)),

        TextInput::make('traction_elevator_count')
            ->numeric()
            ->minValue(0)
            ->placeholder('-')
            ->inputMode('numeric')
            ->extraInputAttributes(['class' => 'text-center'])
            ->live()
            ->label('Tracción')
            ->formatStateUsing(fn ($state) => blank($state) || $state == 0 ? null : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? 0 : $state)
            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncElevators($get, $set)),

        TextInput::make('hydraulic_elevator_count')
            ->disabled()
            ->dehydrated()
            ->placeholder('-')
            ->extraInputAttributes(['class' => 'text-center'])
            ->label('Hidráulicos')
            ->formatStateUsing(fn ($state) => blank($state) || $state == 0 ? null : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? 0 : $state),

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
