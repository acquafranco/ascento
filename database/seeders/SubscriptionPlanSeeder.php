<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Los tres planes vigentes (también los crea la migración
 * 2026_10_13_100000_introduce_three_plans_with_limits). Los planes viejos no
 * se borran: quedan inactivos para el historial.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public const PLANS = [
        SubscriptionPlan::INICIAL => [
            'name' => 'Ascento Inicial',
            'description' => 'Para empresas chicas que quieren ordenar su operación.',
            'price' => 69000,
            'max_buildings' => 20,
            'max_clients' => 50,
            'max_technicians' => 3,
            'max_reports_per_month' => 15,
            'is_recommended' => false,
            'sort_order' => 1,
            'feature_keys' => ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map'],
        ],
        SubscriptionPlan::PROFESIONAL => [
            'name' => 'Ascento Profesional',
            'description' => 'Todo lo que necesita una empresa de mantenimiento en crecimiento.',
            'price' => 119000,
            'max_buildings' => 70,
            'max_clients' => 150,
            'max_technicians' => 10,
            'max_reports_per_month' => null,
            'is_recommended' => true,
            'sort_order' => 2,
            'feature_keys' => ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map', 'quotes', 'digital_delivery_notes'],
        ],
        SubscriptionPlan::EMPRESA => [
            'name' => 'Ascento Empresa',
            'description' => 'Para empresas con una operación grande.',
            'price' => 169000,
            'max_buildings' => 300,
            'max_clients' => 420,
            'max_technicians' => 25,
            'max_reports_per_month' => null,
            'is_recommended' => false,
            'sort_order' => 3,
            'feature_keys' => ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map', 'quotes', 'digital_delivery_notes'],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $slug => $plan) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $slug],
                [...$plan, 'currency' => 'ARS', 'is_active' => true, 'features' => []],
            );
        }

        SubscriptionPlan::whereNotIn('slug', array_keys(self::PLANS))->update(['is_active' => false]);
    }
}
