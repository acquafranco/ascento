<?php

namespace App\Services\OnCall;

use App\Models\Company;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Notifications\App\OnCallNotification;
use App\Services\Notifications\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Avisos de guardias al técnico afectado (bandeja + push/Telegram si los activó),
 * con el Notifier de siempre: misma empresa, cuenta activa, sin duplicar. Se
 * envían después de confirmar la transacción (un cambio rechazado no avisa).
 */
class OnCallNotifier
{
    public function __construct(private Notifier $notifier) {}

    public function assigned(OnCallShift $shift, ?OnCallShift $replacing = null): void
    {
        $this->afterCommit(function () use ($shift, $replacing) {
            $schedule = $this->schedule($shift);
            $detail = $replacing ? 'Cambio de guardia: reemplazás a '.($replacing->user?->name ?? 'otro técnico').'.' : null;
            $this->send($shift->user_id, $schedule, new OnCallNotification('assigned', $schedule->name, self::period($shift->starts_at, $shift->ends_at), $this->path($schedule), 'oncall:assigned:'.$shift->id, $detail));
        });
    }

    public function removed(OnCallShift $shift, CarbonInterface $from, ?CarbonInterface $to, ?User $newUser): void
    {
        $this->afterCommit(function () use ($shift, $from, $to, $newUser) {
            $schedule = $this->schedule($shift);
            $detail = $newUser ? 'Lo cubre '.$newUser->name.'.' : null;
            $this->send($shift->user_id, $schedule, new OnCallNotification('removed', $schedule->name, self::period($from, $to), $this->path($schedule), 'oncall:removed:'.$shift->id, $detail));
        });
    }

    public function upcoming(OnCallShift $shift): void
    {
        $schedule = $this->schedule($shift);
        $this->send($shift->user_id, $schedule, new OnCallNotification('upcoming', $schedule->name, self::period($shift->starts_at, $shift->ends_at), $this->path($schedule), 'oncall:upcoming:'.$shift->id));
    }

    public static function period(CarbonInterface $from, ?CarbonInterface $to): string
    {
        return 'del '.$from->format('d/m H:i').($to ? ' al '.$to->format('d/m H:i') : ' en adelante');
    }

    private function send(int $userId, OnCallSchedule $schedule, OnCallNotification $notification): void
    {
        if ($user = User::withoutGlobalScopes()->find($userId)) {
            $this->notifier->sendTo($user, $notification, (int) $schedule->company_id);
        }
    }

    private function schedule(OnCallShift $shift): OnCallSchedule
    {
        return OnCallSchedule::withoutGlobalScopes()->findOrFail($shift->on_call_schedule_id);
    }

    private function path(OnCallSchedule $schedule): ?string
    {
        $slug = Company::whereKey($schedule->company_id)->value('slug');

        return $slug ? route('on-call.index', ['company' => $slug], false) : null;
    }

    private function afterCommit(callable $callback): void
    {
        DB::afterCommit($callback);
    }
}
