<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableActions;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Receivables\Widgets\ReceivablesOverview;
use Filament\Resources\Pages\ListRecords;

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    protected ?string $subheading = 'Cuentas por cobrar: servicios de mantenimiento, presupuestos aprobados y otros conceptos. Gestión interna, sin facturación.';

    protected function getHeaderActions(): array
    {
        return [
            ReceivableActions::generateFromServices(),
            ReceivableActions::createManual(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ReceivablesOverview::class];
    }
}
