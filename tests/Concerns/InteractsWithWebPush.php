<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Web Push de punta a punta sin red: claves VAPID y de dispositivo reales
 * (el payload se cifra de verdad) y los servicios de push simulados con
 * Http::fake (el paquete entrega a través del cliente HTTP de Laravel).
 */
trait InteractsWithWebPush
{
    protected function configureVapid(): void
    {
        $keys = VAPID::createVapidKeys();

        config([
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'],
            'webpush.vapid.subject' => 'mailto:soporte@ascento.test',
        ]);
    }

    /** Respuesta actual de los servicios de push (se cambia con pushServiceResponds). */
    protected \Closure|int $pushServiceResponse = 201;

    /** Simula los servicios de push de los navegadores. */
    protected function fakePushService(): void
    {
        Http::preventStrayRequests();

        $respond = function () {
            $response = $this->pushServiceResponse;

            return $response instanceof \Closure ? $response() : Http::response('', $response);
        };

        Http::fake([
            'fcm.googleapis.com/*' => $respond,
            'web.push.apple.com/*' => $respond,
        ]);
    }

    /** Cambia lo que responden los servicios de push (status HTTP o closure). */
    protected function pushServiceResponds(\Closure|int $response): void
    {
        $this->pushServiceResponse = $response;
    }

    /** Registra un dispositivo (suscripción con claves válidas) para el usuario. */
    protected function subscribeDevice(User $user, string $host = 'fcm.googleapis.com'): PushSubscription
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];

        $publicKey = $this->base64Url("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));

        return $user->updatePushSubscription(
            "https://{$host}/fcm/send/".Str::random(40),
            $publicKey,
            $this->base64Url(random_bytes(16)),
            'aes128gcm',
        );
    }

    /** @return list<string> Endpoints a los que se entregó un push. */
    protected function deliveredEndpoints(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/fcm/send/'))
            ->map(fn (Request $request) => $request->url())
            ->values()
            ->all();
    }

    protected function assertPushDeliveredTo(PushSubscription $subscription): void
    {
        $this->assertContains($subscription->endpoint, $this->deliveredEndpoints(), 'No se entregó el push a ese dispositivo.');
    }

    protected function assertNoPushDeliveredTo(PushSubscription $subscription): void
    {
        $this->assertNotContains($subscription->endpoint, $this->deliveredEndpoints(), 'Se entregó un push que no correspondía.');
    }

    /**
     * Lo despachado "after response" ya corrió: Livewire::test y los requests
     * de test terminan la app igual que un request real. Acá solo se limpian
     * esos callbacks para que un terminate() posterior no los repita (en
     * producción corren una sola vez por request).
     */
    protected function runAfterResponseJobs(): void
    {
        $property = new \ReflectionProperty($this->app, 'terminatingCallbacks');
        $property->setValue($this->app, []);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
