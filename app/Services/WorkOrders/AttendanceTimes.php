<?php

namespace App\Services\WorkOrders;

use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tiempos de atención de una orden: AVISO (call_received_at), LLEGADA
 * (arrived_at) y SALIDA (left_at), y las tres duraciones.
 *
 * - Llegada y salida: las registra el técnico ASIGNADO a la orden, con la hora
 *   del servidor, una sola vez cada una (con bloqueo de fila: dos toques o dos
 *   celulares no duplican). La hora no se edita. Abrir la orden o tener la
 *   ubicación activada NO registra nada.
 * - Salida: después de la llegada. No cierra la orden, no firma ni reemplaza
 *   el remito (finished_at sigue siendo la firma).
 * - Aviso: no se deduce de created_at. Lo carga quien lo sabe (la oficina en
 *   el panel, el técnico al abrir su intervención) o sale del reclamo del
 *   portal o del QR. Nunca en el futuro ni después de la llegada.
 * - Duraciones: solo con los dos horarios necesarios y en orden; si falta
 *   uno, «Sin registrar» (null), nunca un valor inventado.
 */
class AttendanceTimes
{
    /** Salida: hasta cuánto después de terminar la orden (remito) se puede registrar. */
    public const LEAVE_WINDOW_HOURS = 24;

    /** Aviso: hasta cuántos días antes de crear la orden se acepta. */
    public const CALL_MAX_DAYS_BEFORE = 7;

    private function lockedFor(User $user, WorkOrder $order): WorkOrder
    {
        $locked = WorkOrder::withoutGlobalScopes()->where('company_id', $user->company_id)->lockForUpdate()->find($order->id);
        if (! $locked || $locked->trashed() || ! $locked->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['attendance' => 'Esta orden no está asignada a vos.']);
        }

        return $locked;
    }

    public static function canArrive(User $user, WorkOrder $order): bool
    {
        return (int) $order->company_id === (int) $user->company_id && ! $order->trashed() && $order->arrived_at === null
            && $order->status === 'in_progress' && ! $order->deliveryNote()->exists()
            && $order->users()->whereKey($user->id)->exists();
    }

    public static function canLeave(User $user, WorkOrder $order): bool
    {
        return (int) $order->company_id === (int) $user->company_id && ! $order->trashed()
            && $order->arrived_at !== null && $order->left_at === null
            && self::withinLeaveWindow($order)
            && $order->users()->whereKey($user->id)->exists();
    }

    private static function withinLeaveWindow(WorkOrder $order): bool
    {
        return $order->status === 'in_progress'
            || ($order->status === 'completed' && $order->finished_at && $order->finished_at->gt(now()->subHours(self::LEAVE_WINDOW_HOURS)));
    }

    /** «Llegué al edificio». Devuelve [orden, registrada ahora (false = ya estaba)]. */
    public function arrive(User $user, WorkOrder $order): array
    {
        return DB::transaction(function () use ($user, $order) {
            $locked = $this->lockedFor($user, $order);
            if ($locked->arrived_at !== null) {
                return [$locked, false];
            }
            if ($locked->status !== 'in_progress' || $locked->deliveryNote()->exists()) {
                throw ValidationException::withMessages(['attendance' => $locked->status === 'pending'
                    ? 'Primero tomá el trabajo.'
                    : 'Esta orden ya está cerrada: no se puede registrar la llegada.']);
            }
            $locked->forceFill(['arrived_at' => now(), 'arrived_by' => $user->id])->save();

            return [$locked, true];
        });
    }

    /** «Terminé y me retiro». No cierra la orden ni toca el remito. */
    public function leave(User $user, WorkOrder $order): array
    {
        return DB::transaction(function () use ($user, $order) {
            $locked = $this->lockedFor($user, $order);
            if ($locked->left_at !== null) {
                return [$locked, false];
            }
            if ($locked->arrived_at === null) {
                throw ValidationException::withMessages(['attendance' => 'Primero registrá la llegada al edificio.']);
            }
            if (! self::withinLeaveWindow($locked)) {
                throw ValidationException::withMessages(['attendance' => 'Ya no se puede registrar la salida de esta orden.']);
            }
            $locked->forceFill(['left_at' => now(), 'left_by' => $user->id])->save();

            return [$locked, true];
        });
    }

    /**
     * Valida una hora de aviso cargada a mano: ni en el futuro, ni más de
     * CALL_MAX_DAYS_BEFORE días antes de la orden, ni después de la llegada.
     */
    public static function validateCall(?CarbonInterface $at, ?WorkOrder $order = null, string $field = 'call_received_at'): void
    {
        if ($at === null) {
            return;
        }
        $reference = $order?->created_at ?? now();
        $message = match (true) {
            $at->gt(now()->addMinute()) => 'La hora del aviso no puede ser futura.',
            $at->lt($reference->copy()->subDays(self::CALL_MAX_DAYS_BEFORE)) => 'La hora del aviso es de hace más de '.self::CALL_MAX_DAYS_BEFORE.' días: revisala.',
            $order?->arrived_at !== null && $at->gt($order->arrived_at) => 'El aviso no puede ser después de la llegada al edificio ('.$order->arrived_at->format('d/m H:i').').',
            default => null,
        };
        if ($message) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * Carga o corrige la hora del aviso (oficina en el panel). Registra quién.
     * null = sin registrar.
     */
    public function recordCall(User $actor, WorkOrder $order, ?CarbonInterface $at, string $source = 'office'): void
    {
        self::validateCall($at, $order);
        $order->forceFill([
            'call_received_at' => $at,
            'call_source' => $at ? $source : null,
            'call_recorded_by' => $at ? $actor->id : null,
        ])->save();
    }

    /**
     * Lo que se muestra: los tres horarios (o null = «Sin registrar») y las
     * tres duraciones en minutos (null si falta un horario o están al revés).
     *
     * @return array{call: ?Carbon, call_source: ?string, call_by: ?string, arrived: ?Carbon, arrived_by: ?string, left: ?Carbon, left_by: ?string, response: ?int, on_site: ?int, total: ?int}
     */
    public static function summary(WorkOrder $order): array
    {
        return [
            'call' => $order->call_received_at,
            'call_source' => WorkOrder::CALL_SOURCES[$order->call_source] ?? null,
            'call_by' => $order->callRecordedBy?->name,
            'arrived' => $order->arrived_at,
            'arrived_by' => $order->arrivedBy?->name,
            'left' => $order->left_at,
            'left_by' => $order->leftBy?->name,
        ] + self::durations($order);
    }

    /**
     * Solo las duraciones, en minutos (sin consultar quién registró cada hora: sirve para listados).
     *
     * @return array{response: ?int, on_site: ?int, total: ?int}
     */
    public static function durations(WorkOrder $order): array
    {
        $minutes = fn (?CarbonInterface $from, ?CarbonInterface $to) => $from && $to && $to->gte($from) ? (int) floor($from->diffInSeconds($to) / 60) : null;

        return [
            'response' => $minutes($order->call_received_at, $order->arrived_at),
            'on_site' => $minutes($order->arrived_at, $order->left_at),
            'total' => $minutes($order->call_received_at, $order->left_at),
        ];
    }

    /** «50 min», «1 h 30 min», «Sin registrar». */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return 'Sin registrar';
        }
        if ($minutes < 60) {
            return $minutes.' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h.' h'.($m ? ' '.$m.' min' : '');
    }

    /** Hora para mostrar (día/mes y hora, en la zona de la app) o «Sin registrar». */
    public static function time(?CarbonInterface $at): string
    {
        return $at ? $at->copy()->timezone(config('app.timezone'))->format('d/m H:i') : 'Sin registrar';
    }
}
