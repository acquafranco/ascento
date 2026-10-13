<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\Tasks\TaskResource;
use App\Services\Tasks\TaskService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTask extends EditRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::Tasks;

    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['reminder_recipients'] = $this->record->reminderRecipients()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(TaskService::class)->update(auth()->user(), $record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('index');
    }
}
