<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListServiceProfiles;
use App\Filament\Resources\Marketplace\Pages\ViewServiceProfile;
use App\Models\Marketplace\ServiceProfile;
use App\Support\Countries;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Perfiles profesionales («Profesionales y servicios»): revisión y moderación (solo SuperAdmin). */
class ServiceProfileResource extends Resource
{
    protected static ?string $model = ServiceProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Perfiles profesionales';

    protected static ?string $modelLabel = 'perfil profesional';

    protected static ?string $pluralModelLabel = 'Perfiles profesionales';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'marketplace/profesionales';

    protected static ?string $recordTitleAttribute = 'public_name';

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
        $pending = static::canAccess() ? ServiceProfile::where('status', ServiceProfile::PUBLISHED)->whereNull('approved_at')->whereNull('moderation')->count() : 0;

        return $pending ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Perfiles esperando revisión';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['supplier:id,name', 'company:id,name'])->withCount(['reports as open_reports' => fn ($q) => $q->where('status', 'open'), 'contactRequests']))
            ->columns([
                TextColumn::make('public_name')->label('Perfil')->searchable()->description(fn (ServiceProfile $r) => $r->kindLabel()),
                TextColumn::make('owner')->label('Cuenta')->state(fn (ServiceProfile $r) => ($r->supplier?->name ?? $r->company?->name ?? 'Dada de baja'))->description(fn (ServiceProfile $r) => $r->ownerLabel()),
                TextColumn::make('state')->label('Estado')->state(fn (ServiceProfile $r) => $r->stateLabel())->badge()
                    ->color(fn (ServiceProfile $r) => match ($r->stateTone()) {
                        'ok' => 'success', 'warn' => 'warning', 'bad' => 'danger', default => 'gray'
                    }),
                TextColumn::make('open_reports')->label('Reportes abiertos')->badge()->color(fn ($state) => $state ? 'danger' : 'gray'),
                TextColumn::make('contact_requests_count')->label('Solicitudes'),
                TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Estado')->options([
                    'review' => 'Esperando revisión', 'visible' => 'Publicados y aprobados', 'hidden' => 'Ocultos', 'suspended' => 'Suspendidos', 'draft' => 'Borradores', 'archived' => 'Archivados',
                ])->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                    'review' => $q->where('status', ServiceProfile::PUBLISHED)->whereNull('approved_at')->whereNull('moderation'),
                    'visible' => $q->where('status', ServiceProfile::PUBLISHED)->whereNotNull('approved_at')->whereNull('moderation'),
                    'hidden', 'suspended' => $q->where('moderation', $data['value']),
                    'draft', 'archived' => $q->where('status', $data['value'])->whereNull('moderation'),
                    default => $q,
                }),
            ])
            ->recordActions(ServiceModeration::profileActions());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Perfil')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('state')->label('Estado')->state(fn (ServiceProfile $r) => $r->stateLabel())->badge(),
                TextEntry::make('moderation_reason')->label('Motivo de moderación')->placeholder('—'),
                TextEntry::make('approved_at')->label('Aprobado')->dateTime('d/m/Y H:i')->placeholder('Sin aprobar'),
                TextEntry::make('public_name')->label('Nombre público'),
                TextEntry::make('kind')->label('Tipo')->formatStateUsing(fn ($state, ServiceProfile $r) => $r->kindLabel()),
                TextEntry::make('owner')->label('Cuenta')->state(fn (ServiceProfile $r) => ($r->supplier?->name ?? $r->company?->name ?? 'Dada de baja').' · '.$r->ownerLabel()),
                TextEntry::make('modality')->label('Modalidad')->formatStateUsing(fn ($state, ServiceProfile $r) => $r->modalityLabel()),
                TextEntry::make('coverage')->label('Zona')->state(fn (ServiceProfile $r) => $r->coverageLabel()),
                TextEntry::make('country')->label('País')->formatStateUsing(fn ($state) => Countries::name($state)),
                TextEntry::make('categories')->label('Especialidades')->state(fn (ServiceProfile $r) => $r->categories->pluck('name')->implode(', ') ?: '—')->columnSpanFull(),
                TextEntry::make('services')->label('Servicios')->state(fn (ServiceProfile $r) => implode(', ', (array) $r->services) ?: '—')->columnSpanFull(),
                TextEntry::make('brands')->label('Marcas')->state(fn (ServiceProfile $r) => implode(', ', (array) $r->brands) ?: '—')->columnSpanFull(),
                TextEntry::make('headline')->label('Frase')->placeholder('—')->columnSpanFull(),
                TextEntry::make('description')->label('Descripción')->columnSpanFull(),
                TextEntry::make('experience')->label('Experiencia')->placeholder('—')->columnSpanFull(),
                TextEntry::make('website')->label('Sitio web')->placeholder('—'),
                TextEntry::make('created_at')->label('Alta')->dateTime('d/m/Y H:i'),
                TextEntry::make('updated_at')->label('Última edición')->dateTime('d/m/Y H:i'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServiceProfiles::route('/'), 'view' => ViewServiceProfile::route('/{record}')];
    }
}
