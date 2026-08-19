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
        $this->accessToken = (string) config(
            'services.mercadopago.access_token'
        );

        if (empty($this->accessToken)) {
            throw new RuntimeException(
                'MERCADOPAGO_ACCESS_TOKEN no está configurado.'
            );
        }
    }

    /**
     * Crea una suscripción individual en Mercado Pago.
     *
     * Endpoint:
     * POST /preapproval
     */
    public function createSubscription(array $data): array
    {
        return $this->request(
            'post',
            '/preapproval',
            $data
        );
    }

    /**
     * Crea un plan de suscripción.
     */
    public function createSubscriptionPlan(array $data): array
    {
        return $this->request(
            'post',
            '/preapproval_plan',
            $data
        );
    }

    /**
     * Actualiza un plan de suscripción.
     */
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

    /**
     * Obtiene información del usuario de Mercado Pago.
     */
    public function getUser(): array
    {
        return $this->request(
            'get',
            '/users/me'
        );
    }

    /**
     * Obtiene una suscripción.
     */
    public function getSubscription(
        string $subscriptionId
    ): array {
        return $this->request(
            'get',
            '/preapproval/' . $subscriptionId
        );
    }

    /**
     * Alias de getSubscription().
     */
    public function syncSubscription(
        string $subscriptionId
    ): array {
        return $this->getSubscription(
            $subscriptionId
        );
    }

    /**
     * Obtiene un pago autorizado.
     */
    public function getAuthorizedPayment(
        string $paymentId
    ): array {
        return $this->request(
            'get',
            '/authorized_payments/' . $paymentId
        );
    }

    /**
     * Obtiene un plan.
     */
    public function getSubscriptionPlan(
        string $planId
    ): array {
        return $this->request(
            'get',
            '/preapproval_plan/' . $planId
        );
    }

    /**
     * Busca planes.
     */
    public function searchSubscriptionPlans(
        array $params = []
    ): array {
        return $this->request(
            'get',
            '/preapproval_plan/search',
            $params
        );
    }

    /**
     * Busca suscripciones.
     */
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
     * Alias para buscar suscripciones.
     */
    public function findSubscriptions(
        array $params = []
    ): array {
        return $this->searchSubscriptions($params);
    }

    /**
     * Cancela definitivamente una suscripción.
     *
     * IMPORTANTE:
     *
     * paused != canceled.
     *
     * Una suscripción cancelada definitivamente
     * no se reutiliza.
     */
    public function cancelSubscription(
        string $subscriptionId
    ): array {
        $subscription = $this->getSubscription(
            $subscriptionId
        );

        $status = $subscription['status'] ?? null;

        if (in_array(
            $status,
            ['cancelled', 'canceled'],
            true
        )) {
            return $subscription;
        }

        if ($status === 'paused') {
            throw new RuntimeException(
                'La suscripción de Mercado Pago está pausada. Reactivala o dejala pausada antes de cancelarla.'
            );
        }

        if (!in_array(
            $status,
            [
                'authorized',
                'active',
                'trialing',
                'past_due',
            ],
            true
        )) {
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
     * Una suscripción pausada puede reanudarse
     * posteriormente utilizando el mismo ID.
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

        if (in_array(
            $status,
            ['cancelled', 'canceled'],
            true
        )) {
            throw new RuntimeException(
                'Una suscripción cancelada no puede pausarse.'
            );
        }

        if (!in_array(
            $status,
            [
                'authorized',
                'active',
                'trialing',
                'past_due',
            ],
            true
        )) {
            throw new RuntimeException(
                'No se puede pausar la suscripción. '
                . 'Estado actual de Mercado Pago: '
                . ($status ?? 'desconocido')
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
     * Reactiva una suscripción pausada.
     *
     * NO sirve para suscripciones canceladas.
     */
    public function resumeSubscription(
        string $subscriptionId
    ): array {
        $subscription = $this->getSubscription(
            $subscriptionId
        );

        $status = $subscription['status'] ?? null;

        if (in_array(
            $status,
            ['cancelled', 'canceled'],
            true
        )) {
            throw new RuntimeException(
                'La suscripción está cancelada en Mercado Pago y no puede reanudarse. Debe crearse una nueva suscripción.'
            );
        }

        if (in_array(
            $status,
            ['authorized', 'active'],
            true
        )) {
            return $subscription;
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
