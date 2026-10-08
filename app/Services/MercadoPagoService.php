<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    public static function isConfigured(): bool
    {
        return filled(config('services.mercadopago.access_token'));
    }

    /**
     * Crea la suscripción (preapproval) SIN plan asociado y en estado
     * "pending": Mercado Pago devuelve un init_point donde el cliente carga
     * su medio de pago. Con plan asociado la API exige card_token_id
     * (tokenizar la tarjeta de nuestro lado), que no usamos.
     */
    public function createPreapproval(array $data): array
    {
        return $this->request('post', '/preapproval', $data);
    }

    public function getPreapproval(string $preapprovalId): array
    {
        return $this->request('get', '/preapproval/'.rawurlencode($preapprovalId));
    }

    /** Cancela definitivamente (Mercado Pago no permite reactivar una cancelada). */
    public function cancelPreapproval(string $preapprovalId): array
    {
        return $this->request('put', '/preapproval/'.rawurlencode($preapprovalId), [
            'status' => 'cancelled',
        ]);
    }

    /** Una cuota de la suscripción (topic subscription_authorized_payment). */
    public function getAuthorizedPayment(string $authorizedPaymentId): array
    {
        return $this->request('get', '/authorized_payments/'.rawurlencode($authorizedPaymentId));
    }

    /** Cuotas de una suscripción (para reconciliar si se perdió un webhook). */
    public function searchAuthorizedPayments(string $preapprovalId): array
    {
        return $this->request('get', '/authorized_payments/search', [
            'preapproval_id' => $preapprovalId,
            'limit' => 50,
        ]);
    }

    /** El pago real detrás de una cuota: estado e importe definitivos. */
    public function getPayment(string $paymentId): array
    {
        return $this->request('get', '/v1/payments/'.rawurlencode($paymentId));
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
                'get' => $request->get($this->baseUrl.$endpoint, $data),
                'post' => $request->post($this->baseUrl.$endpoint, $data),
                'put' => $request->put($this->baseUrl.$endpoint, $data),
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

        if (! is_array($json)) {
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
    private function extractErrorMessage(Response $response): string
    {
        $json = $response->json();

        $detail = $json['message']
            ?? $json['error']
            ?? (isset($json['cause'][0]['description']) ? $json['cause'][0]['description'] : null)
            ?? $response->body();

        return 'Mercado Pago respondió con error: '.$response->status().' - '.$detail;
    }
}
