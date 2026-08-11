<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoService
{
    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');

        if (empty($this->accessToken)) {
            throw new RuntimeException(
                'MERCADOPAGO_ACCESS_TOKEN no está configurado.'
            );
        }
    }

    /**
     * Crea una suscripción en Mercado Pago.
     */
    public function createSubscription(array $data): array
    {
        return $this->request('post', '/preapproval', $data);
    }

    /**
     * Crea un plan de suscripción en Mercado Pago.
     */
    public function createSubscriptionPlan(array $data): array
    {
        return $this->request('post', '/preapproval_plan', $data);
    }

    /**
     * Obtiene una suscripción por su ID.
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->request(
            'get',
            '/preapproval/' . $subscriptionId
        );
    }

    public function getSubscriptionPlan(string $planId): array
{
    return $this->request(
        'get',
        '/preapproval_plan/' . $planId
    );
}

    /**
     * Obtiene una suscripción desde Mercado Pago para sincronizar su estado.
     */
    public function syncSubscription(string $subscriptionId): array
    {
        return $this->getSubscription($subscriptionId);
    }

    /**
     * Cancela una suscripción.
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'canceled',
            ]
        );
    }

    /**
     * Pausa una suscripción.
     */
    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'paused',
            ]
        );
    }

    /**
     * Reactiva una suscripción.
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'authorized',
            ]
        );
    }

    /**
     * Realiza una petición autenticada contra Mercado Pago.
     */
    private function request(
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $request = Http::withToken($this->accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout(15);

        $response = match (strtolower($method)) {
            'get' => $request->get(
                $this->baseUrl . $endpoint,
                $data
            ),

            'post' => $request->post(
                $this->baseUrl . $endpoint,
                $data
            ),

            'put' => $request->put(
                $this->baseUrl . $endpoint,
                $data
            ),

            default => throw new RuntimeException(
                "Método HTTP no soportado: {$method}"
            ),
        };

        if ($response->failed()) {
            throw new RuntimeException(
                'Mercado Pago respondió con error: '
                . $response->status()
                . ' - '
                . $response->body()
            );
        }

        return $response->json();
    }
}
