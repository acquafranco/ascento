<?php

namespace App\Support\Billing;

use App\Models\PlatformSettings;
use App\Services\Activity\PlatformAudit;
use App\Services\MercadoPagoService;
use Illuminate\Support\Carbon;

/**
 * ¿La cuenta de Mercado Pago del servidor es de PRUEBA o REAL? No se deduce
 * del prefijo del token (APP_USR- se usa en las dos): se consulta a Mercado
 * Pago (/users/me, solo lectura; no cobra ni crea nada) cuando el SuperAdmin
 * toca «Verificar cuenta» en «Configuración de la plataforma».
 *
 * Se guarda el resultado y una huella HMAC del token (no reversible, nunca el
 * token). Si el token cambia, la verificación anterior deja de valer.
 *
 * Modos: missing (sin token) · unverified · test · production.
 */
final class MercadoPagoAccount
{
    public const MISSING = 'missing';

    public const UNVERIFIED = 'unverified';

    public const TEST = 'test';

    public const PRODUCTION = 'production';

    /** Mercado Pago Argentina (la única pasarela de cobro hoy). */
    public const SITE = 'MLA';

    public static function fingerprint(): ?string
    {
        $token = (string) config('services.mercadopago.access_token');

        return $token === '' ? null : hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /** @return array{is_test: bool, site: ?string, checked_at: Carbon}|null la verificación del token ACTUAL */
    public static function verification(): ?array
    {
        $settings = PlatformSettings::current();
        $fingerprint = self::fingerprint();

        if ($fingerprint === null || $settings->mp_account_checked_at === null || $settings->mp_account_is_test === null
            || ! hash_equals((string) $settings->mp_token_fingerprint, $fingerprint)) {
            return null;
        }

        return ['is_test' => (bool) $settings->mp_account_is_test, 'site' => $settings->mp_account_site, 'checked_at' => $settings->mp_account_checked_at];
    }

    public static function mode(): string
    {
        if (self::fingerprint() === null) {
            return self::MISSING;
        }
        $verification = self::verification();

        return match (true) {
            $verification === null => self::UNVERIFIED,
            $verification['is_test'] => self::TEST,
            default => self::PRODUCTION,
        };
    }

    public static function modeLabel(): string
    {
        return match (self::mode()) {
            self::MISSING => 'Configuración incompleta (sin credenciales)',
            self::UNVERIFIED => 'Credenciales cargadas, cuenta sin verificar',
            self::TEST => 'Cuenta de PRUEBA (sandbox) de Mercado Pago',
            default => 'Cuenta REAL de Mercado Pago (cobros de verdad)',
        };
    }

    /**
     * Lo que impide empezar cobros NUEVOS de proveedores con esta cuenta en
     * este entorno (sin valores secretos).
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $verification = self::verification();
        if (self::fingerprint() === null) {
            return [];
        }
        if ($verification === null) {
            return ['Falta verificar la cuenta de Mercado Pago (Configuración de la plataforma → «Verificar cuenta»): sin eso no se sabe si cobra de prueba o de verdad.'];
        }

        $problems = [];
        if (app()->isProduction() && $verification['is_test']) {
            $problems[] = 'La cuenta de Mercado Pago es de PRUEBA y la aplicación está en producción.';
        }
        if (! app()->isProduction() && ! $verification['is_test']) {
            $problems[] = 'La cuenta de Mercado Pago es REAL y la aplicación no está en producción: usá credenciales de un usuario de prueba.';
        }
        if ($verification['site'] !== self::SITE) {
            $problems[] = 'La cuenta de Mercado Pago no es de Argentina ('.self::SITE.'): no puede cobrar en ARS.';
        }

        return $problems;
    }

    /**
     * Consulta la cuenta a Mercado Pago y guarda el resultado. Solo lectura.
     *
     * @throws \Throwable si Mercado Pago rechaza el token o no responde
     */
    public static function verify(): string
    {
        $me = app(MercadoPagoService::class)->me();
        $isTest = in_array('test_user', (array) ($me['tags'] ?? []), true);
        $site = isset($me['site_id']) ? mb_substr((string) $me['site_id'], 0, 8) : null;

        $settings = PlatformSettings::row();
        $settings->forceFill([
            'mp_account_checked_at' => now(),
            'mp_account_is_test' => $isTest,
            'mp_account_site' => $site,
            'mp_token_fingerprint' => self::fingerprint(),
            'updated_by' => auth()->id(),
        ])->save();
        PlatformAudit::record('platform.mercadopago.verified', $settings, ['cuenta' => $isTest ? 'prueba' : 'real', 'sitio' => $site], 'Cuenta de Mercado Pago');

        return self::mode();
    }
}
