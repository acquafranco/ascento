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
     * Cancela una suscripción existente.
     *
     * IMPORTANTE:
     * Este método consulta primero Mercado Pago.
     *
     * Si ya está cancelada, NO intenta hacer PUT.
     */
    public function cancelSubscription(
        string $subscriptionId
    ): array {
        $subscription = $this->getSubscription(
            $subscriptionId
        );

        $status = $subscription['status'] ?? null;

        if (in_array($status, [
            'cancelled',
            'canceled',
        ], true)) {
            return $subscription;
        }

        if ($status === 'paused') {
            throw new RuntimeException(
                'La suscripción de Mercado Pago está pausada.'
            );
        }

        if (!in_array($status, [
            'authorized',
            'active',
            'trialing',
        ], true)) {
            throw new RuntimeException(
                'No se puede cancelar la suscripción. '
                . 'Estado actual de Mercado Pago: '
                . ($status ?? 'desconocido')
            );
        }

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
     *
     * IMPORTANTE:
     * Pausar NO es lo mismo que cancelar.
     *
     * Una suscripción pausada puede volver a authorized.
     */
    public function pauseSubscription(
        string $subscriptionId
    ): array {
        $subscription = $this->getSubscription(
            $subscriptionId
        );

        $status = $subscription['status'] ?? null;

        if ($status === 'paused') {
            return $subscription;
        }

        if (in_array($status, [
            'cancelled',
            'canceled',
        ], true)) {
            throw new RuntimeException(
                'Una suscripción cancelada no puede pausarse.'
            );
        }

        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'paused',
            ]
        );
    }

    /**
     * Reanuda una suscripción PAUSADA.
     *
     * NO utilizar para una suscripción cancelada.
     */
    public function resumeSubscription(
        string $subscriptionId
    ): array {
        $subscription = $this->getSubscription(
            $subscriptionId
        );

        $status = $subscription['status'] ?? null;

        if ($status === 'authorized') {
            return $subscription;
        }

        if (in_array($status, [
            'cancelled',
            'canceled',
        ], true)) {
            throw new RuntimeException(
                'La suscripción está cancelada en Mercado Pago y no puede reanudarse. Debe autorizarse una nueva suscripción.'
            );
        }

        if ($status !== 'paused') {
            throw new RuntimeException(
                'No se puede reanudar la suscripción. '
                . 'Estado actual de Mercado Pago: '
                . ($status ?? 'desconocido')
            );
        }

        return $this->request(
            'put',
            '/preapproval/' . $subscriptionId,
            [
                'status' => 'authorized',
            ]
        );
    }

    /**
     * Busca suscripciones de Mercado Pago.
     *
     * Útil para detectar una suscripción existente
     * antes de crear otra.
     */
    public function findSubscriptions(
        array $params = []
    ): array {
        return $this->searchSubscriptions($params);
    }

    /**
     * Realiza una petición a Mercado Pago.
     */
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

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException(
                'Mercado Pago devolvió una respuesta inválida.'
            );
        }

        return $json;
    }
}
