<?php

namespace App\Support\Plans;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Exceptions\PlanFeatureUnavailableException;
use App\Exceptions\PlanLimitReachedException;
use App\Models\Company;
use App\Models\SubscriptionPlan;

/**
 * Único lugar que responde "¿esta empresa puede…?" según su plan, y que
 * arma los mensajes de límite/upgrade. Nada de "if plan == X" por el código.
 */
class PlanGuard
{
    /** A partir de qué porcentaje se avisa "estás cerca del límite". */
    public const NEAR_RATIO = 0.8;

    public function __construct(public readonly Company $company) {}

    public static function for(Company $company): self
    {
        return new self($company);
    }

    public function plan(): SubscriptionPlan
    {
        return $this->company->plan();
    }

    public function usage(PlanLimit $limit): int
    {
        return $limit->usage($this->company);
    }

    public function limit(PlanLimit $limit): ?int
    {
        return $this->plan()->limit($limit);
    }

    public function canAdd(PlanLimit $limit, int $count = 1): bool
    {
        $max = $this->limit($limit);

        return $max === null || $this->usage($limit) + $count <= $max;
    }

    public function isNear(PlanLimit $limit): bool
    {
        $max = $this->limit($limit);

        return $max !== null && $this->usage($limit) >= (int) ceil($max * self::NEAR_RATIO);
    }

    public function isAtLimit(PlanLimit $limit): bool
    {
        return ! $this->canAdd($limit);
    }

    public function allows(PlanFeature $feature): bool
    {
        return $this->plan()->allows($feature);
    }

    /** @throws PlanLimitReachedException */
    public function ensureCanAdd(PlanLimit $limit, int $count = 1): void
    {
        if (! $this->canAdd($limit, $count)) {
            throw new PlanLimitReachedException($this->company, $limit);
        }
    }

    /** @throws PlanFeatureUnavailableException */
    public function ensureFeature(PlanFeature $feature): void
    {
        if (! $this->allows($feature)) {
            throw new PlanFeatureUnavailableException($this->company, $feature);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TEXTOS
    |--------------------------------------------------------------------------
    */

    /** "18 / 20 edificios" (o "18 edificios" si no hay límite). */
    public function usageLabel(PlanLimit $limit): string
    {
        $max = $this->limit($limit);
        $used = $this->usage($limit);

        return $max === null ? "{$used} ".$limit->plural() : "{$used} / {$max} ".$limit->plural();
    }

    /** Aviso para mostrar junto al uso (null si está lejos del límite). */
    public function warning(PlanLimit $limit): ?string
    {
        return match (true) {
            $this->isAtLimit($limit) => 'Alcanzaste el límite de tu plan. Actualizá tu plan para continuar.',
            $this->isNear($limit) => 'Estás cerca del límite de tu plan.',
            default => null,
        };
    }

    public function limitReachedMessage(PlanLimit $limit): string
    {
        $max = $this->limit($limit);
        $planName = $this->plan()->shortName();

        return $limit->isMonthly()
            ? "Alcanzaste el límite de {$max} reportes de este mes de tu plan {$planName}."
            : "Alcanzaste el límite de {$max} {$limit->plural()} de tu plan {$planName}.";
    }

    public function featureUnavailableMessage(PlanFeature $feature): string
    {
        $plan = SubscriptionPlan::cheapestWith($feature);

        return $feature->label().' está disponible desde el plan '.($plan?->shortName() ?? 'Profesional').'.';
    }

    /**
     * Qué gana al actualizar: "Con Profesional podés administrar hasta 70
     * edificios y además obtenés reportes sin límite mensual, presupuestos…".
     */
    public function upgradePitch(?PlanLimit $limit = null, ?PlanFeature $feature = null): ?string
    {
        $current = $this->plan();
        $target = match (true) {
            $feature !== null => SubscriptionPlan::cheapestWith($feature),
            $limit !== null => SubscriptionPlan::cheapestFor($limit, $this->usage($limit) + 1),
            default => $current->next(),
        };

        if (! $target || $target->sort_order <= $current->sort_order) {
            $target = $current->next();
        }

        if (! $target) {
            return null;
        }

        $gains = [];

        foreach (PlanLimit::cases() as $each) {
            $old = $current->limit($each);
            $new = $target->limit($each);

            if ($old !== null && ($new === null || $new > $old)) {
                $gains[$each->value] = match (true) {
                    $each->isMonthly() && $new === null => 'reportes sin límite mensual',
                    $new === null => mb_strtolower($each->planLabel(null)),
                    default => 'hasta '.$new.' '.$each->plural(),
                };
            }
        }

        foreach ([PlanFeature::Quotes, PlanFeature::DigitalDeliveryNotes] as $each) {
            if (! $current->allows($each) && $target->allows($each)) {
                $gains[$each->value] = mb_strtolower($each->label());
            }
        }

        if ($gains === []) {
            return null;
        }

        // Lo que motivó el upgrade va primero.
        $lead = $limit && isset($gains[$limit->value]) ? $gains[$limit->value] : null;
        $rest = array_values(array_diff_key($gains, $limit ? [$limit->value => true] : []));

        $text = 'Con '.$target->shortName();

        if ($lead) {
            $verb = $limit === PlanLimit::ReportsPerMonth ? 'generás' : 'podés administrar';
            $text .= " {$verb} {$lead}";
            $text .= $rest ? ' y además obtenés '.$this->joinList($rest) : '';
        } else {
            $text .= ' obtenés '.$this->joinList(array_values($gains));
        }

        return $text.'.';
    }

    private function joinList(array $items): string
    {
        return count($items) > 1
            ? implode(', ', array_slice($items, 0, -1)).' y '.end($items)
            : ($items[0] ?? '');
    }
}
