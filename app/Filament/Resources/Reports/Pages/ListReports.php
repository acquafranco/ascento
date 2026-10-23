<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Enums\PlanLimit;
use App\Filament\Concerns\ShowsPlanUsage;
use App\Filament\Resources\Reports\ReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    use ShowsPlanUsage;

    protected static PlanLimit $planLimit = PlanLimit::ReportsPerMonth;

    protected static string $resource = ReportResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que registran tus técnicos durante su trabajo: fallas, intervenciones y observaciones de inspección. Los reclamos y avisos que llegan del portal o del QR están en «Reclamos y avisos externos».';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->planUsageAction(),
            CreateAction::make(),
        ];
    }
}
