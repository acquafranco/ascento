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
            ->url(function ($record) {
                $user = auth()->user();

                $companySlug = $user->isSuperAdmin()
                    ? \App\Models\Company::find(session('selected_company_id'))?->slug
                    : $user->company?->slug;

                return route('users.template', [
                    'company' => $companySlug,
                    'user' => $record,
                ]);
            })
            ->openUrlInNewTab(),
    ];
}

}
