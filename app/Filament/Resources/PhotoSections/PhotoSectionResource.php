<?php

namespace App\Filament\Resources\PhotoSections;

use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\PhotoSections\Pages\ManagePhotoSections;
use App\Models\PhotoSection;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Secciones de fotos de mantenimientos e inspecciones (Pozo, Techo, Sala de
 * máquinas…). Cada empresa administra las suyas: agregar, renombrar,
 * ordenar y desactivar. No se borran, así las fotos históricas conservan su
 * sección (además guardan el nombre que tenía al cargarse).
 */
class PhotoSectionResource extends Resource
{
    use ScopedToCurrentCompany;

    protected static ?string $model = PhotoSection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationLabel = 'Secciones de fotos';

    protected static ?string $modelLabel = 'Sección de fotos';

    protected static ?string $pluralModelLabel = 'Secciones de fotos';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'secciones-de-fotos';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(60)
                ->placeholder('Ej: Cabina, Puertas, Tablero de control')
                ->rule(fn (?PhotoSection $record) => Rule::unique('photo_sections', 'name')
                    ->where('company_id', CompanyContext::currentId())
                    ->ignore($record?->id))
                ->validationMessages(['unique' => 'Ya existe una sección con ese nombre (si está desactivada, activala).']),
            TextInput::make('position')
                ->label('Orden')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(999)
                ->default(fn () => (int) PhotoSection::where('company_id', CompanyContext::currentId())->max('position') + 1)
                ->helperText('Las secciones se muestran al técnico de menor a mayor.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')->label('Sección')->searchable()->weight('bold'),
                TextColumn::make('position')->label('Orden')->sortable(),
                IconColumn::make('is_active')->label('Activa')->boolean(),
                TextColumn::make('photos_count')->label('Fotos cargadas')->counts('photos'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Estado')->trueLabel('Activas')->falseLabel('Desactivadas'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggle')
                    ->label(fn (PhotoSection $record) => $record->is_active ? 'Desactivar' : 'Activar')
                    ->icon(fn (PhotoSection $record) => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (PhotoSection $record) => $record->is_active ? 'gray' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PhotoSection $record) => $record->is_active
                        ? 'El técnico ya no la va a ver al hacer un remito. Las fotos ya cargadas se conservan.'
                        : 'Vuelve a aparecer para el técnico al hacer un remito.')
                    ->action(fn (PhotoSection $record) => $record->forceFill(['is_active' => ! $record->is_active])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePhotoSections::route('/'),
        ];
    }
}
