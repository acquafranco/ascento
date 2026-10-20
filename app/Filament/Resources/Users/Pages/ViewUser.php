<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UserOrdersWidget;
use App\Filament\Resources\Users\Widgets\UserStatsWidget;
use App\Services\Technicians\TechnicianSheet;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            UserStatsWidget::class,
            UserOrdersWidget::class,
        ];
    }

    /** La planilla del técnico, en el panel (solo el dueño: TechnicianSheet::canView). */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sheet')
                ->label('Ver planilla')
                ->icon('heroicon-o-table-cells')
                ->url(fn ($record) => TechnicianSheetPage::getUrl(['record' => $record]))
                ->visible(fn ($record) => TechnicianSheet::canView(auth()->user(), $record)),
        ];
    }
}
