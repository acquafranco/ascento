<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\SupplierProductResource;
use Filament\Resources\Pages\ListRecords;

class ListSupplierProducts extends ListRecords
{
    protected static string $resource = SupplierProductResource::class;
}
