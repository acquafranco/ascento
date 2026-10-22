<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\MarketplaceRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMarketplaceRequest extends ViewRecord
{
    protected static string $resource = MarketplaceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return MarketplaceRequestResource::adminActions();
    }
}
