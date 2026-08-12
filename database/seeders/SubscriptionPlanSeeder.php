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
                'mercadopago_plan_id' => 'daab3c56fb194b7699e8d12d864fc995',
                'features' => [
                    'Prueba gratis de 15 días',
                ],
                'is_active' => true,
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'professional'],
            [
                'name' => 'Profesional',
                'price' => 260000,
                'currency' => 'ARS',
                'mercadopago_plan_id' => '294432a6b72d41a7979aacc4ab89a79b',
                'features' => [
                    'Prueba gratis de 15 días',
                ],
                'is_active' => true,
            ]
        );
    }
}
