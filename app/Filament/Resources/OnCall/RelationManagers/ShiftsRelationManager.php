<?php

namespace App\Filament\Resources\OnCall\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Resources\OnCall\OnCallActions;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Models\OnCallShift;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Turnos de la guardia: vigentes y futuros por defecto; el historial completo con el filtro. */
class ShiftsRelationManager extends RelationManager
{
    use GuardedRelation;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::OnCall;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'shifts';

    protected static ?string $title = 'Turnos';

    public function isReadOnly(): bool
    {
        return ! OnCallScheduleResource::canEdit($this->getOwnerRecord());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user' => fn ($u) => $u->withoutGlobalScopes(), 'parent.user']))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('user.name')->label('Técnico')->weight('bold')
                    ->description(fn (OnCallShift $r) => $r->user?->trashed() ? 'Técnico desactivado: no cubre' : null),
                TextColumn::make('starts_at')->label('Desde')->dateTime('D d/m/Y H:i'),
                TextColumn::make('ends_at')->label('Hasta')->dateTime('D d/m/Y H:i')->placeholder('Permanente'),
                TextColumn::make('origin')->label('Origen')->formatStateUsing(fn ($state) => OnCallShift::ORIGINS[$state] ?? $state)
                    ->description(fn (OnCallShift $r) => $r->origin === 'replacement' && $r->parent?->user ? 'Reemplaza a '.$r->parent->user->name : null),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state, OnCallShift $r) => match (true) {
                        $state !== OnCallShift::ACTIVE => OnCallShift::STATUSES[$state],
                        $r->includes(now()) => 'En curso',
                        $r->starts_at->gt(now()) => 'Próximo',
                        default => 'Cumplido',
                    })
                    ->color(fn ($state, OnCallShift $r) => match (true) {
                        $state === OnCallShift::CANCELLED => 'danger',
                        $state === OnCallShift::REPLACED => 'gray',
                        $r->includes(now()) => 'success',
                        default => 'info',
                    })
                    ->description(fn (OnCallShift $r) => $r->close_reason ? 'Motivo: '.$r->close_reason : null),
                TextColumn::make('work_orders_count')->label('Trabajos')->counts('workOrders')->alignCenter()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('view')->label('Mostrar')->default('current')
                    ->options(['current' => 'Vigentes y próximos', 'past' => 'Ya cumplidos', 'all' => 'Todo el historial (incluye modificados y cancelados)'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'current') {
                        'past' => $query->active()->whereNotNull('ends_at')->where('ends_at', '<=', now()),
                        'all' => $query,
                        default => $query->active()->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now())),
                    }),
            ])
            ->headerActions([OnCallActions::assign()->record(fn () => $this->getOwnerRecord())])
            ->recordActions([OnCallActions::replace(), OnCallActions::end(), OnCallActions::cancel()])
            ->emptyStateHeading('Sin turnos para mostrar')
            ->emptyStateDescription('Asigná un técnico (o configurá la rotación) para cubrir esta guardia.');
    }
}
