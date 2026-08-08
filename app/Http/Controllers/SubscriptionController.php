<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MercadoPagoService
{
    protected string $baseUri;
    protected string $accessToken;

    public function __construct()
    {
        $this->baseUri = config('services.mercadopago.base_uri');
        $this->accessToken = config('services.mercadopago.token');
    }

    protected function request(string $method, string $uri, array $data = []): array
    {
        $response = Http::withToken($this->accessToken)
            ->$method($this->baseUri . $uri, $data);

        if ($response->failed()) {
            throw new \RuntimeException('MercadoPago API request failed: ' . $response->body());
        }

        return $response->json();
    }

    public function createSubscriptionPlan(array $data): array
    {
        return $this->request(
            'post',
            '/preapproval_plan',
            $data
        );
    }

    public function getSubscriptionPlan(string $planId): array
    {
        return $this->request(
            'get',
            '/preapproval_plan/' . $planId
        );
    }

    public function createSubscription(array $data): array
    {
        return $this->request(
            'post',
            '/preapproval',
            $data
        );
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request(
            'post',
            "/preapproval/{$subscriptionId}/cancel"
        );
    }
}
