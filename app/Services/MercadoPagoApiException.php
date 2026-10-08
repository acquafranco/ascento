<?php

namespace App\Services;

use RuntimeException;

/**
 * Error devuelto por la API de Mercado Pago, con el código HTTP y una
 * explicación accionable en castellano para los casos frecuentes.
 */
class MercadoPagoApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $detail,
    ) {
        parent::__construct("Mercado Pago respondió con error: {$status} - {$detail}");
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * Qué tiene que hacer quien configura la cuenta (texto para el admin).
     */
    public function hint(): string
    {
        $detail = mb_strtolower($this->detail);

        return match (true) {
            $this->status === 401 || str_contains($detail, 'invalid access token') || str_contains($detail, 'unauthorized') => 'El MERCADOPAGO_ACCESS_TOKEN no es válido. Copiá el Access Token de la aplicación nueva y, si el servidor cachea la configuración, corré "php artisan config:clear".',
            str_contains($detail, 'both payer and collector must be real or test users') => 'Estás usando credenciales de PRUEBA con un email real (o al revés). Con credenciales de prueba completá MERCADOPAGO_TEST_PAYER_EMAIL con el email del usuario de prueba comprador; en producción dejalo vacío.',
            str_contains($detail, 'payer and collector cannot be the same') || str_contains($detail, 'cannot pay yourself') || str_contains($detail, 'same user') => 'El email del pagador es el mismo de la cuenta de Mercado Pago que cobra. El pago tiene que hacerse con otra cuenta (cambiá el email de la empresa en "Mi empresa").',
            str_contains($detail, 'back_url') => 'Mercado Pago rechazó la URL de retorno: APP_URL tiene que ser la dirección pública con https de Ascento.',
            str_contains($detail, 'payer_email') => 'Mercado Pago rechazó el email del pagador. Revisá el email de la empresa en "Mi empresa".',
            str_contains($detail, 'card_token_id') => 'Mercado Pago pide una tarjeta tokenizada: la suscripción no debe estar asociada a un plan.',
            $this->status >= 500 => 'Mercado Pago tuvo un problema temporal. Probá de nuevo en unos minutos.',
            default => 'Mercado Pago rechazó la operación: '.$this->detail,
        };
    }
}
