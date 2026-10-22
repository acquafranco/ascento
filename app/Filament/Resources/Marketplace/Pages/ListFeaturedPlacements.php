<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\FeaturedPlacementResource;
use Filament\Resources\Pages\ListRecords;

class ListFeaturedPlacements extends ListRecords
{
    protected static string $resource = FeaturedPlacementResource::class;
}
