<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Models\OnCallSchedule;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\RotationService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditOnCallSchedule extends EditRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::OnCall;

    protected static string $resource = OnCallScheduleResource::class;

    protected ?string $subheading = 'Los cambios valen desde ahora: los turnos pasados y en curso no se recalculan.';

    protected static ?string $title = 'Cambiar horario o rotación';

    /**
     * Con rotación, guardar puede recalcular turnos ya generados: se pide
     * confirmación, con lo que va a pasar (la vista previa lo detalla).
     */
    protected function getSaveFormAction(): Action
    {
        if (! $this->getRecord()->isRotation()) {
            return parent::getSaveFormAction();
        }

        return parent::getSaveFormAction()
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading('¿Guardar los cambios de la rotación?')
            ->modalDescription('Se recalculan solo los turnos futuros que generó la rotación, como muestra la vista previa, y se avisa a los técnicos afectados. Los turnos pasados, el que está en curso y los cambios hechos a mano no se tocan.')
            ->modalSubmitActionLabel('Sí, guardar')
            ->action(fn () => $this->save());
    }

    protected function afterSave(): void
    {
        /** @var OnCallSchedule $schedule */
        $schedule = $this->record;
        $labels = ['name' => 'nombre', 'notes' => 'indicaciones', 'days' => 'días', 'starts_time' => 'hora de inicio', 'ends_time' => 'hora de fin',
            'technicians_needed' => 'técnicos a la vez', 'mode' => 'modalidad', 'rotation_days' => 'duración del turno', 'rotation_starts_on' => 'inicio de la rotación'];
        $changed = array_values(array_intersect_key($labels, $schedule->getChanges()));

        if ($changed) {
            app(OnCallService::class)->log($schedule, null, 'schedule_updated', null, null, now(), null, null, 'Cambió: '.implode(', ', $changed).'.', auth()->user());
        }

        if ($schedule->isRotation()) {
            OnCallRotationFeedback::send(app(RotationService::class)->sync($schedule, auth()->user(), reason: 'Rotación modificada'));
        }
    }

    protected function getRedirectUrl(): string
    {
        return OnCallScheduleResource::getUrl('view', ['record' => $this->record]);
    }
}
