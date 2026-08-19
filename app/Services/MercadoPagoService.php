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
        $this->accessToken = config(
            'services.mercadopago.access_token'
        );

        if (empty($this->accessToken)) {
            throw new RuntimeException(
                'MERCADOPAGO_ACCESS_TOKEN no está configurado.'
            );
        }
    }

    public function createSubscription(array $data): array
    {
        return $this->request(
            'post',
            '/preapproval',
            $data
        );
    }

    public function createSubscriptionPlan(array $data): array
    {
        return $this->request(
            'post',
            '/preapproval_plan',
            $data
        );
    }

    public function updateSubscriptionPlan(
        string $planId,
        array $data
    ): array {
        return $this->request(
            'put',
            '/preapproval_plan/' . $planId,
            $data
        );
    }

    public function getUser(): array
    {
        return $this->request(
            'get',
            '/users/me'
        );
    }

    public function getSubscription(
        string $subscriptionId
    ): array {
        return $this->request(
            'get',
            '/preapproval/' . $subscriptionId
        );
    }

    public function syncSubscription(
        string $subscriptionId
    ): array {
        return $this->getSubscription(
            $subscriptionId
        );
    }

    public function getAuthorizedPayment(
        string $paymentId
    ): array {
        return $this->request(
            'get',
            '/authorized_payments/' . $paymentId
        );
    }

    public function getSubscriptionPlan(
        string $planId
    ): array {
        return $this->request(
            'get',
            '/preapproval_plan/' . $planId
        );
    }

    public function searchSubscriptionPlans(
        array $params = []
    ): array {
        return $this->request(
            'get',
            '/preapproval_plan/search',
            $params
        );
    }

    public function searchSubscriptions(
        array $params = []
    ): array {
        return $this->request(
            'get',
            '/preapproval/search',
            $params
        );
    }

    /**
     * Cancela una suscripción de Mercado Pago.
     *
     * IMPORTANTE:
     * Primero consulta el estado real.
     *
     * Si ya está cancelada, devuelve la suscripción
     * sin intentar hacer otro PUT.
     */
    public function cancelSubscription(
        string $subscriptionId
    ): array {
        $subscription = $this->getSubscription(
            $subscriptionId
        );

        $status = $subscription['status'] ?? null;

        /*
         * Mercado Pago ya la canceló.
         */
        if (in_array($status, [
            'cancelled',
            'canceled',
        ], true)) {
            return $subscription;
        }

        /*
         * Ya está pausada.
         * No la tratamos como cancelada.
         */
        if ($status === 'paused') {
            throw new RuntimeException(
                'La suscripción de Mercado Pago está pausada.'
            );
        }

        /*
         * Solicitamos la cancelación.
         */
        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'canceled',
            ]
        );
    }

    public function pauseSubscription(
        string $subscriptionId
    ): array {
        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'paused',
            ]
        );
    }

    public function resumeSubscription(
        string $subscriptionId
    ): array {
        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'authorized',
            ]
        );
    }

    private function request(
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $request = Http::withToken(
            $this->accessToken
        )
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
