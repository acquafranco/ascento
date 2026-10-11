<?php

namespace App\Support;

use App\Events\CountersChanged;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Junta, durante el request, a quiénes les cambiaron los contadores (los
 * admins de la empresa y los técnicos involucrados) y avisa UNA vez al final.
 * Así cinco cambios en el mismo request son un solo evento por persona.
 */
class RealtimeCounters
{
    /** @var array<int, true> */
    private static array $users = [];

    /** @var array<int, true> */
    private static array $companies = [];

    /** @var array<int, true> Órdenes tocadas: sus técnicos se resuelven al final (pueden asignarse después de crearla). */
    private static array $workOrders = [];

    private static bool $registered = false;

    /** @param iterable<int|null> $technicianIds */
    public static function touch(?int $companyId, iterable $technicianIds = []): void
    {
        if (! Realtime::enabled()) {
            return;
        }

        if ($companyId) {
            static::$companies[$companyId] = true;
        }
        foreach ($technicianIds as $id) {
            if ($id) {
                static::$users[(int) $id] = true;
            }
        }

        static::register();
    }

    public static function touchWorkOrder(WorkOrder $order): void
    {
        if (! Realtime::enabled()) {
            return;
        }

        static::$companies[(int) $order->company_id] = true;
        static::$workOrders[(int) $order->id] = true;
        static::register();
    }

    private static function register(): void
    {
        if (! static::$registered) {
            static::$registered = true;
            app()->terminating(fn () => static::flush());
        }
    }

    public static function flush(): void
    {
        $users = static::$users;
        $companies = array_keys(static::$companies);
        $orders = array_keys(static::$workOrders);
        static::$users = static::$companies = static::$workOrders = [];
        static::$registered = false;

        if ($orders !== []) {
            DB::table('work_order_user')->whereIn('work_order_id', $orders)->pluck('user_id')
                ->merge(DB::table('work_order_participants')->whereIn('work_order_id', $orders)->pluck('user_id'))
                ->each(function ($id) use (&$users) {
                    $users[(int) $id] = true;
                });
        }

        if ($companies !== []) {
            User::whereIn('company_id', $companies)->where('role', 'admin')->pluck('id')
                ->each(function ($id) use (&$users) {
                    $users[(int) $id] = true;
                });
        }

        if ($users === []) {
            return;
        }

        try {
            broadcast(new CountersChanged(array_keys($users)));
        } catch (Throwable $e) {
            // Sin Reverb la app sigue igual: las pantallas se ponen al día al reconectar.
            Log::warning('No se pudo avisar el cambio de contadores', ['exception' => $e::class]);
        }
    }
}
