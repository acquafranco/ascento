<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * «Trabajos de la visita» de una orden: los problemas que el técnico registró
 * y atendió (intervenciones), cada uno con por dónde llegó, qué hizo y cómo
 * quedó. Solo lectura: los carga el técnico y se cierran al firmar el remito.
 */
class JobsRelationManager extends RelationManager
{
    use GuardedRelation;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::WorkOrders;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'jobs';

    protected static ?string $title = 'Trabajos de la visita';

    protected static ?string $modelLabel = 'trabajo';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user')->withCount('photos'))
            ->columns([
                TextColumn::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i'),
                TextColumn::make('user.name')->label('Técnico'),
                TextColumn::make('elevator_number')->label('Equipo'),
                TextColumn::make('channel')->label('Reclamo por')->formatStateUsing(fn (?string $state) => Report::CHANNELS[$state] ?? '—'),
                TextColumn::make('description')->label('Problema')->limit(60)->wrap(),
                TextColumn::make('work_done')->label('Realizado')->limit(60)->wrap()->placeholder('Pendiente del remito'),
                TextColumn::make('result')->label('Resultado')->badge()
                    ->formatStateUsing(fn (?string $state) => Report::RESULTS[$state] ?? '—')
                    ->color(fn (?string $state) => $state === 'resolved' ? 'success' : ($state ? 'warning' : 'gray'))
                    ->placeholder('Pendiente'),
                TextColumn::make('elevator_state')->label('Estado del equipo')->formatStateUsing(fn (?string $state) => Report::ELEVATOR_STATES[$state] ?? '—')->placeholder('—'),
                TextColumn::make('pending_work')->label('Pendiente')->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('photos_count')->label('Fotos'),
            ])
            ->recordUrl(fn (Report $record) => ReportResource::getUrl('view', ['record' => $record]))
            ->paginated(false);
    }
}
