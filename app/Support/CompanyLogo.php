<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Logo de una empresa para documentos (remitos y presupuestos), con las mismas
 * reglas que el PDF de reportes:
 * - solo del disco "public" (donde lo guarda "Mi empresa"), nunca una ruta
 *   del servidor: sin "..", sin rutas absolutas;
 * - solo si el archivo es realmente una imagen PNG o JPEG (se mira el
 *   contenido, no la extensión).
 * Así ningún valor guardado en companies.logo puede hacer que un PDF incruste
 * otro archivo del servidor.
 */
final class CompanyLogo
{
    /** Ruta válida en el disco público, o null. */
    private static function path(?Company $company): ?string
    {
        $path = trim((string) $company?->logo);

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
            return null;
        }

        try {
            return Storage::disk('public')->exists($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: string}|null [ruta, tipo] si es una imagen PNG/JPEG real */
    private static function image(?Company $company): ?array
    {
        $path = self::path($company);
        if ($path === null) {
            return null;
        }

        $size = @getimagesizefromstring((string) Storage::disk('public')->get($path));

        return $size && in_array($size['mime'], ['image/jpeg', 'image/png'], true) ? [$path, $size['mime']] : null;
    }

    /** data URI para dompdf (que no descarga URLs), o null. */
    public static function dataUri(?Company $company): ?string
    {
        $image = self::image($company);

        return $image ? 'data:'.$image[1].';base64,'.base64_encode((string) Storage::disk('public')->get($image[0])) : null;
    }

    /** URL pública para la web (mismas reglas), o null. */
    public static function url(?Company $company): ?string
    {
        $image = self::image($company);

        return $image ? asset('storage/'.$image[0]) : null;
    }
}
