<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoService
{
    private string $accessToken;

    private string $baseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->accessToken = (string) config('services.mercadopago.access_token');

        if (empty($this->accessToken)) {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN no está configurado.');
        }
    }

    /**
     * Crea una suscripción individual en Mercado Pago.
     *
     * Endpoint: POST /preapproval
     */
    public function createSubscription(array $data): array
    {
        return $this->request('post', '/preapproval', $data);
    }

    public function createSubscriptionPlan(array $data): array
    {
        return $this->request('post', '/preapproval_plan', $data);
    }

    public function updateSubscriptionPlan(string $planId, array $data): array
    {
        return $this->request('put', '/preapproval_plan/' . $planId, $data);
    }

    public function getUser(): array
    {
        return $this->request('get', '/users/me');
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('get', '/preapproval/' . $subscriptionId);
    }

    /**
     * Alias de getSubscription().
     */
    public function syncSubscription(string $subscriptionId): array
    {
        return $this->getSubscription($subscriptionId);
    }

    public function getAuthorizedPayment(string $paymentId): array
    {
        return $this->request('get', '/authorized_payments/' . $paymentId);
    }

    public function getSubscriptionPlan(string $planId): array
    {
        return $this->request('get', '/preapproval_plan/' . $planId);
    }

    public function searchSubscriptionPlans(array $params = []): array
    {
        return $this->request('get', '/preapproval_plan/search', $params);
    }

    public function searchSubscriptions(array $params = []): array
    {
        return $this->request('get', '/preapproval/search', $params);
    }

    /**
     * Alias para buscar suscripciones.
     */
    public function findSubscriptions(array $params = []): array
    {
        return $this->searchSubscriptions($params);
    }

    /**
     * Cancela definitivamente una suscripción.
     *
     * IMPORTANTE: paused != canceled. Una suscripción cancelada
     * definitivamente no se reutiliza.
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        $subscription = $this->getSubscription($subscriptionId);

        $status = $subscription['status'] ?? null;

        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return $subscription;
        }

        if ($status === 'paused') {
            // Mercado Pago sí permite cancelar desde "paused"; lo
            // dejamos pasar en vez de bloquearlo artificialmente.
            return $this->request('put', '/preapproval/' . $subscriptionId, [
                'status' => 'cancelled',
            ]);
        }

        if (!in_array($status, ['authorized', 'active', 'trialing', 'past_due'], true)) {
            throw new RuntimeException(
                'No se puede cancelar la suscripción. Estado actual de Mercado Pago: '
                . ($status ?? 'desconocido')
            );
        }

        return $this->request('put', '/preapproval/' . $subscriptionId, [
            'status' => 'cancelled',
        ]);
    }

    /**
     * Pausa una suscripción. Puede reanudarse luego con el mismo ID.
     */
    public function pauseSubscription(string $subscriptionId): array
    {
        $subscription = $this->getSubscription($subscriptionId);

        $status = $subscription['status'] ?? null;

        if ($status === 'paused') {
            return $subscription;
        }

        if (in_array($status, ['cancelled', 'canceled'], true)) {
            throw new RuntimeException('Una suscripción cancelada no puede pausarse.');
        }

        if (!in_array($status, ['authorized', 'active', 'trialing', 'past_due'], true)) {
            throw new RuntimeException(
                'No se puede pausar la suscripción. Estado actual de Mercado Pago: '
                . ($status ?? 'desconocido')
            );
        }

        return $this->request('put', '/preapproval/' . $subscriptionId, [
            'status' => 'paused',
        ]);
    }

    /**
     * Reactiva una suscripción pausada. NO sirve para canceladas.
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        $subscription = $this->getSubscription($subscriptionId);

        $status = $subscription['status'] ?? null;

        if (in_array($status, ['cancelled', 'canceled'], true)) {
            throw new RuntimeException(
                'La suscripción está cancelada en Mercado Pago y no puede reanudarse. Debe crearse una nueva.'
            );
        }

        if (in_array($status, ['authorized', 'active'], true)) {
            return $subscription;
        }

        if ($status !== 'paused') {
            throw new RuntimeException(
                'No se puede reanudar la suscripción. Estado actual de Mercado Pago: '
                . ($status ?? 'desconocido')
            );
        }

        return $this->request('put', '/preapproval/' . $subscriptionId, [
            'status' => 'authorized',
        ]);
    }

    /**
     * Realiza una petición a Mercado Pago.
     *
     * Las lecturas (GET) reintentan una vez ante fallos transitorios de
     * red; las escrituras (POST/PUT) NO reintentan solas para no arriesgar
     * duplicar operaciones (por ejemplo, dos preapproval por un timeout).
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $method = strtolower($method);

        try {
            $request = $this->buildRequest($method);

            $response = match ($method) {
                'get' => $request->get($this->baseUrl . $endpoint, $data),
                'post' => $request->post($this->baseUrl . $endpoint, $data),
                'put' => $request->put($this->baseUrl . $endpoint, $data),
                default => throw new RuntimeException("Método HTTP no soportado: {$method}"),
            };
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                'No se pudo conectar con Mercado Pago. Intentá nuevamente en unos segundos.',
                previous: $e
            );
        }

        if ($response->failed()) {
            throw new RuntimeException($this->extractErrorMessage($response));
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException('Mercado Pago devolvió una respuesta inválida.');
        }

        return $json;
    }

    private function buildRequest(string $method): PendingRequest
    {
        $request = Http::withToken($this->accessToken)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(15);

        // Solo las lecturas son seguras de reintentar automáticamente.
        if ($method === 'get') {
            $request = $request->retry(2, 300);
        }

        return $request;
    }

    /**
     * Mercado Pago normalmente devuelve algo como:
     * { "message": "...", "error": "...", "status": 400, "cause": [...] }
     * Intentamos mostrar ese mensaje en vez del body crudo.
     */
    private function extractErrorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $json = $response->json();

        $detail = $json['message']
            ?? $json['error']
            ?? (isset($json['cause'][0]['description']) ? $json['cause'][0]['description'] : null)
            ?? $response->body();

        return 'Mercado Pago respondió con error: ' . $response->status() . ' - ' . $detail;
    }
}
