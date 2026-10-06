<?php

namespace App\Filament\Resources\Users\Widgets;

use App\Models\WorkOrder;
use Livewire\Attributes\Locked;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserStatsWidget extends StatsOverviewWidget
{
    // Lo fija la página (ViewUser); el cliente no puede cambiarlo.
    #[Locked]
    public ?object $record = null;

    protected function getStats(): array
    {
        if (!$this->record) {
            return [];
        }

        return [

            Stat::make(
                'Pendientes',
                WorkOrder::whereHas('users', function ($q) {

                    $q->where(
                        'users.id',
                        $this->record->id
                    );

                })
                ->where('status', 'pending')
                ->count()
            ),

            Stat::make(
                'En progreso',
                WorkOrder::whereHas('users', function ($q) {

                    $q->where(
                        'users.id',
                        $this->record->id
                    );

                })
                ->where('status', 'in_progress')
                ->count()
            ),

            Stat::make(
                'Completadas hoy',
                WorkOrder::whereHas('users', function ($q) {

                    $q->where(
                        'users.id',
                        $this->record->id
                    );

                })
                ->where('status', 'completed')
                ->whereDate(
                    'finished_at',
                    today()
                )
                ->count()
            ),

            Stat::make(
                'Edificios',
                $this->record->buildings()
                    ->distinct('buildings.id')
                    ->count('buildings.id')
            ),

        ];
    }
}
