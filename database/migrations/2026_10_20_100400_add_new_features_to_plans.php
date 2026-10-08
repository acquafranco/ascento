<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Funciones nuevas por plan. Solo SUMA claves a feature_keys: no cambia
 * precios, límites ni lo que cada plan ya tenía.
 *
 * Inicial ("Operar"): agenda, centro de atención básico, legajo + historial
 * básico del ascensor, indicadores básicos.
 * Profesional ("Controlar"): + atención avanzada, indicadores de empresa,
 * historial avanzado y análisis de fallas.
 * Empresa ("Gestionar"): + indicadores avanzados (cartera, comparativas,
 * tendencias) y alertas avanzadas.
 */
return new class extends Migration
{
    private const CORE = ['agenda', 'attention_center', 'elevator_file', 'indicators'];

    private const PROFESIONAL = ['attention_advanced', 'company_indicators', 'elevator_history_advanced', 'failure_analysis'];

    private const EMPRESA = ['advanced_indicators', 'advanced_alerts'];

    private function features(string $slug): array
    {
        return match ($slug) {
            'inicial' => self::CORE,
            'empresa' => [...self::CORE, ...self::PROFESIONAL, ...self::EMPRESA],
            // El plan histórico "professional" (inactivo, sin funciones cargadas)
            // queda igual que Profesional, por si algún dato viejo lo usa.
            'professional' => ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map', 'quotes', 'digital_delivery_notes', ...self::CORE, ...self::PROFESIONAL],
            default => [...self::CORE, ...self::PROFESIONAL],
        };
    }

    public function up(): void
    {
        DB::table('subscription_plans')->get(['id', 'slug', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_unique([...$keys, ...$this->features($plan->slug)]))),
            ]);
        });
    }

    public function down(): void
    {
        $new = [...self::CORE, ...self::PROFESIONAL, ...self::EMPRESA];

        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(function ($plan) use ($new) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_diff($keys, $new))),
            ]);
        });
    }
};
