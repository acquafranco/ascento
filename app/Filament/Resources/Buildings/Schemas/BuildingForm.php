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
use App\Services\Geocoding\AddressAutocomplete;
use App\Support\CompanyContext;
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

            // Buscador: completa la dirección y deja el edificio ubicado en el
            // mapa al guardar (las coordenadas las pone el servidor, ver
            // AddressAutocomplete). Los campos de abajo siguen editables.
            Select::make('address_search')
                ->label('Buscar dirección')
                ->placeholder('Escribí calle y altura. Ej: Cabildo 2040')
                ->helperText('Elegí una opción y se completan los datos de abajo. Así el edificio aparece solo en el mapa.')
                ->searchable()
                ->searchDebounce(400)
                ->searchPrompt('Escribí al menos 4 letras de la calle y la altura')
                ->searchingMessage('Buscando…')
                ->noSearchResultsMessage('No encontramos esa dirección. Podés cargarla a mano abajo.')
                ->getSearchResultsUsing(fn (string $search): array => app(AddressAutocomplete::class)->search($search, CompanyContext::currentId()))
                ->getOptionLabelUsing(fn ($value): ?string => app(AddressAutocomplete::class)->pick($value)['label'] ?? null)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) {
                    $pick = app(AddressAutocomplete::class)->pick($state);

                    if (! $pick) {
                        return;
                    }

                    foreach ($pick['fields'] as $field => $value) {
                        $set($field, $value);
                    }
                })
                ->dehydrated(false)
                ->visible(fn (): bool => app(AddressAutocomplete::class)->isAvailable())
                ->columnSpanFull(),

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



            // Color del punto en el mapa (paleta fija; ver Building::MAP_COLORS).
            Select::make('map_color')
                ->label('Color en el mapa')
                ->placeholder('Naranja (por defecto)')
                ->helperText('Usalo para agrupar a simple vista: por zona, por técnico, por tipo de cliente…')
                ->options(collect(Building::MAP_COLORS)->mapWithKeys(fn (array $color, string $key) => [
                    $key => '<span style="display:inline-flex;align-items:center;gap:.5rem">'
                        .'<span style="width:.9rem;height:.9rem;border-radius:999px;background:'.$color[1].';display:inline-block"></span>'
                        .e($color[0]).'</span>',
                ])->all())
                ->allowHtml()
                ->native(false)
                ->in(array_keys(Building::MAP_COLORS))
                ->columnSpanFull(),

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
