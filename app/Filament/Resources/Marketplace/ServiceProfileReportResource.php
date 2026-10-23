<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListServiceProfileReports;
use App\Models\Marketplace\ServiceProfileReport;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Reportes de perfiles profesionales hechos por empresas (solo SuperAdmin). */
class ServiceProfileReportResource extends Resource
{
    protected static ?string $model = ServiceProfileReport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'Reportes de profesionales';

    protected static ?string $modelLabel = 'reporte';

    protected static ?string $pluralModelLabel = 'Reportes de profesionales';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'marketplace/reportes-profesionales';

    public static function canAccess(): bool
    {
        return MarketplaceModeration::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = static::canAccess() ? ServiceProfileReport::where('status', 'open')->count() : 0;

        return $open ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['profile:id,public_name,moderation', 'company:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('profile.public_name')->label('Perfil')->url(fn (ServiceProfileReport $r) => $r->profile ? ServiceProfileResource::getUrl('view', ['record' => $r->profile]) : null),
                TextColumn::make('reason')->label('Motivo')->formatStateUsing(fn ($state, ServiceProfileReport $r) => $r->reasonLabel())->description(fn (ServiceProfileReport $r) => Str::limit((string) $r->details, 120)),
                TextColumn::make('company.name')->label('Reportó')->placeholder('Empresa dada de baja'),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => ServiceProfileReport::STATUSES[$state] ?? $state)->badge()
                    ->color(fn ($state) => $state === 'open' ? 'warning' : 'gray')->description(fn (ServiceProfileReport $r) => $r->resolution),
            ])
            ->filters([SelectFilter::make('status')->label('Estado')->options(ServiceProfileReport::STATUSES)->default('open')])
            ->recordActions(ServiceModeration::reportActions());
    }

    public static function getPages(): array
    {
        return ['index' => ListServiceProfileReports::route('/')];
    }
}
