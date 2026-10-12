<?php

namespace App\Filament\Resources\VisitPhotos;

use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\VisitPhotos\Pages\ListVisitPhotos;
use App\Models\VisitPhoto;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fotos de antes y después de TODAS las visitas y órdenes de la empresa,
 * cargue quien las cargue (solo lectura): el admin las lista, abre y
 * descarga. Cada imagen se sirve por la ruta privada, que vuelve a
 * verificar empresa y usuario. Las fotos del flujo anterior que nunca se
 * vincularon a una visita no aparecen (se limpian solas).
 */
class VisitPhotoResource extends Resource
{
    use ScopedToCurrentCompany;

    protected static ?string $model = VisitPhoto::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Fotos de trabajos';

    protected static ?string $modelLabel = 'Foto';

    protected static ?string $pluralModelLabel = 'Fotos de trabajos';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 39;

    protected static ?string $slug = 'fotos-de-trabajos';

    public const TYPES = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'work_order' => 'Orden de trabajo'];

    public static function getEloquentQuery(): Builder
    {
        $query = static::scopedQuery();

        return $query->where(fn (Builder $q) => $q->whereNotNull('building_visit_id')->orWhereNotNull('work_order_id'));
    }

    /** Filtro de empresa del trait (ScopedToCurrentCompany). */
    private static function scopedQuery(): Builder
    {
        $companyId = CompanyContext::currentId();
        $query = parent::getEloquentQuery();

        return $companyId ? $query->where('visit_photos.company_id', $companyId) : $query->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['building:id,name', 'uploader:id,name', 'elevator:id,label']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('thumb')->label('')->getStateUsing(fn (VisitPhoto $record) => $record->thumbUrl())->square()->imageSize(56),
                TextColumn::make('building.name')->label('Edificio')->searchable()->weight('bold'),
                TextColumn::make('assignment_type')->label('Trabajo')->badge()->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                TextColumn::make('section_name')->label('Sección')
                    ->description(fn (VisitPhoto $record) => VisitPhoto::STAGES[$record->stage] ?? $record->stage),
                TextColumn::make('elevator.label')->label('Equipo')->placeholder('General')->toggleable(),
                TextColumn::make('notes')->label('Observación')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('uploader.name')->label('Cargó')->placeholder('—'),
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('assignment_type')->label('Trabajo')->options(self::TYPES),
                SelectFilter::make('stage')->label('Estado')->options(VisitPhoto::STAGES),
                SelectFilter::make('building_id')->label('Edificio')->relationship('building', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('open')->label('Ver')->icon('heroicon-o-eye')
                    ->url(fn (VisitPhoto $record) => $record->url(), shouldOpenInNewTab: true),
                Action::make('download')->label('Descargar')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (VisitPhoto $record) => $record->downloadUrl()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisitPhotos::route('/'),
        ];
    }
}
