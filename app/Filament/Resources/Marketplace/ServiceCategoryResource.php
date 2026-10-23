<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListServiceCategories;
use App\Models\Marketplace\ServiceCategory;
use App\Services\Activity\PlatformAudit;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Especialidades y servicios de «Profesionales y servicios»: se agregan y
 * editan sin tocar código. No se borran (los perfiles las referencian): se
 * desactivan y dejan de ofrecerse en formularios y filtros.
 */
class ServiceCategoryResource extends Resource
{
    protected static ?string $model = ServiceCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Especialidades profesionales';

    protected static ?string $modelLabel = 'especialidad';

    protected static ?string $pluralModelLabel = 'Especialidades profesionales';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'marketplace/especialidades';

    public static function canAccess(): bool
    {
        return MarketplaceModeration::allowed();
    }

    public static function canCreate(): bool
    {
        return false; // con la acción de la cabecera (auditada)
    }

    /** @return list<Component> */
    public static function fields(?ServiceCategory $record = null): array
    {
        return [
            TextInput::make('name')->label('Nombre')->required()->maxLength(80)
                ->rule(fn () => Rule::unique('service_categories', 'name')->ignore($record?->id)),
            TextInput::make('description')->label('Descripción (opcional)')->maxLength(255),
            TextInput::make('position')->label('Orden')->numeric()->integer()->minValue(0)->maxValue(10000)->default(100),
            Toggle::make('is_active')->label('Activa (se ofrece en perfiles y filtros)')->default(true),
        ];
    }

    public static function saveFrom(array $data, ?ServiceCategory $record = null): ServiceCategory
    {
        abort_unless(MarketplaceModeration::allowed(), 403);
        $record ??= new ServiceCategory(['slug' => self::uniqueSlug($data['name'])]);
        $record->fill(['name' => trim($data['name']), 'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'position' => (int) ($data['position'] ?? 100), 'is_active' => (bool) ($data['is_active'] ?? true)]);
        $changes = $record->getDirty();
        $record->save();
        PlatformAudit::record('marketplace.service_category.saved', $record, $changes);

        return $record;
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 80, '') ?: 'especialidad';
        $slug = $base;
        for ($i = 2; ServiceCategory::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('position')
            ->modifyQueryUsing(fn ($query) => $query->withCount('profiles'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->description(fn (ServiceCategory $r) => $r->description),
                TextColumn::make('position')->label('Orden')->sortable(),
                TextColumn::make('profiles_count')->label('Perfiles'),
                TextColumn::make('is_active')->label('Estado')->formatStateUsing(fn ($state) => $state ? 'Activa' : 'Inactiva')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('edit')->label('Editar')->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (ServiceCategory $record) => $record->only(['name', 'description', 'position', 'is_active']))
                    ->schema(fn (ServiceCategory $record) => self::fields($record))
                    ->action(function (ServiceCategory $record, array $data) {
                        self::saveFrom($data, $record);
                        Notification::make()->title('Especialidad guardada')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServiceCategories::route('/')];
    }
}
