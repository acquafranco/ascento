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
use App\Models\Elevator;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;

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
                ->url(fn () => \App\Support\Plans\PlanGuard::currentAllows(\App\Enums\PlanFeature::Map) ? BuildingsMap::getUrl() : null)
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
            | ASCENSORES Y MONTACARGAS
            |--------------------------------------------------------------------------
            | Se registra CADA equipo con su tipo y su tracción (la tracción es
            | de cada equipo, no del edificio). Las cantidades del edificio se
            | calculan desde los equipos registrados. Quitar un equipo no lo
            | borra: queda inactivo con su historial (se pide confirmación).
            */

            Section::make('Ascensores y montacargas')
                ->description('Indicá cuántos hay y elegí la tracción de cada uno. Sin al menos un equipo no se programan mantenimientos ni inspecciones.')
                ->icon('heroicon-o-arrows-up-down')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('equipment_elevators')
                            ->label('Ascensores')
                            ->numeric()->integer()->minValue(0)->maxValue(50)
                            ->inputMode('numeric')
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->afterStateHydrated(fn (Set $set, Get $get) => $set('equipment_elevators', self::countKind($get('equipment'), 'elevator')))
                            ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::resize($get, $set, 'elevator', (int) $state)),
                        TextInput::make('equipment_freight')
                            ->label('Montacargas')
                            ->numeric()->integer()->minValue(0)->maxValue(50)
                            ->inputMode('numeric')
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->afterStateHydrated(fn (Set $set, Get $get) => $set('equipment_freight', self::countKind($get('equipment'), 'freight')))
                            ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::resize($get, $set, 'freight', (int) $state)),
                    ]),

                    Repeater::make('equipment')
                        ->label('Equipos')
                        ->hiddenLabel()
                        ->schema([
                            Hidden::make('id'),
                            Hidden::make('label'),
                            Select::make('kind')
                                ->label('Tipo de equipo')
                                ->options(Elevator::KINDS)
                                ->default('elevator')
                                ->required()
                                ->native(false)
                                // El tipo de un equipo ya registrado no cambia: su nombre es historial.
                                ->disabled(fn (Get $get) => filled($get('id')))
                                ->dehydrated()
                                ->live(),
                            Select::make('machine_type')
                                ->label('Tracción')
                                ->options(Elevator::MACHINE_TYPES)
                                ->placeholder('Sin especificar')
                                ->native(false)
                                // Obligatoria en equipos nuevos; los existentes pueden seguir sin dato.
                                ->required(fn (Get $get) => blank($get('id')))
                                ->validationMessages(['required' => 'Elegí la tracción del equipo.'])
                                ->in(array_keys(Elevator::MACHINE_TYPES)),
                        ])
                        ->columns(2)
                        ->itemLabel(fn (array $state) => filled($state['label'] ?? null)
                            ? $state['label'].(blank($state['machine_type'] ?? null) ? ' · tracción sin especificar' : '')
                            : 'Nuevo '.mb_strtolower(Elevator::KINDS[$state['kind'] ?? 'elevator'] ?? 'equipo'))
                        ->addActionLabel('Agregar equipo')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->minItems(fn (?Building $record) => $record === null || $record->hasEquipment() ? 1 : 0)
                        ->validationMessages(['min' => 'Registrá al menos un ascensor o montacargas.'])
                        ->deleteAction(fn (Action $action) => $action
                            ->requiresConfirmation()
                            ->modalHeading('¿Quitar este equipo?')
                            ->modalDescription('Si ya estaba registrado, no se borra: queda inactivo con su historial (reportes, órdenes, documentos). Se aplica al guardar el edificio.')
                            ->modalSubmitActionLabel('Quitar'))
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $set('equipment_elevators', self::countKind($get('equipment'), 'elevator'));
                            $set('equipment_freight', self::countKind($get('equipment'), 'freight'));
                        }),
                ]),

            // Zona / sector (texto libre del admin): filtro del mapa.
            TextInput::make('map_zone')
                ->label('Zona o sector (opcional)')
                ->placeholder('Ej.: Zona Norte')
                ->helperText('Agrupá tus edificios por zona o recorrido para filtrarlos en el mapa.')
                ->maxLength(60)
                ->datalist(fn () => Building::query()->whereNotNull('map_zone')->distinct()->orderBy('map_zone')->limit(50)->pluck('map_zone')->all())
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? trim($state) : null)
                ->columnSpanFull(),

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



    private static function countKind(?array $items, string $kind): int
    {
        return collect($items ?? [])->where('kind', $kind)->count();
    }

    /**
     * Ajusta la lista a la cantidad indicada de un tipo: agrega equipos nuevos
     * o quita los NUEVOS (todavía sin guardar) del final. Nunca quita equipos
     * ya registrados por cambiar el número: para eso está el botón de cada
     * equipo, que pide confirmación.
     */
    private static function resize(Get $get, Set $set, string $kind, int $wanted): void
    {
        $items = $get('equipment') ?? [];
        $wanted = max(0, min(50, $wanted));
        $current = collect($items)->where('kind', $kind);

        if ($current->count() < $wanted) {
            for ($i = $current->count(); $i < $wanted; $i++) {
                $items[(string) \Illuminate\Support\Str::uuid()] = ['id' => null, 'label' => null, 'kind' => $kind, 'machine_type' => null];
            }
        } else {
            $toRemove = $current->count() - $wanted;
            foreach (array_reverse($items, true) as $key => $item) {
                if ($toRemove > 0 && ($item['kind'] ?? null) === $kind && blank($item['id'] ?? null)) {
                    unset($items[$key]);
                    $toRemove--;
                }
            }

            if ($toRemove > 0) {
                Notification::make()
                    ->title('Para dar de baja un equipo registrado, quitalo de la lista')
                    ->body('Así se confirma cuál: queda inactivo con su historial, no se borra.')
                    ->warning()->send();
            }
        }

        $set('equipment', $items);
        $set($kind === 'elevator' ? 'equipment_elevators' : 'equipment_freight', collect($items)->where('kind', $kind)->count());
    }
}
