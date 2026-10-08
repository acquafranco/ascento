<?php

namespace App\Filament\Resources\Buildings\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Pages\BuildingsMap;
use App\Models\Building;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class BuildingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([


            Select::make('client_id')
                ->relationship(
                    name: 'client',
                    titleAttribute: 'name',
                    modifyQueryUsing: function ($query) {
                        $user = auth()->user();

                        $companyId = $user->isSuperAdmin()
                            ? session('selected_company_id')
                            : $user->company_id;

                        return $query->withoutTrashed()->where('company_id', $companyId);
                    }
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


            // Estado de la ubicación en el mapa (solo al editar).
            TextEntry::make('geocoding_status')
                ->label('Ubicación en el mapa')
                ->visibleOn('edit')
                ->badge()
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    Building::GEO_GEOCODED => 'Ubicado',
                    Building::GEO_MANUAL => 'Marcado a mano',
                    Building::GEO_NEEDS_REVIEW => 'No se pudo ubicar: revisá la dirección',
                    Building::GEO_ERROR => 'Se reintenta en unos minutos',
                    default => 'Pendiente (se ubica al guardar)',
                })
                ->color(fn (?string $state) => match ($state) {
                    Building::GEO_GEOCODED, Building::GEO_MANUAL => 'success',
                    Building::GEO_NEEDS_REVIEW => 'warning',
                    default => 'gray',
                })
                ->url(fn () => BuildingsMap::getUrl())
                ->columnSpanFull(),



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
