<?php

namespace App\Console\Commands;

use App\Models\OnCallSchedule;
use App\Services\OnCall\RotationService;
use Illuminate\Console\Command;

/** Guardias rotativas: mantiene generados los turnos futuros hasta el horizonte (idempotente). */
class SyncOnCallRotations extends Command
{
    protected $signature = 'oncall:rotations';

    protected $description = 'Genera los próximos turnos de las guardias rotativas (sin tocar el pasado ni los cambios manuales)';

    public function handle(RotationService $rotations): int
    {
        $created = 0;
        OnCallSchedule::withoutGlobalScopes()->where('is_active', true)->where('mode', OnCallSchedule::ROTATION)
            ->each(function (OnCallSchedule $schedule) use ($rotations, &$created) {
                $created += $rotations->sync($schedule)['created'];
            });

        $this->info("Turnos nuevos: {$created}");

        return self::SUCCESS;
    }
}
