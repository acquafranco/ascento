<?php

namespace App\Services\OnCall;

use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Revisión previa (no guarda nada) de una asignación o un reemplazo: los
 * errores que impedirían guardarlo, las advertencias y el resumen en palabras
 * («Juan Pérez cubrirá la guardia desde el lunes 20/10 a las 18:00 hasta…»).
 *
 * Usa las mismas comprobaciones que OnCallService; al confirmar, el servicio
 * vuelve a validar todo dentro de su transacción (esto solo anticipa).
 */
class OnCallPlanner
{
    /** Franjas que se listan en el resumen antes de resumir el resto. */
    private const LISTED = 4;

    public function __construct(private OnCallService $service) {}

    /**
     * @param  list<int>  $userIds  el primero es el principal; el resto cubre junto con él
     * @return array{errors: list<string>, warnings: list<string>, headline: ?string, details: list<string>}
     */
    public function assignment(OnCallSchedule $schedule, array $userIds, ?CarbonInterface $from, ?CarbonInterface $to, bool $openEnded = false): array
    {
        $result = ['errors' => [], 'warnings' => [], 'headline' => null, 'details' => []];
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds ?: [0])->get()->sortBy(fn (User $u) => array_search((int) $u->id, $userIds, true))->values();

        if (! $schedule->is_active) {
            $result['errors'][] = 'Esa guardia está finalizada: no admite nuevas asignaciones.';
        }
        if ($users->isEmpty()) {
            $result['errors'][] = 'Elegí a quién asignamos.';
        }
        if (! $from) {
            $result['errors'][] = 'Indicá qué día y a qué hora comienza.';
        }
        if (! $openEnded && ! $to) {
            $result['errors'][] = 'Indicá hasta qué día y a qué hora cubre, o marcá «Sin fecha de fin».';
        }
        if ($result['errors']) {
            return $result;
        }

        $from = Carbon::parse($from)->startOfMinute();
        $to = $openEnded ? null : Carbon::parse($to)->startOfMinute();

        if ($to && $to->lte($from)) {
            $result['errors'][] = 'La guardia tiene que terminar después de empezar. Si termina al día siguiente (por ejemplo, de 18:00 a 08:00), elegí el día siguiente en «¿Hasta qué día?».';

            return $result;
        }
        if ($to && $to->lte(now())) {
            $result['errors'][] = 'Ese período ya pasó. Las guardias se asignan desde ahora en adelante.';

            return $result;
        }

        $needed = (int) $schedule->technicians_needed;
        if ($users->count() > $needed) {
            $result['errors'][] = OnCallService::capacityMessage($needed, $users->count());

            return $result;
        }

        foreach ($users as $i => $user) {
            try {
                $this->service->assertTechnician($schedule, $user);
                // Los que se suman en el mismo pedido se cuentan entre sí.
                $this->service->assertCapacity($schedule, (int) $user->id, $from, $to, extra: $i);
            } catch (ValidationException $e) {
                $result['errors'][] = ($users->count() > 1 ? $user->name.': ' : '').collect($e->errors())->flatten()->first();
            }
        }

        // ¿En ese período hay horario de guardia? (si no, el técnico no quedaría de guardia nunca)
        $horizon = $to ?? $from->copy()->addDays(14);
        $windows = $schedule->windowsBetween($from, $horizon);
        if ($to && $windows === []) {
            $result['errors'][] = 'En ese período la guardia «'.$schedule->name.'» no tiene horario de cobertura ('.rtrim($schedule->coverageSentence(), '.').'), así que nadie quedaría de guardia. Elegí días y horas dentro de ese horario.';
        }
        if ($result['errors']) {
            return $result;
        }

        $names = self::names($users);
        $plural = $users->count() > 1;
        $result['headline'] = $names.' '.($plural ? 'cubrirán' : 'cubrirá').' la guardia «'.$schedule->name.'» '.OnCallText::range($from, $to).'.';

        // Qué franjas cubre de verdad dentro del período.
        $clipped = collect($windows)->map(fn ($w) => [$w[0]->copy()->max($from), $w[1]->copy()->min($horizon)])->filter(fn ($w) => $w[1]->gt($w[0]))->values();
        $listed = $clipped->take(self::LISTED)->map(fn ($w) => OnCallText::span($w[0], $w[1]))->implode(' · ');
        $result['details'][] = $to
            ? 'Está de guardia en '.($clipped->count() === 1 ? 'esta franja' : 'estas '.$clipped->count().' franjas').': '.$listed.($clipped->count() > self::LISTED ? ' y '.($clipped->count() - self::LISTED).' más' : '').'.'
            : 'Desde '.OnCallText::short($from).', cubre todas las franjas de la guardia hasta que lo cambies. Las próximas: '.$listed.'.';

        if (! $this->periodIsAllCoverage($schedule)) {
            $result['details'][] = 'Horario de esta guardia: '.$schedule->coverageSentence().' Fuera de ese horario no '.($plural ? 'quedan' : 'queda').' de guardia.';
        }

        // Una franja que empieza antes o termina después de lo elegido queda partida.
        if (($first = $windows[0] ?? null) && $first[0]->lt($from)) {
            $result['warnings'][] = 'La franja empieza '.OnCallText::short($first[0]).', pero elegiste '.$from->format('H:i').': de '.$first[0]->format('H:i').' a '.$from->format('H:i').' no '.($plural ? 'la cubren' : 'la cubre').' '.$names.'.';
        }
        if ($to && ($last = end($windows)) && $last[1]->gt($to)) {
            $result['warnings'][] = 'La última franja termina '.OnCallText::short($last[1]).', pero elegiste '.$to->format('H:i').': de '.$to->format('H:i').' a '.$last[1]->format('H:i').' no '.($plural ? 'la cubren' : 'la cubre').' '.$names.'.';
        }
        if ($from->lt(now()->startOfMinute())) {
            $result['warnings'][] = 'El inicio ya pasó: queda registrado que '.($plural ? 'están' : 'está').' de guardia desde '.OnCallText::short($from).'.';
        }
        foreach ($users as $user) {
            foreach ($this->otherGuards($schedule, $user, $from, $to) as $other) {
                $result['warnings'][] = $user->name.' también está de guardia en «'.$other['schedule'].'» '.$other['when'].'. Confirmá que pueda cubrir las dos.';
            }
        }

        $result['details'][] = 'Ascento no sabe si '.($plural ? 'están disponibles' : 'está disponible').': confirmalo con '.($plural ? 'ellos' : 'el técnico').'. Le'.($plural ? 's' : '').' llega un aviso en Ascento (y por notificación o Telegram, si los activó).';

        return $result;
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, headline: ?string, details: list<string>}
     */
    public function replacement(OnCallShift $shift, ?User $newUser, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $result = ['errors' => [], 'warnings' => [], 'headline' => null, 'details' => []];
        $shift = OnCallShift::withoutGlobalScopes()->with(['user' => fn ($q) => $q->withoutGlobalScopes()])->findOrFail($shift->id);
        $schedule = OnCallSchedule::withoutGlobalScopes()->findOrFail($shift->on_call_schedule_id);
        $original = $shift->user?->name ?? 'el técnico anterior';
        $end = $shift->ends_at;
        $now = now()->startOfMinute();

        if (! $shift->isActive()) {
            $result['errors'][] = 'Ese turno ya fue modificado o cancelado. Cerrá esta ventana y volvé a abrirla para ver los turnos actuales.';
        }
        if (! $newUser) {
            $result['errors'][] = 'Elegí quién lo reemplaza.';
        }
        if (! $from) {
            $result['errors'][] = 'Indicá desde cuándo se aplica el reemplazo.';
        }
        if ($result['errors']) {
            return $result;
        }

        $from = Carbon::parse($from)->startOfMinute();
        $to = $to ? Carbon::parse($to)->startOfMinute() : $end;

        if ($from->lt($now)) {
            $result['errors'][] = 'Lo que ya pasó no se cambia: el reemplazo tiene que empezar ahora ('.$now->format('H:i').') o más adelante.';
        } elseif ($from->lt($shift->starts_at) || ($end && $from->gte($end))) {
            $result['errors'][] = 'El reemplazo tiene que empezar dentro del turno de '.$original.' ('.self::shiftPeriod($shift).').';
        }
        if ($to && $to->lte($from)) {
            $result['errors'][] = 'El reemplazo tiene que terminar después de empezar. Si termina al día siguiente, elegí el día siguiente en «¿Hasta qué día?».';
        } elseif ($to && $end && $to->gt($end)) {
            $result['errors'][] = 'El turno de '.$original.' termina '.OnCallText::short($end).': el reemplazo no puede ir más allá. Para cubrir después, usá «Asignar guardia».';
        }
        if ((int) $newUser->id === (int) $shift->user_id) {
            $result['errors'][] = 'Elegí a otra persona: '.$original.' ya es quien cubre ese turno.';
        }
        if ($result['errors']) {
            return $result;
        }

        try {
            $this->service->assertTechnician($schedule, $newUser);
            $this->service->assertCapacity($schedule, (int) $newUser->id, $from, $to, [$shift->id]);
        } catch (ValidationException $e) {
            $result['errors'][] = collect($e->errors())->flatten()->first();

            return $result;
        }

        $horizon = $to ?? $from->copy()->addDays(14);
        $windows = collect($schedule->windowsBetween($from, $horizon))->map(fn ($w) => [$w[0]->copy()->max($from), $w[1]->copy()->min($horizon)])->filter(fn ($w) => $w[1]->gt($w[0]))->values();
        if ($to && $windows->isEmpty()) {
            $result['errors'][] = 'En ese período la guardia no tiene horario de cobertura ('.rtrim($schedule->coverageSentence(), '.').'): el reemplazo no cambiaría nada. Elegí días y horas dentro de ese horario.';

            return $result;
        }

        $result['headline'] = $newUser->name.' reemplaza a '.$original.' en «'.$schedule->name.'» '.OnCallText::range($from, $to).'.';
        $listed = $windows->take(self::LISTED)->map(fn ($w) => OnCallText::span($w[0], $w[1]))->implode(' · ');
        $result['details'][] = ($to ? 'Cubre '.($windows->count() === 1 ? 'esta franja' : 'estas '.$windows->count().' franjas').': ' : 'Cubre todas las franjas desde ahí, hasta que lo cambies. Las próximas: ').$listed
            .($to && $windows->count() > self::LISTED ? ' y '.($windows->count() - self::LISTED).' más' : '').'.';

        if ($from->gt($shift->starts_at)) {
            $result['details'][] = 'Hasta '.OnCallText::short($from).', el turno sigue a nombre de '.$original.'.';
        }
        if ($to && ($end === null || $to->lt($end))) {
            $result['details'][] = 'Desde '.OnCallText::short($to).', vuelve a cubrir '.$original.($end ? ' hasta '.OnCallText::short($end) : ', sin fecha de fin').'.';
        }
        $result['details'][] = 'No cambia ningún otro turno. Lo que ya pasó queda como estaba y el cambio queda en el historial.';

        $orders = $shift->workOrders()->withoutGlobalScopes()->count();
        if ($orders > 0) {
            $result['details'][] = ($orders === 1 ? 'La orden de trabajo vinculada' : "Las {$orders} órdenes de trabajo vinculadas").' a este turno '.($orders === 1 ? 'sigue vinculada' : 'siguen vinculadas').' a la guardia de '.$original.'.';
        }
        foreach ($this->otherGuards($schedule, $newUser, $from, $to) as $other) {
            $result['warnings'][] = $newUser->name.' también está de guardia en «'.$other['schedule'].'» '.$other['when'].'. Confirmá que pueda cubrir las dos.';
        }
        $result['details'][] = 'Avisamos a '.$newUser->name.' y a '.$original.'. Ascento no sabe si '.$newUser->name.' está disponible: confirmalo antes.';

        return $result;
    }

    /** ¿La guardia cubre todo el período elegido? (guardia de 24 h todos los días) */
    private function periodIsAllCoverage(OnCallSchedule $schedule): bool
    {
        return array_map('intval', (array) $schedule->days) === [1, 2, 3, 4, 5, 6, 7] && substr($schedule->starts_time, 0, 5) === substr($schedule->ends_time, 0, 5);
    }

    /**
     * Otras guardias en las que el técnico ya está de guardia al mismo tiempo
     * (cruce real de horarios, no solo de períodos).
     *
     * @return list<array{schedule: string, when: string}>
     */
    public function otherGuards(OnCallSchedule $schedule, User $user, CarbonInterface $from, ?CarbonInterface $to): array
    {
        $to ??= Carbon::parse($from)->addDays(14);
        $mine = $schedule->windowsBetween($from, $to);
        $found = [];

        $others = OnCallShift::withoutGlobalScopes()->where('company_id', $schedule->company_id)->where('user_id', $user->id)
            ->where('on_call_schedule_id', '!=', $schedule->id ?? 0)->active()->overlapping($from, $to)
            ->with(['schedule' => fn ($q) => $q->withoutGlobalScopes()])->get();

        foreach ($others as $shift) {
            if (! $shift->schedule) {
                continue;
            }
            $start = $shift->starts_at->copy()->max($from);
            $end = ($shift->ends_at ?? $to)->copy()->min($to);
            foreach ($shift->schedule->windowsBetween($start, $end) as [$a, $b]) {
                $a = $a->max($start);
                $b = $b->min($end);
                foreach ($mine as [$c, $d]) {
                    if ($a->lt($d) && $c->lt($b)) {
                        $found[$shift->schedule->name] ??= ['schedule' => $shift->schedule->name, 'when' => OnCallText::span($a->copy()->max($c), $b->copy()->min($d))];
                    }
                }
            }
        }

        return array_values($found);
    }

    /** «Juan Pérez», «Juan Pérez y Ana López», «Juan, Ana y Luis». */
    public static function names(Collection $users): string
    {
        $names = $users->pluck('name')->values();

        return $names->count() <= 1 ? (string) $names->first() : $names->slice(0, -1)->implode(', ').' y '.$names->last();
    }

    /** «hoy 18:00 a mañana 08:00» o «desde hoy 18:00, sin fecha de fin». */
    public static function shiftPeriod(OnCallShift $shift): string
    {
        return $shift->ends_at ? OnCallText::short($shift->starts_at).' a '.OnCallText::short($shift->ends_at) : 'desde '.OnCallText::short($shift->starts_at).', sin fecha de fin';
    }
}
