<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SubscriptionPlan::updateOrCreate(
            ['slug' => 'basic'],
            [
                'name' => 'Básico',
                'price' => 120000,
                'currency' => 'ARS',
                'mercadopago_plan_id' => null,
                'features' => [
                    // Agregaremos las funciones reales cuando definamos las limitaciones del plan.
                ],
                'is_active' => true,
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'professional'],
            [
                'name' => 'Profesional',
                'price' => 250000,
                'currency' => 'ARS',
                'mercadopago_plan_id' => null,
                'features' => [
                    // Agregaremos las funciones reales cuando definamos las limitaciones del plan.
                ],
                'is_active' => true,
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'premium'],
            [
                'name' => 'Premium',
                'price' => 350000,
                'currency' => 'ARS',
                'mercadopago_plan_id' => null,
                'features' => [
                    // Agregaremos las funciones reales cuando definamos las limitaciones del plan.
                ],
                'is_active' => true,
            ]
        );
    }
}
