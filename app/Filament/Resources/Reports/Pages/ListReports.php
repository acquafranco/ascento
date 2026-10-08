<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    use \App\Filament\Concerns\ShowsPlanUsage;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::ReportsPerMonth;

    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->planUsageAction(),
            CreateAction::make(),
        ];
    }
}
