<?php

namespace App\Filament\Resources\OnCall\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Models\OnCallChange;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Historial de cambios de la guardia (solo lectura): quién cambió qué, cuándo y por qué. */
class ChangesRelationManager extends RelationManager
{
    use GuardedRelation;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::OnCall;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'changes';

    protected static ?string $title = 'Historial de cambios';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromUser', 'toUser', 'actor']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Cuándo')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('action')->label('Qué')->badge()->formatStateUsing(fn ($state, OnCallChange $r) => $r->actionLabel())
                    ->description(fn (OnCallChange $r) => $r->summary),
                TextColumn::make('fromUser.name')->label('Técnico anterior')->placeholder('—'),
                TextColumn::make('toUser.name')->label('Técnico nuevo')->placeholder('—'),
                TextColumn::make('period')->label('Período afectado')
                    ->state(fn (OnCallChange $r) => $r->period_start ? $r->period_start->format('d/m/Y H:i').' – '.($r->period_end?->format('d/m/Y H:i') ?? 'en adelante') : '—'),
                TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
                TextColumn::make('actor.name')->label('Lo hizo')->placeholder('Automático'),
            ])
            ->filters([SelectFilter::make('action')->label('Tipo')->options(OnCallChange::ACTIONS)])
            ->emptyStateHeading('Sin cambios registrados');
    }
}
