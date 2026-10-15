<?php

namespace App\Console\Commands;

use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Services\OnCall\OnCallNotifier;
use Illuminate\Console\Command;

/**
 * Recordatorio al técnico de un turno de guardia que empieza en las próximas
 * 24 horas. Una sola vez por turno (reminded_at y la clave del aviso).
 */
class SendOnCallReminders extends Command
{
    protected $signature = 'oncall:remind';

    protected $description = 'Avisa a los técnicos las guardias que empiezan en las próximas 24 horas (sin duplicar)';

    public function handle(OnCallNotifier $notifier): int
    {
        $sent = 0;
        OnCallShift::withoutGlobalScopes()->active()->whereNull('reminded_at')
            ->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDay())
            ->whereIn('on_call_schedule_id', OnCallSchedule::withoutGlobalScopes()->where('is_active', true)->select('id'))
            ->orderBy('id')
            ->each(function (OnCallShift $shift) use ($notifier, &$sent) {
                $notifier->upcoming($shift);
                $shift->forceFill(['reminded_at' => now()])->saveQuietly();
                $sent++;
            });

        $this->info("Recordatorios generados: {$sent}");

        return self::SUCCESS;
    }
}
