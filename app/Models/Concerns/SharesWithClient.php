<?php

namespace App\Models\Concerns;

use App\Enums\PlanFeature;
use App\Exceptions\PlanFeatureUnavailableException;
use App\Models\Company;
use App\Support\Plans\PlanGuard;

/**
 * Registros que la empresa puede compartir con el portal del cliente
 * (reportes con sus fotos, remitos, presupuestos y documentos del legajo).
 * Privado por defecto; se comparte uno por uno (o en lote) y queda
 * registrado cuándo. No es asignable desde formularios.
 */
trait SharesWithClient
{
    public function initializeSharesWithClient(): void
    {
        $this->mergeCasts(['shared_with_client' => 'boolean', 'shared_at' => 'datetime']);
    }

    /**
     * @return bool true si pasó de privado a compartido (para avisar al cliente)
     *
     * @throws PlanFeatureUnavailableException si el plan no incluye el portal
     */
    public function shareWithClient(bool $shared = true): bool
    {
        if ($shared) {
            $company = Company::find($this->company_id);

            if ($company) {
                PlanGuard::for($company)->ensureFeature(PlanFeature::ClientPortal);
            }
        }

        $wasShared = (bool) $this->shared_with_client;

        $this->forceFill([
            'shared_with_client' => $shared,
            'shared_at' => $shared ? ($this->shared_at ?? now()) : null,
        ])->saveQuietly();

        return $shared && ! $wasShared;
    }
}
