<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Services\Tasks\TaskService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(TaskService::class)->create(auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('index');
    }
}
