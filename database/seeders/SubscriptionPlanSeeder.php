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
                'price' => 270000,
                'currency' => 'ARS',
                'mercadopago_plan_id' => '685efb697677431c92fdd7f43f07c2aa',
                'features' => [
                    'Prueba gratis de 30 días',
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
