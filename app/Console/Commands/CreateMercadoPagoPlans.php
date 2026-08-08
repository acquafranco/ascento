<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Illuminate\Console\Command;

class CreateMercadoPagoPlans extends Command
{
    protected $signature = 'mercadopago:create-plans';

    protected $description = 'Crea en Mercado Pago los planes de suscripción que existen en la aplicación.';

    public function handle(MercadoPagoService $mercadoPago): int
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();

        if ($plans->isEmpty()) {
            $this->error('No hay planes activos en subscription_plans.');

            return self::FAILURE;
        }

        foreach ($plans as $plan) {
            if ($plan->mercadopago_plan_id) {
                $this->line(
                    "{$plan->name}: ya tiene Mercado Pago ID {$plan->mercadopago_plan_id}. Se omite."
                );

                continue;
            }

            $this->info(
                "Creando plan {$plan->name} por {$plan->price} {$plan->currency}..."
            );

            try {
                $response = $mercadoPago->createSubscriptionPlan([
                    'reason' => 'Ascento - ' . $plan->name,
                    'auto_recurring' => [
                        'frequency' => 1,
                        'frequency_type' => 'months',
                        'transaction_amount' => (float) $plan->price,
                        'currency_id' => $plan->currency,
                    ],
                    'back_url' => config('app.url'),
                ]);
            } catch (\Throwable $e) {
                $this->error(
                    "Error creando {$plan->name}: {$e->getMessage()}"
                );

                continue;
            }

            $mercadoPagoPlanId = $response['id'] ?? null;

            if (!$mercadoPagoPlanId) {
                $this->error(
                    "Mercado Pago no devolvió un ID para {$plan->name}."
                );

                continue;
            }

            $plan->update([
                'mercadopago_plan_id' => $mercadoPagoPlanId,
            ]);

            $this->info(
                "✓ {$plan->name} creado. ID: {$mercadoPagoPlanId}"
            );
        }

        $this->newLine();
        $this->info('Proceso finalizado.');

        return self::SUCCESS;
    }
}
