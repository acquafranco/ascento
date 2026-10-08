<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Subscriptions\Widgets\SubscriptionStats;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    protected ?string $subheading = 'Estado y períodos de cada empresa según Mercado Pago. Las empresas en prueba gratis están en Empresas.';

    protected function getHeaderWidgets(): array
    {
        return [SubscriptionStats::class];
    }
}
