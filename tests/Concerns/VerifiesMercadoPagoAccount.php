<?php

namespace Tests\Concerns;

use App\Models\PlatformSettings;
use App\Support\Billing\MercadoPagoAccount;

/**
 * Deja la cuenta de Mercado Pago del token configurado AHORA como verificada
 * (lo que haría «Verificar cuenta» con /users/me), sin llamar a la API.
 * Fuera de producción solo vale una cuenta de prueba.
 */
trait VerifiesMercadoPagoAccount
{
    protected function markMercadoPagoVerified(bool $test = true, string $site = 'MLA'): void
    {
        PlatformSettings::row()->forceFill([
            'mp_account_checked_at' => now(),
            'mp_account_is_test' => $test,
            'mp_account_site' => $site,
            'mp_token_fingerprint' => MercadoPagoAccount::fingerprint(),
        ])->save();
    }
}
