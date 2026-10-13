<?php

namespace App\Filament\Resources\ParachuteTests\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Pages\ParachuteTestsPage;
use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListParachuteTests extends ListRecords
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ParachuteTests;

    protected static string $resource = ParachuteTestResource::class;

    protected static ?string $title = 'Historial de pruebas de paracaídas';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('status')->label('Estado por ascensor')->color('gray')->icon('heroicon-o-arrow-left')->url(ParachuteTestsPage::getUrl()),
            CreateAction::make()->label('Registrar prueba'),
        ];
    }
}
