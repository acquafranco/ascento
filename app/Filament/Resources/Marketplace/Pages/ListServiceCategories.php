<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\ServiceCategoryResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListServiceCategories extends ListRecords
{
    protected static string $resource = ServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('Nueva especialidad')->icon('heroicon-o-plus')
                ->schema(ServiceCategoryResource::fields())
                ->action(function (array $data) {
                    ServiceCategoryResource::saveFrom($data);
                    Notification::make()->title('Especialidad creada')->success()->send();
                }),
        ];
    }
}
