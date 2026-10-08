<?php

namespace App\Filament\Resources\Elevators;

use App\Enums\PlanFeature;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\Elevators\Pages\EditElevator;
use App\Filament\Resources\Elevators\Pages\ListElevators;
use App\Filament\Resources\Elevators\Pages\ViewElevator;
use App\Livewire\ElevatorHistoryPanel;
use App\Livewire\HelpTip;
use App\Models\Elevator;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Legajo técnico de cada ascensor: ficha técnica (datos del equipo) e
 * historial operativo (lo que pasó, armado con los registros existentes).
 * Los legajos se crean solos con los equipos del edificio: acá no se crean
 * ni se borran.
 */
class ElevatorResource extends Resource
{
    use ScopedToCurrentCompany;

    protected static ?string $model = Elevator::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static ?string $navigationLabel = 'Ascensores';

    protected static ?string $modelLabel = 'Ascensor';

    protected static ?string $pluralModelLabel = 'Ascensores';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'label';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Equipo')->columns(4)->columnSpanFull()->schema([
                TextInput::make('manufacturer')->label('Fabricante')->maxLength(255),
                TextInput::make('model')->label('Modelo')->maxLength(255),
                TextInput::make('serial_number')->label('Número de serie')->maxLength(255),
                TextInput::make('year')->label('Año')->numeric()->minValue(1900)->maxValue((int) date('Y') + 1),
                TextInput::make('capacity_kg')->label('Capacidad (kg)')->numeric()->minValue(1)->maxValue(100000),
                TextInput::make('capacity_people')->label('Capacidad (personas)')->numeric()->minValue(1)->maxValue(500),
                TextInput::make('speed_ms')->label('Velocidad (m/s)')->numeric()->minValue(0)->maxValue(20)->step(0.01),
                TextInput::make('stops')->label('Paradas')->numeric()->minValue(2)->maxValue(300),
            ]),
            Section::make('Instalación y componentes')->columns(3)->columnSpanFull()->schema([
                DatePicker::make('installed_at')->label('Instalación')->native(false)->displayFormat('d/m/Y'),
                TextInput::make('installer')->label('Instalador')->maxLength(255),
                Select::make('machine_type')->label('Tipo de máquina')->options(Elevator::MACHINE_TYPES)->native(false),
                TextInput::make('controller')->label('Controlador / maniobra')->maxLength(255),
                TextInput::make('motor')->label('Motor')->maxLength(255),
                TextInput::make('doors')->label('Puertas')->maxLength(255)->placeholder('Automáticas de 2 hojas'),
                TextInput::make('door_operator')->label('Operador de puertas')->maxLength(255),
                Textarea::make('components')->label('Otros componentes relevantes')->rows(2)->maxLength(5000)->columnSpan(2),
                Textarea::make('notes')->label('Notas')->rows(2)->maxLength(5000)->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $value = fn (string $field, string $label, ?string $suffix = null) => TextEntry::make($field)->label($label)->placeholder('—')
            ->formatStateUsing(fn ($state) => $suffix && filled($state) ? $state.' '.$suffix : $state);

        return $schema->components([
            Section::make('1. Ficha técnica')
                ->columnSpanFull()
                ->columns(4)
                ->description(fn (Elevator $record) => $record->missingEssentials()
                    ? 'Faltan datos básicos: '.collect($record->missingEssentials())->map(fn ($f) => static::fieldLabel($f))->implode(', ').'.'
                    : 'Ficha completa.')
                ->schema([
                    TextEntry::make('building.name')->label('Edificio'),
                    TextEntry::make('building.client.name')->label('Cliente')->placeholder('—'),
                    TextEntry::make('label')->label('Equipo'),
                    TextEntry::make('is_active')->label('Estado')->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Ya no figura en el edificio')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                    $value('manufacturer', 'Fabricante'),
                    $value('model', 'Modelo'),
                    $value('serial_number', 'Número de serie'),
                    $value('year', 'Año'),
                    $value('capacity_kg', 'Capacidad', 'kg'),
                    $value('capacity_people', 'Personas'),
                    $value('speed_ms', 'Velocidad', 'm/s'),
                    $value('stops', 'Paradas'),
                    TextEntry::make('installed_at')->label('Instalación')->date('d/m/Y')->placeholder('—'),
                    $value('installer', 'Instalador'),
                    TextEntry::make('machine_type')->label('Máquina')->formatStateUsing(fn ($state) => Elevator::MACHINE_TYPES[$state] ?? $state)->placeholder('—'),
                    $value('controller', 'Controlador'),
                    $value('motor', 'Motor'),
                    $value('doors', 'Puertas'),
                    $value('door_operator', 'Operador'),
                    TextEntry::make('components')->label('Otros componentes')->placeholder('—')->columnSpan(2),
                    TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('2. Historial operativo')
                ->columnSpanFull()
                ->schema([
                    Livewire::make(HelpTip::class, ['key' => 'elevator_history'])->key('help-elevator-history'),
                    Livewire::make(ElevatorHistoryPanel::class, fn (Elevator $record) => ['elevator' => $record])->key('elevator-history'),
                ]),
        ]);
    }

    public static function fieldLabel(string $field): string
    {
        return [
            'manufacturer' => 'fabricante', 'model' => 'modelo', 'serial_number' => 'número de serie',
            'year' => 'año', 'capacity_kg' => 'capacidad', 'stops' => 'paradas',
        ][$field] ?? $field;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['building:id,name,client_id', 'building.client:id,name'])->withCount('documents'))
            ->defaultSort('building_id')
            ->columns([
                TextColumn::make('building.name')->label('Edificio')->searchable()->sortable(),
                TextColumn::make('building.client.name')->label('Cliente')->toggleable(),
                TextColumn::make('label')->label('Equipo'),
                TextColumn::make('manufacturer')->label('Fabricante / modelo')
                    ->formatStateUsing(fn ($state, Elevator $record) => trim($record->manufacturer.' '.$record->model))->placeholder('—')->searchable(['manufacturer', 'model']),
                TextColumn::make('serial_number')->label('Serie')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('ficha')->label('Ficha')->badge()
                    ->state(fn (Elevator $record) => $record->missingEssentials() ? 'Incompleta' : 'Completa')
                    ->color(fn (string $state) => $state === 'Completa' ? 'success' : 'warning'),
                TextColumn::make('documents_count')->label('Documentos')->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('building_id')->label('Edificio')->relationship('building', 'name')->searchable()->preload(),
                Filter::make('incomplete')->label('Ficha incompleta')
                    ->query(fn (Builder $query) => $query->where(fn ($q) => collect(Elevator::ESSENTIAL_FIELDS)->each(fn ($f) => $q->orWhereNull($f)))),
                TernaryFilter::make('is_active')->label('Activos')->default(true),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()->label('Editar ficha')]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListElevators::route('/'),
            'view' => ViewElevator::route('/{record}'),
            'edit' => EditElevator::route('/{record}/edit'),
        ];
    }

    /** El legajo es del plan (en los tres), pero se consulta igual que el resto. */
    public static function allowedForCurrentCompany(PlanFeature $feature): bool
    {
        $company = PlanUpsell::currentCompany();

        return $company !== null && PlanGuard::for($company)->allows($feature);
    }
}
