<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoApiException;
use App\Services\MercadoPagoService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnóstico de la integración con Mercado Pago (no cobra ni crea nada).
 */
class CheckMercadoPago extends Command
{
    protected $signature = 'mercadopago:check';

    protected $description = 'Revisa credenciales, cuenta, webhook y plan de Mercado Pago y explica qué falta.';

    private int $problems = 0;

    public function handle(): int
    {
        $this->problems = 0;
        $token = (string) config('services.mercadopago.access_token');
        $testPayer = (string) config('services.mercadopago.test_payer_email');

        if (app()->configurationIsCached()) {
            $this->warn('• La configuración está cacheada: si cambiaste el .env, corré "php artisan config:clear" (o volvé a cachear).');
        }

        if ($token === '') {
            return $this->stop('Falta MERCADOPAGO_ACCESS_TOKEN.');
        }

        $this->line('• Access token: '.(str_starts_with($token, 'TEST-') ? 'de PRUEBA (TEST-…)' : 'APP_USR-…').' ****'.substr($token, -4));

        try {
            $me = app(MercadoPagoService::class)->me();
        } catch (MercadoPagoApiException $e) {
            return $this->stop('Mercado Pago rechazó el token: '.$e->hint());
        } catch (Throwable $e) {
            return $this->stop('No se pudo conectar con Mercado Pago: '.$e->getMessage());
        }

        $isTestUser = in_array('test_user', (array) ($me['tags'] ?? []), true);

        $this->info('• Cuenta: '.($me['nickname'] ?? '?').' (id '.($me['id'] ?? '?').', país '.($me['site_id'] ?? '?').')'.($isTestUser ? ' — USUARIO DE PRUEBA' : ''));

        if (($me['site_id'] ?? null) !== 'MLA') {
            $this->problem('La cuenta no es de Argentina (MLA): las suscripciones en ARS no van a funcionar.');
        }

        if ($isTestUser && $testPayer === '') {
            $this->problem('Las credenciales son de un usuario de PRUEBA: completá MERCADOPAGO_TEST_PAYER_EMAIL con el email del usuario de prueba COMPRADOR.');
        }

        if (! $isTestUser && $testPayer !== '') {
            $this->problem('Credenciales reales con MERCADOPAGO_TEST_PAYER_EMAIL cargado: en producción dejalo vacío.');
        }

        if ($testPayer !== '' && isset($me['email']) && strcasecmp($testPayer, $me['email']) === 0) {
            $this->problem('MERCADOPAGO_TEST_PAYER_EMAIL es el email del VENDEDOR: tiene que ser el del comprador de prueba.');
        }

        if (blank(config('services.mercadopago.webhook_secret'))) {
            $this->problem('Falta MERCADOPAGO_WEBHOOK_SECRET (Tus integraciones → Webhooks → Clave secreta). En producción, sin ella se rechazan los avisos.');
        } else {
            $this->line('• Clave secreta del webhook: configurada');
        }

        $appUrl = (string) config('app.url');
        if (! str_starts_with($appUrl, 'https://')) {
            $this->problem("APP_URL es \"{$appUrl}\": Mercado Pago necesita una URL pública con https para volver a Ascento.");
        }

        $this->line('• URL del webhook a configurar en Mercado Pago: '.rtrim($appUrl, '/').'/api/mercadopago/webhook (evento: Planes y suscripciones)');

        $plans = SubscriptionPlan::offered();
        if ($plans->isEmpty()) {
            $this->problem('No hay planes activos en subscription_plans (correr las migraciones o el seeder SubscriptionPlanSeeder).');
        }
        foreach ($plans as $plan) {
            $this->line("• Plan {$plan->name}: {$plan->currency} ".number_format((float) $plan->price, 0, ',', '.').'/mes');
        }

        if ($this->problems === 0) {
            $this->info('Todo en orden.');
        }

        return $this->problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function problem(string $message): void
    {
        $this->problems++;
        $this->error('✗ '.$message);
    }

    private function stop(string $message): int
    {
        $this->error('✗ '.$message);

        return self::FAILURE;
    }
}
