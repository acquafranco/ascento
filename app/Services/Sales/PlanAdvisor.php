<?php

namespace App\Services\Sales;

use App\Enums\PlanLimit;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;

/**
 * Qué plan necesita una empresa, con los planes y límites REALES
 * (subscription_plans): edificios, clientes y técnicos. La cantidad de
 * ascensores no limita ningún plan (solo cuenta para la implementación).
 *
 * - Compatible: el plan admite las tres cantidades.
 * - Recomendado: el más barato compatible (si hay varios, se explican las
 *   diferencias de funciones).
 * - Si ninguno alcanza, no se fuerza un plan: propuesta personalizada.
 */
final class PlanAdvisor
{
    /** Los límites que decide la calculadora, con la cantidad que se compara. */
    private const CHECKS = ['buildings' => PlanLimit::Buildings, 'clients' => PlanLimit::Clients, 'technicians' => PlanLimit::Technicians];

    /**
     * @return array{plans: list<array>, recommended: ?string, none: bool}
     */
    public function advise(CalculatorInput $in, ?Collection $plans = null): array
    {
        $plans ??= SubscriptionPlan::offered();
        $needs = ['buildings' => $in->buildings, 'clients' => $in->clients, 'technicians' => $in->technicians];
        $rows = [];
        $previous = null;

        foreach ($plans as $plan) {
            $checks = [];
            foreach (self::CHECKS as $key => $limit) {
                $max = $plan->limit($limit);
                $checks[$key] = ['label' => $limit->planLabel($max), 'fits' => $max === null || $needs[$key] <= $max, 'max' => $max, 'need' => $needs[$key]];
            }
            $fits = collect($checks)->every(fn ($c) => $c['fits']);

            $rows[] = [
                'slug' => $plan->slug,
                'name' => $plan->shortName(),
                'description' => $plan->description,
                'price' => (float) $plan->price,
                'price_label' => $plan->formattedPrice(),
                'currency' => $plan->currency,
                'fits' => $fits,
                'checks' => $checks,
                // Lo que suma frente al plan anterior (funciones reales del plan).
                'adds' => $previous ? array_values(array_diff($plan->highlights(), $previous->highlights())) : [],
            ];
            $previous = $plan;
        }

        $compatible = array_values(array_filter($rows, fn ($r) => $r['fits']));
        usort($compatible, fn ($a, $b) => $a['price'] <=> $b['price']);

        return [
            'plans' => $rows,
            'recommended' => $compatible[0]['slug'] ?? null,
            'none' => $compatible === [],
        ];
    }
}
