<?php

namespace App\Models\Concerns;

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

    public function shareWithClient(bool $shared = true): void
    {
        $this->forceFill([
            'shared_with_client' => $shared,
            'shared_at' => $shared ? ($this->shared_at ?? now()) : null,
        ])->saveQuietly();
    }
}
