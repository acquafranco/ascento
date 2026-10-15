<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Filament\Resources\OnCall\Widgets\OnCallOverview;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnCallSchedules extends ListRecords
{
    protected static string $resource = OnCallScheduleResource::class;

    protected ?string $subheading = 'Cobertura técnica fuera del horario habitual: quién está de guardia ahora, las próximas franjas y los cambios.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nueva guardia')];
    }

    protected function getHeaderWidgets(): array
    {
        return [OnCallOverview::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
