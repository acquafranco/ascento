<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::updateOrCreate(
            ['slug' => 'professional'],
            [
                'name' => 'Ascento',
                'price' => 260000,
                'currency' => 'ARS',
                'mercadopago_plan_id' => '294432a6b72d41a7979aacc4ab89a79b',
                'features' => [
                    'Prueba gratis de 15 días',
                ],
                'is_active' => true,
            ]
        );

        /*
         * Desactivamos los planes anteriores.
         *
         * No los eliminamos porque pueden existir
         * suscripciones históricas asociadas.
         */
        SubscriptionPlan::where('slug', 'basic')
            ->update(['is_active' => false]);

        SubscriptionPlan::where('slug', 'premium')
            ->update(['is_active' => false]);
    }
}
