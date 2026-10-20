<?php

namespace App\Filament\Resources\OnCall;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\OnCall\Pages\CreateOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\EditOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\ListOnCallSchedules;
use App\Filament\Resources\OnCall\Pages\ViewOnCallSchedule;
use App\Filament\Resources\OnCall\RelationManagers\ChangesRelationManager;
use App\Filament\Resources\OnCall\RelationManagers\ShiftsRelationManager;
use App\Filament\Resources\OnCall\RelationManagers\WorkOrdersRelationManager;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Services\OnCall\OnCallService;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Guardias técnicas: cobertura fuera del horario habitual. La configuración
 * (días, horario, cuántos técnicos a la vez, manual o rotativa) está acá; las
 * asignaciones efectivas y su historial, en sus turnos (OnCallService).
 */
class OnCallScheduleResource extends Resource
{
    use GuardedByPermission;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::OnCall;
    }

    use ScopedToCurrentCompany;

    protected static ?string $model = OnCallSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'Guardias';

    protected static ?string $modelLabel = 'guardia';

    protected static ?string $pluralModelLabel = 'Guardias';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'guardias';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Con turnos ya vigentes, el horario no se cambia: el historial depende de él.
        $locked = fn (?OnCallSchedule $record) => $record?->hasStartedShifts() ?? false;

        return $schema->components([
            Section::make('Guardia')->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(120)->placeholder('Ej: Guardia nocturna, Fines de semana'),
                Textarea::make('notes')->label('Indicaciones para los técnicos (opcional)')->rows(2)->maxLength(2000)
                    ->helperText('Por ejemplo: a qué teléfono llamar o qué edificios priorizar. Lo ven los técnicos asignados.'),
            ]),

            Section::make('Horario cubierto')->columnSpanFull()
                ->description(fn (?OnCallSchedule $record) => $locked($record)
                    ? 'Esta guardia ya tuvo turnos: su horario no se cambia (el historial depende de él). Para otro horario, finalizala y creá una nueva.'
                    : 'Cada franja empieza los días elegidos a la hora de inicio. Si la hora de fin es menor o igual a la de inicio, termina al día siguiente (por ejemplo, 18:00 a 08:00).')
                ->schema([
                    CheckboxList::make('days')->label('Días en que empieza la franja')->options(OnCallSchedule::DAYS)
                        ->columns(['default' => 2, 'sm' => 4, 'lg' => 7])->required()->bulkToggleable()
                        ->default([1, 2, 3, 4, 5])->disabled($locked),
                    Grid::make(['default' => 1, 'sm' => 3])->schema([
                        TimePicker::make('starts_time')->label('Desde')->seconds(false)->required()->default('18:00')->disabled($locked),
                        TimePicker::make('ends_time')->label('Hasta')->seconds(false)->required()->default('08:00')->disabled($locked)
                            ->helperText('Igual a «Desde» = 24 horas.'),
                        TextInput::make('technicians_needed')->label('Técnicos a la vez')->numeric()->minValue(1)->maxValue(10)->default(1)->required()
                            ->disabled($locked)->helperText('Cuántos técnicos cubren al mismo tiempo.'),
                    ]),
                ]),

            Section::make('Cómo se asigna')->columnSpanFull()->schema([
                Radio::make('mode')->label('Modalidad')->required()->live()->default(OnCallSchedule::MANUAL)
                    ->options([
                        OnCallSchedule::MANUAL => 'Manual: asignás técnicos por período (o de forma permanente) desde la guardia.',
                        OnCallSchedule::ROTATION => 'Rotativa: los técnicos se alternan en orden, en turnos de la duración que elijas.',
                    ]),
                Grid::make(['default' => 1, 'sm' => 2])->visible(fn (Get $get) => $get('mode') === OnCallSchedule::ROTATION)->schema([
                    TextInput::make('rotation_days')->label('Duración de cada turno (días)')->numeric()->minValue(1)->maxValue(31)->default(7)
                        ->required(fn (Get $get) => $get('mode') === OnCallSchedule::ROTATION),
                    DatePicker::make('rotation_starts_on')->label('La rotación empieza el')->native(false)->displayFormat('d/m/Y')->default(today())
                        ->required(fn (Get $get) => $get('mode') === OnCallSchedule::ROTATION)
                        ->helperText('Cada turno empieza a la hora «Desde». Solo se generan turnos futuros.'),
                ]),
                Repeater::make('members')->label('Técnicos de la rotación (en orden)')->relationship()
                    ->visible(fn (Get $get) => $get('mode') === OnCallSchedule::ROTATION)
                    ->orderColumn('position')->reorderable()->addActionLabel('Agregar técnico')->defaultItems(0)
                    ->minItems(fn (Get $get) => $get('mode') === OnCallSchedule::ROTATION ? 1 : 0)
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        Select::make('user_id')->label('Técnico')->required()->distinct()
                            ->options(fn () => OnCallService::technicians()->pluck('name', 'id')),
                        DatePicker::make('excluded_from')->label('No disponible desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('excluded_until')->label('Hasta')->native(false)->displayFormat('d/m/Y')->afterOrEqual('excluded_from'),
                    ])
                    ->helperText('Un técnico no disponible se saltea y toma el turno el siguiente. Cambiar el orden o los participantes recalcula solo los turnos futuros de la rotación.'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $service = fn () => app(OnCallService::class);

        // Arriba lo que se consulta siempre (ahora, después, lo que falta);
        // la configuración de la guardia, plegada.
        return $schema->components([
            Section::make('Cobertura')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('now')->label('De guardia ahora')
                    ->state(function (OnCallSchedule $r) use ($service) {
                        if (! $r->covers(now())) {
                            return 'Fuera del horario de guardia';
                        }
                        $names = $service()->shiftsAt($r, now())->map(fn (OnCallShift $s) => $s->user->name);

                        return $names->isEmpty() ? 'Sin cobertura' : $names->implode(', ');
                    })
                    ->color(fn ($state) => $state === 'Sin cobertura' ? 'danger' : null)->weight('bold')->size('lg'),
                TextEntry::make('next')->label('Después')
                    ->state(function (OnCallSchedule $r) use ($service) {
                        $window = $r->is_active ? $r->nextWindow($r->currentWindow(now())[1] ?? now()) : null;
                        if (! $window) {
                            return '—';
                        }
                        $names = $service()->shiftsAt($r, $window[0])->map(fn (OnCallShift $s) => $s->user->name);

                        return ($names->isEmpty() ? 'Sin técnico' : $names->implode(', ')).' · '.$window[0]->translatedFormat('D d/m H:i').' a '.$window[1]->translatedFormat('D d/m H:i');
                    })
                    ->color(fn ($state) => str_starts_with((string) $state, 'Sin técnico') ? 'danger' : null),
                TextEntry::make('gaps')->label('Sin cobertura en los próximos 14 días')->columnSpanFull()
                    ->state(function (OnCallSchedule $r) use ($service) {
                        $gaps = $service()->gaps($r, now(), now()->addDays(14));

                        return $gaps === [] ? 'Ninguno: todas las franjas tienen técnico.' : collect($gaps)->take(8)
                            ->map(fn ($g) => $g['start']->translatedFormat('D d/m H:i').' a '.$g['end']->translatedFormat('D d/m H:i').($g['assigned'] ? " ({$g['assigned']} de {$g['needed']})" : ''))
                            ->implode(' · ').(count($gaps) > 8 ? ' · y '.(count($gaps) - 8).' más' : '');
                    })
                    ->color(fn ($state) => str_starts_with((string) $state, 'Ninguno') ? 'success' : 'danger'),
                TextEntry::make('notes')->label('Indicaciones para los técnicos')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Configuración')->columnSpanFull()->columns(['default' => 1, 'md' => 4])->collapsible()->collapsed()
                ->description('Horario, modalidad y cuántos técnicos cubren a la vez. Para cambiarla, «Editar».')
                ->schema([
                    TextEntry::make('coverage')->label('Horario')->state(fn (OnCallSchedule $r) => $r->coverageLabel()),
                    TextEntry::make('mode')->label('Modalidad')->state(fn (OnCallSchedule $r) => $r->modeLabel()),
                    TextEntry::make('technicians_needed')->label('Técnicos a la vez'),
                    TextEntry::make('is_active')->label('Estado')->state(fn (OnCallSchedule $r) => $r->is_active ? 'Activa' : 'Finalizada')
                        ->badge()->color(fn ($state) => $state === 'Activa' ? 'success' : 'gray'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(OnCallService::class);

        return $table
            ->heading('Guardias configuradas')
            ->description('Abrí una guardia para ver sus turnos, el historial de cambios y los trabajos, o para cambiar su horario.')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Guardia')->searchable()->sortable()->weight('bold')
                    ->description(fn (OnCallSchedule $r) => $r->coverageLabel())->wrap(),
                TextColumn::make('mode')->label('Modalidad')->state(fn (OnCallSchedule $r) => $r->modeLabel())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('on_duty')->label('De guardia ahora')
                    ->state(function (OnCallSchedule $r) use ($service) {
                        if (! $r->covers(now())) {
                            return 'Fuera de horario';
                        }
                        $names = $service()->shiftsAt($r, now())->map(fn (OnCallShift $s) => $s->user->name);

                        return $names->isEmpty() ? 'Sin cobertura' : $names->implode(', ');
                    })
                    ->color(fn ($state) => match ($state) {
                        'Sin cobertura' => 'danger', 'Fuera de horario' => 'gray', default => null
                    })->wrap(),
                TextColumn::make('gaps_count')->label('Huecos (14 días)')->alignCenter()
                    ->state(fn (OnCallSchedule $r) => count($service()->gaps($r, now(), now()->addDays(14))))
                    ->color(fn ($state) => $state ? 'danger' : 'success'),
                IconColumn::make('is_active')->label('Activa')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Activa')->default(true),
            ])
            ->recordUrl(fn (OnCallSchedule $r) => static::getUrl('view', ['record' => $r]))
            ->emptyStateHeading('Todavía no hay guardias')
            ->emptyStateDescription('Creá una guardia (por ejemplo, «Guardia nocturna»), elegí días y horario, y asigná técnicos o armá una rotación.');
    }

    public static function getRelations(): array
    {
        return [
            ShiftsRelationManager::class,
            ChangesRelationManager::class,
            WorkOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnCallSchedules::route('/'),
            'create' => CreateOnCallSchedule::route('/create'),
            'view' => ViewOnCallSchedule::route('/{record}'),
            'edit' => EditOnCallSchedule::route('/{record}/edit'),
        ];
    }
}
