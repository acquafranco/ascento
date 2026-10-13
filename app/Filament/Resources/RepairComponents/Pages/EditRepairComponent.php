<?php

namespace App\Filament\Resources\RepairComponents\Pages;

use App\Filament\Resources\RepairComponents\RepairComponentResource;
use App\Services\Repairs\RepairService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRepairComponent extends EditRecord
{
    protected static string $resource = RepairComponentResource::class;

    protected static ?string $title = 'Corregir componente en reparación';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(RepairService::class);
        $service->update(auth()->user(), $record, $data);

        foreach (array_values($data['files'] ?? []) as $file) {
            $service->attach(auth()->user(), $record, $file);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return RepairComponentResource::getUrl('view', ['record' => $this->record]);
    }
}
