<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas web. Conservadoras a
 * propósito: no se agrega una CSP estricta porque Filament/Livewire usan
 * scripts en línea (una CSP a ciegas rompería el panel). Las respuestas que ya
 * traen su propia cabecera (archivos privados, PDF) no se pisan.
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
}
