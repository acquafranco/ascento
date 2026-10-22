<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\MarketplaceModeration;
use App\Filament\Resources\Marketplace\SupplierResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return MarketplaceModeration::supplierActions();
    }
}
