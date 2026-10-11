<?php

namespace App\Support\Diagnostics;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Pusher\ApiErrorException;
use Throwable;

/**
 * ¿La app puede publicar en Reverb? Para ascento:check-production y para el
 * log cuando falla un aviso en tiempo real.
 *
 * Separa cuatro casos, porque se arreglan en lugares distintos:
 * - config:     la app no pudo armar el cliente (variable faltante o de tipo
 *               inválido). No es la red.
 * - connection: no hubo respuesta (DNS, puerto cerrado, TLS, tiempo agotado).
 * - http:       Reverb o el proxy respondió con un error (firma, app, 5xx).
 * - app:        otro error al publicar.
 *
 * Publica un mensaje vacío en un canal público que nadie escucha (lo mismo
 * que hace un aviso real). Nunca muestra la clave secreta ni la firma.
 */
class ReverbProbe
{
    public const CHANNEL = 'ascento-diagnostico';

    /** @return array{ok: bool, kind: string, detail: string, hint: string} */
    public static function publish(): array
    {
        try {
            $pusher = Broadcast::connection('reverb')->getPusher();
        } catch (Throwable $e) {
            return static::result('config', $e);
        }

        try {
            $pusher->trigger(static::CHANNEL, 'diagnostico', ['at' => now()->toIso8601String()]);
        } catch (Throwable $e) {
            return static::classify($e);
        }

        return ['ok' => true, 'kind' => 'ok', 'detail' => '', 'hint' => ''];
    }

    /** @return array{ok: bool, kind: string, detail: string, hint: string} */
    public static function classify(Throwable $e): array
    {
        // La librería de Pusher envuelve los errores de red en ApiErrorException
        // con código 0, y Laravel en BroadcastException("Pusher error: cURL error N…").
        $network = $e instanceof ConnectException
            || ($e instanceof RequestException && ! $e->hasResponse())
            || ($e instanceof ApiErrorException && (int) $e->getCode() === 0)
            || str_contains($e->getMessage(), 'cURL error');

        return match (true) {
            $network => static::result('connection', $e),
            $e instanceof ApiErrorException, $e instanceof BroadcastException => static::result('http', $e),
            default => static::result('app', $e),
        };
    }

    /** Destino al que publica la app (sin secretos). */
    public static function target(): string
    {
        $o = (array) config('broadcasting.connections.reverb.options');

        return ($o['scheme'] ?? 'https').'://'.($o['host'] ?? '?').':'.($o['port'] ?? '?');
    }

    /** Mensaje corto y sin secretos (sin la URL firmada ni HTML del proxy). */
    public static function describe(Throwable $e): string
    {
        $message = $e->getMessage();
        if ($e instanceof ApiErrorException && (int) $e->getCode() > 0) {
            $message = 'HTTP '.$e->getCode().' '.$message;
        }

        $message = strip_tags($message);
        $message = preg_replace('/\s*\(see https?:\/\/\S+\)/', '', $message);   // ayuda de cURL
        $message = preg_replace('/\s+for https?:\/\/\S+/', '', (string) $message); // URL con la firma
        $message = preg_replace('/\?\S*/', '', (string) $message);
        $secret = (string) config('broadcasting.connections.reverb.secret');
        if (strlen($secret) >= 3) {
            $message = str_replace($secret, '***', (string) $message);
        }

        return class_basename($e).': '.Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $message)), 200);
    }

    /** @return array{ok: bool, kind: string, detail: string, hint: string} */
    private static function result(string $kind, Throwable $e): array
    {
        return ['ok' => false, 'kind' => $kind, 'detail' => static::describe($e), 'hint' => static::hint($kind, $e)];
    }

    private static function hint(string $kind, Throwable $e): string
    {
        $text = static::describe($e); // sin la URL firmada (tiene "auth_signature")
        $status = $e instanceof ApiErrorException ? (int) $e->getCode() : 0;

        return match (true) {
            $kind === 'config' => 'Es un problema de configuración de la app, no de red: revisá que REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET, REVERB_HOST y REVERB_PORT estén cargados y después php artisan optimize',
            $kind === 'connection' && str_contains($text, 'cURL error 6') => 'No se resuelve REVERB_HOST (DNS)',
            $kind === 'connection' && (str_contains($text, 'cURL error 35') || str_contains($text, 'cURL error 60') || stripos($text, 'SSL') !== false) => 'Falló TLS: ese puerto no atiende https o el certificado no es válido para REVERB_HOST. La app tiene que publicar por el mismo host y puerto públicos que usa el navegador (con Forge, normalmente 443 a través de Nginx)',
            $kind === 'connection' && str_contains($text, 'cURL error 7') => 'Nada atiende en ese host y puerto: revisá que el daemon de Reverb esté corriendo y que REVERB_HOST/REVERB_PORT sean los públicos',
            $kind === 'connection' && str_contains($text, 'cURL error 28') => 'Tiempo agotado: el puerto no respondió (firewall, o https contra un puerto que solo atiende http, como el puerto interno de Reverb)',
            $kind === 'connection' => 'No hubo respuesta de Reverb',
            $kind === 'http' && ($status === 401 || $status === 403 || str_contains($text, 'signature')) => 'Reverb rechazó la firma: REVERB_APP_KEY/REVERB_APP_SECRET de la app no coinciden con los que cargó el proceso de Reverb (reinicialo después de cambiarlos)',
            $kind === 'http' && ($status === 404 || str_contains($text, 'No matching application')) => 'Reverb no conoce ese REVERB_APP_ID, o REVERB_HOST apunta a un sitio que no es Reverb',
            $kind === 'http' && ($status >= 500 || preg_match('/\b50[234]\b|Bad Gateway|Gateway Time/i', $text)) => 'El proxy (Nginx) no llega al proceso de Reverb: daemon caído o puerto distinto al configurado en el sitio',
            $kind === 'http' => 'Reverb o el proxy respondieron con un error',
            default => 'Error de la app al publicar (detalle en el log)',
        };
    }
}
