<?php

namespace App\Support\PublicQr;

use App\Models\BuildingPublicPage;
use Illuminate\Support\Str;

/**
 * Comprobante firmado del formulario «Reportar un problema» (sin cookies).
 *
 * - HMAC con la clave de la app sobre el QR (id y token), un valor aleatorio y
 *   la hora: no se puede fabricar, y regenerar o desactivar el QR invalida
 *   los anteriores.
 * - Vence a las 2 horas.
 * - Al enviar, exige que hayan pasado unos segundos desde que se abrió el
 *   formulario (un robot que envía al instante no registra nada).
 * - Su hash es la clave de idempotencia: el mismo envío repetido devuelve el
 *   mismo reporte.
 */
final class QrReportNonce
{
    public const HOURS = 2;

    public const MIN_SECONDS = 3;

    public static function issue(BuildingPublicPage $page): string
    {
        $random = Str::random(24);
        $ts = now()->timestamp;

        return $random.'.'.$ts.'.'.self::sign($page, $random, $ts);
    }

    /** Clave de idempotencia si el comprobante es válido para ESTE QR; si no, null. */
    public static function key(BuildingPublicPage $page, string $nonce, bool $requireAge = false): ?string
    {
        if (strlen($nonce) > 200 || ! preg_match('/^([A-Za-z0-9]{24})\.(\d{9,11})\.([a-f0-9]{64})$/', $nonce, $m)) {
            return null;
        }

        [, $random, $ts, $sig] = $m;
        $age = now()->timestamp - (int) $ts;

        if ($age < -60 || $age > self::HOURS * 3600 || ($requireAge && $age < self::MIN_SECONDS)
            || ! hash_equals(self::sign($page, $random, (int) $ts), $sig)) {
            return null;
        }

        return hash('sha256', 'qr-report|'.$nonce);
    }

    private static function sign(BuildingPublicPage $page, string $random, int $ts): string
    {
        return hash_hmac('sha256', 'qr-report|'.$page->id.'|'.$page->token.'|'.$random.'|'.$ts, (string) config('app.key'));
    }
}
