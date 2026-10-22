<?php

namespace App\Filament\Resources\SalesLeads\Pages;

use App\Filament\Resources\SalesLeads\SalesLeadResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesLead extends ViewRecord
{
    protected static string $resource = SalesLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [SalesLeadResource::statusAction()];
    }
}
