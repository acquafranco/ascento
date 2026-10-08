<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    use \App\Filament\Concerns\ChecksPlanLimitOnCreate;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::Clients;

    protected static string $resource = ClientResource::class;
}
