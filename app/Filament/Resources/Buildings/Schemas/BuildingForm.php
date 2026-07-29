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
use Filament\Forms\Components\Hidden;

class BuildingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([


            Hidden::make('company_id')
                ->default(fn () => auth()->user()->company_id),


            Select::make('client_id')
                ->relationship(
                    name: 'client',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn ($query) =>
                        $query->where(
                            'company_id',
                            auth()->user()->company_id
                        )
                )
                ->searchable()
                ->preload()
                ->required()
                ->columnSpanFull()
                ->label('Cliente'),



            /*
            |--------------------------------------------------------------------------
            | DIRECCIÓN
            |--------------------------------------------------------------------------
            */

            Grid::make(4)
            ->schema([

                TextInput::make('name')
                    ->label('Calle')
                    ->required()
                    ->columnSpan(2),


                TextInput::make('address')
                    ->label('Número')
                    ->required()
                    ->integer()
                    ->inputMode('numeric')
                    ->columnSpan(1),


                TextInput::make('locality')
                    ->label('Localidad')
                    ->placeholder('Ej: Benavídez')
                    ->columnSpan(1),

            ]),


            Grid::make(3)
            ->schema([


                TextInput::make('municipality')
                    ->label('Municipio / Partido')
                    ->placeholder('Ej: Tigre'),


                TextInput::make('province')
                    ->label('Provincia')
                    ->placeholder('Ej: Buenos Aires'),


                TextInput::make('neighborhood')
                    ->label('Barrio')
                    ->placeholder('Ej: Nordelta'),

            ]),



            /*
            |--------------------------------------------------------------------------
            | CONTACTO
            |--------------------------------------------------------------------------
            */

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



            /*
            |--------------------------------------------------------------------------
            | ASCENSORES
            |--------------------------------------------------------------------------
            */

            Grid::make(4)
            ->schema([


                TextInput::make('elevator_count')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('-')
                    ->inputMode('numeric')
                    ->extraInputAttributes([
                        'class' => 'text-center'
                    ])
                    ->live()
                    ->label('Asc.')
                    ->formatStateUsing(
                        fn ($state) =>
                        blank($state) || $state == 0
                            ? null
                            : $state
                    )
                    ->dehydrateStateUsing(
                        fn ($state) =>
                        blank($state)
                            ? 0
                            : $state
                    )
                    ->afterStateUpdated(
                        fn (Get $get, Set $set) =>
                        self::syncElevators($get, $set)
                    ),



                TextInput::make('freight_elevator_count')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('-')
                    ->inputMode('numeric')
                    ->extraInputAttributes([
                        'class' => 'text-center'
                    ])
                    ->live()
                    ->label('Mont.')
                    ->formatStateUsing(
                        fn ($state) =>
                        blank($state) || $state == 0
                            ? null
                            : $state
                    )
                    ->dehydrateStateUsing(
                        fn ($state) =>
                        blank($state)
                            ? 0
                            : $state
                    )
                    ->afterStateUpdated(
                        fn (Get $get, Set $set) =>
                        self::syncElevators($get, $set)
                    ),



                TextInput::make('traction_elevator_count')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('-')
                    ->inputMode('numeric')
                    ->extraInputAttributes([
                        'class' => 'text-center'
                    ])
                    ->live()
                    ->label('Tracción')
                    ->formatStateUsing(
                        fn ($state) =>
                        blank($state) || $state == 0
                            ? null
                            : $state
                    )
                    ->dehydrateStateUsing(
                        fn ($state) =>
                        blank($state)
                            ? 0
                            : $state
                    )
                    ->afterStateUpdated(
                        fn (Get $get, Set $set) =>
                        self::syncElevators($get, $set)
                    ),



                TextInput::make('hydraulic_elevator_count')
                    ->disabled()
                    ->dehydrated()
                    ->placeholder('-')
                    ->extraInputAttributes([
                        'class' => 'text-center'
                    ])
                    ->label('Hidráulicos')
                    ->formatStateUsing(
                        fn ($state) =>
                        blank($state) || $state == 0
                            ? null
                            : $state
                    )
                    ->dehydrateStateUsing(
                        fn ($state) =>
                        blank($state)
                            ? 0
                            : $state
                    ),

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
     * Sincroniza tipos de ascensores
     */
    private static function syncElevators(
        Get $get,
        Set $set
    ): void
    {

        $ascensores = (int) $get('elevator_count');

        $montacargas = (int) $get('freight_elevator_count');


        $total = $ascensores + $montacargas;


        $traction = min(
            (int) $get('traction_elevator_count'),
            $total
        );


        $hydraulic = max(
            0,
            $total - $traction
        );


        $set(
            'traction_elevator_count',
            $traction
        );


        $set(
            'hydraulic_elevator_count',
            $hydraulic
        );

    }
}
