<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * API de Mercado Pago simulada: cada test arma el "estado real" que
 * devolvería Mercado Pago y Ascento tiene que reflejar SOLO eso.
 */
trait InteractsWithMercadoPago
{
    protected const MP_SECRET = 'webhook-secret-de-prueba';

    /** @var array<string, array> path => respuesta */
    protected array $mpApi = [];

    protected function setUpMercadoPago(): void
    {
        config([
            'services.mercadopago.access_token' => 'APP_USR-TEST',
            'services.mercadopago.webhook_secret' => self::MP_SECRET,
            'services.mercadopago.test_payer_email' => null,
        ]);

        SubscriptionPlan::updateOrCreate(['slug' => 'professional'], [
            'name' => 'Ascento',
            'price' => 149000,
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake(['api.mercadopago.com/*' => function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'PUT' && str_starts_with($path, '/preapproval/')) {
                $id = basename($path);
                $this->mpApi["/preapproval/{$id}"]['status'] = $request['status'];

                return Http::response($this->mpApi["/preapproval/{$id}"] ?? []);
            }

            if ($request->method() === 'POST' && $path === '/preapproval') {
                $body = $this->mpApi['POST /preapproval'] ?? ['__status' => 500];
                $status = $body['__status'] ?? 201;
                unset($body['__status']);

                return Http::response($body, $status);
            }

            return isset($this->mpApi[$path])
                ? Http::response($this->mpApi[$path])
                : Http::response(['message' => 'not found'], 404);
        }]);
    }

    protected function mpPreapproval(string $id, Company $company, string $status = 'authorized', array $extra = []): array
    {
        return $this->mpApi["/preapproval/{$id}"] = array_replace_recursive([
            'id' => $id,
            'status' => $status,
            'external_reference' => 'ascento-company-'.$company->id,
            'next_payment_date' => now()->addMonth()->toIso8601String(),
            'auto_recurring' => ['frequency' => 1, 'frequency_type' => 'months', 'transaction_amount' => 149000, 'currency_id' => 'ARS'],
        ], $extra);
    }

    /** Una cuota y su pago real. */
    protected function mpCharge(string $authorizedPaymentId, string $preapprovalId, string $paymentStatus = 'approved', float $amount = 149000, array $paymentExtra = []): void
    {
        $paymentId = 'PAY'.$authorizedPaymentId;

        $this->mpApi["/authorized_payments/{$authorizedPaymentId}"] = [
            'id' => $authorizedPaymentId,
            'preapproval_id' => $preapprovalId,
            'status' => $paymentStatus === 'approved' ? 'processed' : 'recycling',
            'transaction_amount' => $amount,
            'currency_id' => 'ARS',
            'debit_date' => now()->toIso8601String(),
            'payment' => ['id' => $paymentId, 'status' => $paymentStatus],
        ];

        $this->mpApi["/v1/payments/{$paymentId}"] = array_replace([
            'id' => $paymentId,
            'status' => $paymentStatus,
            'status_detail' => $paymentStatus === 'approved' ? 'accredited' : 'cc_rejected_insufficient_amount',
            'transaction_amount' => $amount,
            'currency_id' => 'ARS',
            'date_approved' => $paymentStatus === 'approved' ? now()->toIso8601String() : null,
        ], $paymentExtra);
    }

    protected function mpSubscription(Company $company, string $preapprovalId, array $attributes = []): Subscription
    {
        return Subscription::create([
            'company_id' => $company->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => $preapprovalId,
            'external_reference' => 'ascento-company-'.$company->id,
            'plan' => 'professional',
            'status' => Subscription::PENDING,
            'amount' => 149000,
            'currency' => 'ARS',
            ...$attributes,
        ]);
    }

    /** Webhook firmado como lo firma Mercado Pago. */
    protected function mpWebhook(string $type, string $dataId, ?string $secret = self::MP_SECRET, array $body = []): TestResponse
    {
        $requestId = 'req-'.uniqid();
        $ts = (string) now()->getTimestampMs();
        $manifest = 'id:'.(ctype_alnum($dataId) ? strtolower($dataId) : $dataId).";request-id:{$requestId};ts:{$ts};";

        $headers = ['x-request-id' => $requestId];

        if ($secret !== null) {
            $headers['x-signature'] = "ts={$ts},v1=".hash_hmac('sha256', $manifest, $secret);
        }

        return $this->withHeaders($headers)->postJson(
            "/api/mercadopago/webhook?data.id={$dataId}&type={$type}",
            $body ?: ['type' => $type, 'data' => ['id' => $dataId]],
        );
    }

    protected function mpRequests(string $method, string $pathPrefix): int
    {
        return Http::recorded()
            ->filter(fn ($pair) => $pair[0]->method() === $method
                && str_starts_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), $pathPrefix))
            ->count();
    }
}
