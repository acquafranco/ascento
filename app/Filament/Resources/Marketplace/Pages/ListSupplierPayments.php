<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\SupplierPaymentResource;
use App\Filament\Resources\Marketplace\Widgets\MarketplaceRevenueStats;
use Filament\Resources\Pages\ListRecords;

class ListSupplierPayments extends ListRecords
{
    protected static string $resource = SupplierPaymentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [MarketplaceRevenueStats::class];
    }
}
