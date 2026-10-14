<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas web. Conservadoras a
 * propósito: la CSP solo existe en modo reporte y apagada por defecto, porque
 * una CSP aplicada a ciegas rompería el panel (ver docs/csp.md). Las
 * respuestas que ya traen su propia cabecera (archivos privados, PDF) no se pisan.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // El técnico usa la cámara (fotos y video) y la ubicación queda para la app.
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(self), payment=(), usb=()',
        ];

        // CSP en modo solo reporte (Etapa 11, R2): apagada por defecto. Con
        // ASCENTO_CSP_REPORT_ONLY=true el navegador no bloquea nada; solo avisa
        // en la consola lo que violaría la política, para ajustarla antes de
        // aplicarla. No hay dirección de reportes (nada nuevo que recibir).
        if (config('app.csp_report_only')) {
            $headers['Content-Security-Policy-Report-Only'] = self::policy();
        }

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=15552000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Política candidata. Filament, Livewire y Alpine evalúan expresiones
     * (x-data, wire:) y hay scripts en línea: por eso 'unsafe-inline' y
     * 'unsafe-eval' en scripts. Aun así acota de dónde se cargan recursos, a
     * dónde se conecta la página, quién la enmarca y a dónde envía formularios.
     */
    public static function policy(): string
    {
        // WebSocket de Reverb (tiempo real), con su puerto si no es el estándar.
        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');
        $socket = $host === '' ? [] : array_map(
            fn (string $scheme) => $scheme.'://'.$host.(in_array($port, [0, 80, 443], true) ? '' : ':'.$port),
            ['wss', 'ws'],
        );

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https://maps.geoapify.com https://tile.openstreetmap.org",
            "font-src 'self' data:",
            "connect-src 'self' ".implode(' ', $socket),
            "media-src 'self' blob:",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
