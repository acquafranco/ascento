<?php

namespace App\Filament\Resources\StaffUsers\Pages;

use App\Filament\Resources\StaffUsers\StaffUserResource;
use App\Services\Staff\StaffManager;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStaffUser extends EditRecord
{
    protected static string $resource = StaffUserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'levels' => StaffManager::levelsFromKeys((array) ($this->record->permissions ?? [])),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $manager = app(StaffManager::class);
        $manager->update(auth()->user(), $record, $data);

        return $manager->setPermissions(auth()->user(), $record->fresh(), $data['levels'] ?? []);
    }

    protected function getRedirectUrl(): string
    {
        return StaffUserResource::getUrl('index');
    }
}
