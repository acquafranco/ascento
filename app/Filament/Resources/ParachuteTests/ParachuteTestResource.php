<?php

namespace App\Filament\Resources\ParachuteTests;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\ParachuteTests\Pages\CreateParachuteTest;
use App\Filament\Resources\ParachuteTests\Pages\EditParachuteTest;
use App\Filament\Resources\ParachuteTests\Pages\ListParachuteTests;
use App\Filament\Resources\ParachuteTests\Pages\ViewParachuteTest;
use App\Models\Elevator;
use App\Models\ParachuteTest;
use App\Services\Parachute\ParachuteService;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de pruebas de paracaídas REALIZADAS (Etapa 7). Área
 * «Inspecciones y reportes»: ver el historial y los archivos; registrar o
 * corregir pide «gestionar» (y lo verifica ParachuteService). No se borran.
 * El estado de cada ascensor (próximas, vencidas) está en ParachuteTestsPage.
 */
class ParachuteTestResource extends Resource
{
    use GuardedByPermission;
    use ScopedToCurrentCompany;

    protected static ?string $model = ParachuteTest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'prueba de paracaídas';

    protected static ?string $pluralModelLabel = 'pruebas de paracaídas';

    protected static ?string $slug = 'pruebas-paracaidas';

    // Se entra desde «Pruebas de paracaídas» (estado por ascensor).
    protected static bool $shouldRegisterNavigation = false;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Inspections;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // historial: se corrige, no se borra
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<int, string> ascensores activos de la empresa (edificio · equipo) */
    public static function elevatorOptions(): array
    {
        return Elevator::where('company_id', CompanyContext::currentId())->where('is_active', true)
            ->with('building:id,name')->orderBy('building_id')->orderBy('label')->get()
            ->filter(fn (Elevator $e) => $e->building !== null)
            ->mapWithKeys(fn (Elevator $e) => [$e->id => $e->building->name.' · '.$e->label])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prueba realizada')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                Select::make('elevator_id')->label('Ascensor')->options(fn () => self::elevatorOptions())->searchable()->required()
                    ->disabledOn('edit')->columnSpanFull(),
                DatePicker::make('performed_on')->label('Fecha de la prueba')->required()->native(false)->displayFormat('d/m/Y')->maxDate(today()),
                Select::make('result')->label('Resultado')->options(ParachuteTest::RESULTS)->required()->native(false),
                Select::make('performed_by_id')->label('La realizó')->options(fn () => app(ParachuteService::class)->people((int) CompanyContext::currentId()))
                    ->searchable()->placeholder('Otra persona (escribir el nombre)')->live(),
                TextInput::make('performed_by_name')->label('Nombre de quien la realizó')->maxLength(120)
                    ->visible(fn (Get $get) => blank($get('performed_by_id'))),
                TextInput::make('inspector_name')->label('Inspector o profesional responsable')->maxLength(120),
                TextInput::make('inspector_registration')->label('Matrícula o registro (opcional)')->maxLength(60),
                Textarea::make('observations')->label('Observaciones')->rows(3)->maxLength(5000)->columnSpanFull(),
                FileUpload::make('files')->label('Documentación o fotos (opcional)')->multiple()->storeFiles(false)->maxFiles(10)
                    ->acceptedFileTypes(array_keys(ParachuteService::MIMES))->maxSize(ParachuteService::MAX_KB)
                    ->helperText('PDF o imágenes. Quedan privadas: nunca se publican en el QR.')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('detail')->hiddenLabel()->columnSpanFull()->view('filament.parachute.test-detail'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['elevator:id,label', 'building:id,name', 'performedBy:id,name'])->withCount('files'))
            ->defaultSort('performed_on', 'desc')
            ->columns([
                TextColumn::make('performed_on')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('elevator.label')->label('Ascensor')->description(fn (ParachuteTest $record) => $record->building?->name),
                TextColumn::make('result')->label('Resultado')->badge()
                    ->formatStateUsing(fn ($state) => ParachuteTest::RESULTS[$state] ?? $state)
                    ->color(fn ($state) => $state === ParachuteTest::SATISFACTORY ? 'success' : 'danger'),
                TextColumn::make('performer')->label('La realizó')->state(fn (ParachuteTest $record) => $record->performerName())->placeholder('—'),
                TextColumn::make('inspector_name')->label('Inspector')->placeholder('—')->toggleable(),
                TextColumn::make('files_count')->label('Archivos')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('elevator_id')->label('Ascensor')->options(fn () => self::elevatorOptions())->searchable(),
                SelectFilter::make('result')->label('Resultado')->options(ParachuteTest::RESULTS),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParachuteTests::route('/'),
            'create' => CreateParachuteTest::route('/create'),
            'view' => ViewParachuteTest::route('/{record}'),
            'edit' => EditParachuteTest::route('/{record}/edit'),
        ];
    }
}
