<?php

namespace App\Services\Plans;

use App\Enums\PlanFeature;
use App\Models\PlanFeatureChange;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

/**
 * Qué funciones incluye cada plan (Etapa 9.2). Solo el SuperAdmin, y solo
 * las funciones configurables (PlanFeature::toggleable): las esenciales no
 * se pueden quitar, ni desde la pantalla ni con un pedido manipulado. No toca
 * precios ni límites. Quitar una función nunca borra datos: las pantallas se
 * cierran y los datos vuelven con el plan. Cada cambio queda en
 * plan_feature_changes (quién, cuándo, plan, función, antes y después, motivo).
 */
class PlanFeatureManager
{
    /**
     * @param  list<string>  $enabled  funciones configurables que el plan debe incluir
     * @return array{added: list<string>, removed: list<string>}
     */
    public function update(?User $actor, SubscriptionPlan $plan, array $enabled, ?string $context = null): array
    {
        abort_unless($actor?->isSuperAdmin(), 403);

        $toggleable = array_map(fn (PlanFeature $f) => $f->value, PlanFeature::toggleable());
        $structural = array_map(fn (PlanFeature $f) => $f->value, PlanFeature::structural());
        $enabled = array_values(array_intersect($toggleable, array_map('strval', $enabled)));
        $context = Str::limit(trim((string) $context), 250, '') ?: null;

        return tap(DB::transaction(function () use ($actor, $plan, $enabled, $toggleable, $structural, $context) {
            $locked = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $before = (array) $locked->feature_keys;
            // Se conservan las esenciales y cualquier clave que no administra esta pantalla.
            $kept = array_values(array_diff($before, $toggleable));
            $after = array_values(array_unique([...$structural, ...$kept, ...$enabled]));

            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added || $removed) {
                $locked->forceFill(['feature_keys' => $after])->save();

                foreach ([...array_map(fn ($k) => [$k, false, true], $added), ...array_map(fn ($k) => [$k, true, false], $removed)] as [$key, $from, $to]) {
                    $change = new PlanFeatureChange;
                    $change->forceFill([
                        'user_id' => $actor->id, 'user_name' => Str::limit((string) $actor->name, 115, ''),
                        'subscription_plan_id' => $locked->id, 'plan_slug' => $locked->slug, 'feature' => $key,
                        'enabled_before' => $from, 'enabled_after' => $to, 'context' => $context,
                    ])->save();
                }

                Log::notice('Funciones del plan actualizadas', [
                    'actor_id' => $actor->id, 'plan' => $locked->slug, 'added' => $added, 'removed' => $removed,
                ]);
            }

            return ['added' => $added, 'removed' => $removed];
        }), fn () => $this->forgetCachedPlans());
    }

    /** Lo que quedó en memoria del pedido (SubscriptionPlan::findBySlug) ya no vale. */
    private function forgetCachedPlans(): void
    {
        Once::flush();
    }
}
