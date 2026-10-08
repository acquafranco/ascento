<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\MercadoPagoService;
use App\Services\MercadoPagoSubscriptionSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook de Mercado Pago (POST /api/mercadopago/webhook).
 *
 * 1. Valida la firma (x-signature, HMAC-SHA256 con la clave secreta del
 *    webhook). En producción, sin clave configurada se rechaza todo.
 * 2. Nunca usa el body para decidir el estado: con el id consulta la API de
 *    Mercado Pago (MercadoPagoSubscriptionSync), que además verifica empresa
 *    e importe.
 * 3. Responde 200 rápido; ante errores de la API responde 500 para que
 *    Mercado Pago reintente.
 */
class MercadoPagoWebhookController extends Controller
{
    private const PREAPPROVAL_TOPICS = ['subscription_preapproval', 'preapproval'];

    private const PAYMENT_TOPICS = ['subscription_authorized_payment', 'authorized_payment'];

    public function __invoke(Request $request, MercadoPagoSubscriptionSync $sync): JsonResponse
    {
        $topic = (string) ($request->query('type') ?? $request->input('type') ?? $request->query('topic') ?? $request->input('topic') ?? '');
        $dataId = $this->dataId($request);

        $event = WebhookEvent::create([
            'provider' => 'mercadopago',
            'topic' => mb_substr($topic, 0, 60) ?: null,
            'resource_id' => $dataId !== null ? mb_substr($dataId, 0, 255) : null,
            'request_id' => mb_substr((string) $request->header('x-request-id'), 0, 255) ?: null,
        ]);

        if (! $this->signatureIsValid($request, $dataId)) {
            $event->update(['result' => 'invalid_signature']);

            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $event->update(['signature_valid' => true]);

        if ($dataId === null || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $dataId)) {
            return $this->finish($event, 'ignored');
        }

        if (! MercadoPagoService::isConfigured()) {
            Log::error('Webhook de Mercado Pago recibido sin MERCADOPAGO_ACCESS_TOKEN configurado.');

            return $this->finish($event, 'not_configured', 503);
        }

        try {
            $result = match (true) {
                in_array($topic, self::PREAPPROVAL_TOPICS, true) => $sync->syncPreapproval($dataId),
                in_array($topic, self::PAYMENT_TOPICS, true) => $sync->syncAuthorizedPayment($dataId),
                default => 'ignored',
            };
        } catch (Throwable $e) {
            report($e);

            return $this->finish($event, 'error', 500);
        }

        return $this->finish($event, $result);
    }

    private function finish(WebhookEvent $event, string $result, int $status = 200): JsonResponse
    {
        $event->update(['result' => $result, 'processed_at' => now()]);

        return response()->json(['status' => $result], $status);
    }

    /**
     * El id del recurso: ?data.id= (PHP lo convierte en data_id) o el body.
     */
    private function dataId(Request $request): ?string
    {
        $id = $request->query('data_id')
            ?? $request->input('data.id')
            ?? $request->query('id');

        if ($id === null && preg_match('/(?:^|&)data\.id=([^&]+)/', (string) $request->server('QUERY_STRING'), $m)) {
            $id = urldecode($m[1]);
        }

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /**
     * Firma oficial de Mercado Pago:
     *   x-signature: ts=<ts>,v1=<hmac>
     *   manifest:    id:<data.id>;request-id:<x-request-id>;ts:<ts>;
     *   v1 = HMAC-SHA256(manifest, clave secreta) en hexadecimal.
     * (data.id alfanumérico va en minúsculas; las partes ausentes se omiten.)
     */
    private function signatureIsValid(Request $request, ?string $dataId): bool
    {
        $secret = (string) config('services.mercadopago.webhook_secret');

        if ($secret === '') {
            if (app()->isProduction()) {
                Log::error('Webhook de Mercado Pago rechazado: falta MERCADOPAGO_WEBHOOK_SECRET.');

                return false;
            }

            // Fuera de producción se acepta sin firma: el estado igual se
            // consulta siempre a la API, así que un body falso no cambia nada.
            return true;
        }

        $parts = [];
        foreach (explode(',', (string) $request->header('x-signature')) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[trim($key)] = trim($value);
            }
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;

        if (! $ts || ! $v1) {
            return false;
        }

        $manifest = '';
        if ($dataId !== null) {
            $manifest .= 'id:'.(ctype_alnum($dataId) ? strtolower($dataId) : $dataId).';';
        }
        if ($requestId = $request->header('x-request-id')) {
            $manifest .= 'request-id:'.$requestId.';';
        }
        $manifest .= 'ts:'.$ts.';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($v1));
    }
}
