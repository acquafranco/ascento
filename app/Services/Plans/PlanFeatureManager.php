<?php

namespace App\Services\Plans;

use App\Enums\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;

/**
 * Qué funciones incluye cada plan (Etapa 9.2). Solo el SuperAdmin, y solo
 * las funciones activables (PlanFeature::toggleable): la base del producto
 * no se puede quitar. No toca precios ni límites. Quitar una función nunca
 * borra datos: las pantallas se cierran y los datos vuelven con el plan.
 * Cada cambio queda en el log de la aplicación (quién, plan, qué se sumó y
 * qué se quitó).
 */
class PlanFeatureManager
{
    /**
     * @param  list<string>  $enabled  funciones activables que el plan debe incluir
     * @return array{added: list<string>, removed: list<string>}
     */
    public function update(?User $actor, SubscriptionPlan $plan, array $enabled): array
    {
        abort_unless($actor?->isSuperAdmin(), 403);

        $toggleable = array_map(fn (PlanFeature $f) => $f->value, PlanFeature::toggleable());
        $structural = array_map(fn (PlanFeature $f) => $f->value, PlanFeature::structural());
        $enabled = array_values(array_intersect($toggleable, array_map('strval', $enabled)));

        return tap(DB::transaction(function () use ($actor, $plan, $enabled, $toggleable, $structural) {
            $locked = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $before = (array) $locked->feature_keys;
            // Se conservan la base y cualquier clave que no administra esta pantalla.
            $kept = array_values(array_diff($before, $toggleable));
            $after = array_values(array_unique([...$structural, ...$kept, ...$enabled]));

            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added || $removed) {
                $locked->forceFill(['feature_keys' => $after])->save();
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
