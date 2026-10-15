<?php

namespace App\Filament\Resources\Emergency\Pages;

use App\Filament\Resources\Emergency\EmergencyRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListEmergencyRequests extends ListRecords
{
    protected static string $resource = EmergencyRequestResource::class;

    protected ?string $subheading = 'Pedidos de ayuda por posible atrapamiento registrados desde el QR público de tus edificios. Son avisos sin confirmar: verificá cada uno.';
}
