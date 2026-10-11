<?php

namespace App\Support\Diagnostics;

/**
 * Límites de subida del servidor, para ascento:check-production.
 *
 * - PHP CLI: lo que ve el comando (ini_get). NO es lo que usa la web.
 * - PHP-FPM (la web): se leen los archivos de configuración de FPM si son
 *   legibles (php.ini, conf.d y pool.d de la misma versión que la CLI).
 *   Es lo configurado en disco: si se cambió y no se recargó PHP-FPM, el
 *   proceso en marcha puede tener otro valor (el panel del SuperAdmin muestra
 *   el valor real de la web).
 * - Nginx: client_max_body_size en los archivos legibles (si no aparece,
 *   Nginx usa 1 MB).
 *
 * Todo es solo lectura. null = no se pudo verificar (no se inventa un valor).
 */
class ServerLimits
{
    /** "64M" / "1G" / "512K" / "0" → MB (0 = sin límite → INF). */
    public static function toMb(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?b?$/i', $value, $m)) {
            return null;
        }

        $number = (float) $m[1];
        if ($number == 0) {
            return INF;
        }

        return match (strtolower($m[2] ?? '')) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => $number / 1024,
            default => $number / 1048576, // bytes
        };
    }

    /** @return array{upload: ?float, post: ?float, memory: ?float, file: ?string} */
    public static function cli(): array
    {
        return [
            'upload' => static::toMb((string) ini_get('upload_max_filesize')),
            'post' => static::toMb((string) ini_get('post_max_size')),
            'memory' => static::toMb((string) ini_get('memory_limit')),
            'file' => php_ini_loaded_file() ?: null,
        ];
    }

    /**
     * Carpeta de configuración de PHP-FPM de la misma versión que la CLI
     * (/etc/php/8.x/cli/php.ini → /etc/php/8.x/fpm). null si no aplica.
     */
    public static function fpmDir(?string $cliIni = null): ?string
    {
        $cliIni ??= php_ini_loaded_file() ?: null;
        if (! $cliIni || ! preg_match('#^(.*)/cli/php\.ini$#', $cliIni, $m)) {
            return null;
        }

        return is_dir($m[1].'/fpm') ? $m[1].'/fpm' : null;
    }

    /**
     * Valores configurados para PHP-FPM (php.ini → conf.d → pools; lo último
     * pisa lo anterior, como hace PHP).
     *
     * @return array{upload: ?float, post: ?float, memory: ?float, files: list<string>}|null
     */
    public static function fpm(?string $dir): ?array
    {
        if (! $dir || ! is_readable($dir.'/php.ini')) {
            return null;
        }

        $values = [];
        $files = [];
        $keys = ['upload_max_filesize', 'post_max_size', 'memory_limit'];

        foreach ([$dir.'/php.ini', ...(glob($dir.'/conf.d/*.ini') ?: [])] as $file) {
            $ini = is_readable($file) ? @parse_ini_file($file, false, INI_SCANNER_RAW) : false;
            if ($ini === false) {
                continue;
            }
            $files[] = $file;
            foreach ($keys as $key) {
                if (isset($ini[$key])) {
                    $values[$key] = (string) $ini[$key];
                }
            }
        }

        // Pools: php_value[...] / php_admin_value[...] (este último no se puede pisar).
        foreach (glob($dir.'/pool.d/*.conf') ?: [] as $file) {
            $text = is_readable($file) ? (string) @file_get_contents($file) : '';
            if ($text === '') {
                continue;
            }
            $files[] = $file;
            foreach ($keys as $key) {
                if (preg_match_all('/^\s*php(?:_admin)?_value\['.$key.'\]\s*=\s*(\S+)/m', $text, $m)) {
                    $values[$key] = end($m[1]);
                }
            }
        }

        return [
            'upload' => static::toMb($values['upload_max_filesize'] ?? '2M'),
            'post' => static::toMb($values['post_max_size'] ?? '8M'),
            'memory' => static::toMb($values['memory_limit'] ?? '128M'),
            'files' => $files,
        ];
    }

    /**
     * client_max_body_size más chico configurado en los archivos legibles de
     * Nginx (o 1 MB si no aparece en ninguno). null si no hay nada legible.
     *
     * @param  list<string>|null  $patterns
     * @return array{mb: float, found: bool, at: ?string, files: list<string>}|null
     */
    public static function nginx(?array $patterns = null): ?array
    {
        $patterns ??= ['/etc/nginx/nginx.conf', '/etc/nginx/conf.d/*.conf', '/etc/nginx/sites-enabled/*', '/etc/nginx/forge-conf/*/*/*.conf', '/etc/nginx/forge-conf/*/*/*'];
        $files = [];
        $sizes = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (in_array($file, $files, true) || ! is_file($file) || ! is_readable($file)) {
                    continue;
                }
                $files[] = $file;
                if (preg_match_all('/^\s*client_max_body_size\s+([^;\s]+)\s*;/m', (string) @file_get_contents($file), $m)) {
                    foreach ($m[1] as $size) {
                        $mb = static::toMb($size);
                        $mb !== null && $sizes[] = [$mb, $file];
                    }
                }
            }
        }

        if ($files === []) {
            return null;
        }

        usort($sizes, fn ($a, $b) => $a[0] <=> $b[0]);

        return ['mb' => $sizes[0][0] ?? 1.0, 'found' => $sizes !== [], 'at' => $sizes[0][1] ?? null, 'files' => $files];
    }

    public static function label(?float $mb): string
    {
        return match (true) {
            $mb === null => '?',
            is_infinite($mb) => 'sin límite',
            default => rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.').' MB',
        };
    }
}
