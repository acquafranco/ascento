<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions\Action;

class ViewUser extends ViewRecord
{
        protected static string $resource = UserResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Resources\Users\Widgets\UserStatsWidget::class,
            \App\Filament\Resources\Users\Widgets\UserOrdersWidget::class,
        ];
    }

   protected function getHeaderActions(): array
{
    return [
        \Filament\Actions\Action::make('ver_template')
            ->label('📅 Ver plantilla')
            ->url(fn ($record) => route('users.template', $record))
            ->openUrlInNewTab(),
    ];
}

}
