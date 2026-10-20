<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Models\OnCallSchedule;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\RotationService;
use Filament\Actions\Action;
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

    protected static ?string $title = 'Configurar un horario de guardia';

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Guardar horario');
    }

    /** De vuelta a la portada, donde se ve quién cubre y qué falta asignar. */
    protected function getRedirectUrl(): string
    {
        return OnCallScheduleResource::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->title('Horario de guardia guardado')
            ->body($this->record->isRotation() ? null : 'Ahora asigná quién cubre cada turno con «Asignar guardia».')->success();
    }
}
