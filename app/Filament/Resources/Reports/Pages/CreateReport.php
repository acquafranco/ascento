<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\Reports\ReportPhotoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateReport extends CreateRecord
{
    use \App\Filament\Concerns\ChecksPlanLimitOnCreate;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::ReportsPerMonth;

    protected static string $resource = ReportResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['company_id'] = auth()->user()->company_id;
        $data['user_id'] = auth()->id();
        $photos = $data['new_photos'] ?? [];
        unset($data['new_photos']);

        return ReportPhotoForm::withPhotoErrors(fn () => DB::transaction(function () use ($data, $photos) {
            $report = static::getModel()::create($data);
            app(ReportPhotoService::class)->add($report, $photos);

            return $report;
        }));
    }
}
