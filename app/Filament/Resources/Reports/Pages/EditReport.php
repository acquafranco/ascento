<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Services\Reports\ReportPhotoService;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Filament\Resources\Pages\EditRecord;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReportPdfAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $photos = $data['new_photos'] ?? [];
        unset($data['new_photos']);

        return ReportPhotoForm::withPhotoErrors(fn () => DB::transaction(function () use ($record, $data, $photos) {
            $record->update($data);
            app(ReportPhotoService::class)->add($record, $photos);

            return $record;
        }));
    }
}
