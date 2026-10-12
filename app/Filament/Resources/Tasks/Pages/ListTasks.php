<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nueva tarea')];
    }

    /** Qué está pendiente y qué se completó, de un vistazo. */
    public function getTabs(): array
    {
        return [
            'mine' => Tab::make('Mis tareas')->modifyQueryUsing(fn (Builder $query) => $query->open()->where('assigned_to', auth()->id()))
                ->badge(fn () => Task::open()->where('assigned_to', auth()->id())->count() ?: null),
            'open' => Tab::make('Pendientes')->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'overdue' => Tab::make('Vencidas')->modifyQueryUsing(fn (Builder $query) => $query->overdue())
                ->badge(fn () => Task::overdue()->count() ?: null)->badgeColor('danger'),
            'done' => Tab::make('Completadas')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'done')->reorder('completed_at', 'desc')),
            'all' => Tab::make('Todas'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
