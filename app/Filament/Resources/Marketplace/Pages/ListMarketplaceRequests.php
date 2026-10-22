<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\MarketplaceRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceRequests extends ListRecords
{
    protected static string $resource = MarketplaceRequestResource::class;
}
