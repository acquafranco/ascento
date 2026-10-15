<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Models\OnCallSchedule;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\RotationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateOnCallSchedule extends CreateRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::OnCall;

    protected static string $resource = OnCallScheduleResource::class;

    protected function afterCreate(): void
    {
        /** @var OnCallSchedule $schedule */
        $schedule = $this->record;
        app(OnCallService::class)->log($schedule, null, 'schedule_updated', null, null, now(), null, null, 'Guardia creada: '.$schedule->coverageLabel().'.', auth()->user());

        if ($schedule->isRotation()) {
            $result = app(RotationService::class)->sync($schedule, auth()->user(), reason: 'Rotación creada');
            OnCallRotationFeedback::send($result);
        }
    }

    protected function getRedirectUrl(): string
    {
        return OnCallScheduleResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->title('Guardia creada')->body($this->record->isRotation() ? null : 'Ahora asigná los técnicos desde «Asignar técnico».')->success();
    }
}
