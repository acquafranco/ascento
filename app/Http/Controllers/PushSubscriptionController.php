<?php

namespace App\Http\Controllers;

use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Alta/baja de las suscripciones Web Push del técnico (una por dispositivo).
 *
 * Vive dentro del grupo /{empresa} (auth + empresa + suscripción vigente):
 * la suscripción siempre queda a nombre del usuario autenticado; el request
 * nunca elige a quién pertenece.
 */
class PushSubscriptionController extends Controller
{
    public const SESSION_KEY = 'push_endpoint';

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->canReceivePush(), 403, 'Esta cuenta no recibe notificaciones.');

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024', 'url:https', $this->allowedHostRule()],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);

        // Si el endpoint era de otra cuenta (celular compartido), el paquete
        // lo borra y lo crea a nombre de este usuario.
        $user->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? 'aes128gcm',
        );

        // Para darlo de baja al cerrar sesión en este dispositivo.
        $request->session()->put(self::SESSION_KEY, $data['endpoint']);

        return response()->json([
            'status' => 'subscribed',
            'devices' => $user->pushSubscriptions()->count(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
        ]);

        // Solo borra suscripciones propias (deletePushSubscription filtra por usuario).
        $request->user()->deletePushSubscription($data['endpoint']);

        if ($request->session()->get(self::SESSION_KEY) === $data['endpoint']) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return response()->json(['status' => 'unsubscribed']);
    }

    /**
     * "Enviar prueba": manda un push a los dispositivos del propio usuario.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->canReceivePush(), 403);

        $devices = $user->pushSubscriptions()->count();

        if ($devices > 0) {
            $user->notify(new TestPushNotification);
        }

        return response()->json([
            'devices' => $user->pushSubscriptions()->count(),
        ]);
    }

    private function allowedHostRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

            foreach ((array) config('webpush.allowed_endpoint_hosts', []) as $pattern) {
                if (Str::is(strtolower($pattern), $host)) {
                    return;
                }
            }

            $fail('Este navegador usa un servicio de notificaciones no soportado.');
        };
    }
}
