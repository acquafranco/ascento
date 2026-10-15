<?php

namespace App\Support\Help;

use Illuminate\Support\Str;

/**
 * Búsqueda del Centro de ayuda, sin servicios externos: el contenido es
 * chico (un centenar de artículos) y se busca en memoria.
 *
 * - Sin acentos ni mayúsculas («guardia» = «Guardía»).
 * - Palabras incompletas: «remi» encuentra «remito» (desde 3 letras).
 * - Otras formas del mismo verbo o sustantivo: «registro» encuentra
 *   «registrar» y «olvidé» encuentra «olvidaste» (se compara la raíz).
 * - Plurales simples: «edificios» = «edificio».
 * - Sinónimos de la jerga del rubro: «consorcio» encuentra artículos de
 *   clientes, «turno» los de guardias, «comprobante» los de remitos.
 * - Pesa más el título, después las palabras clave y el resumen, y por
 *   último los pasos y las notas.
 * - Si ningún artículo tiene todas las palabras, muestra los que tienen
 *   alguna (mejor algo útil que una página vacía).
 */
final class HelpSearch
{
    private const STOPWORDS = ['de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'unos', 'unas', 'y', 'o', 'a', 'al', 'en', 'con', 'por', 'para', 'que', 'se', 'lo', 'le', 'les', 'mi', 'mis', 'tu', 'tus', 'su', 'sus', 'como', 'cuando', 'donde', 'es', 'me', 'no', 'si', 'hago', 'hacer', 'puedo', 'quiero', 'necesito', 'esta', 'este', 'esto'];

    /** Grupos de palabras equivalentes (ya normalizadas). */
    private const SYNONYMS = [
        ['cliente', 'consorcio', 'administracion', 'administrador', 'propietario'],
        ['edificio', 'propiedad', 'inmueble', 'torre'],
        ['ascensor', 'equipo', 'elevador', 'montacarga', 'maquina'],
        ['tecnico', 'empleado', 'operario', 'mecanico'],
        ['remito', 'comprobante', 'constancia', 'firma', 'firmar', 'firmado'],
        ['orden', 'reclamo', 'trabajo', 'pedido', 'ot'],
        ['reporte', 'falla', 'averia', 'desperfecto', 'problema', 'rotura'],
        ['guardia', 'turno', 'cobertura', 'reemplazo', 'suplente'],
        ['cobranza', 'cobro', 'pago', 'deuda', 'factura', 'saldo', 'cobrar'],
        ['servicio', 'contrato', 'abono', 'contratacion'],
        ['presupuesto', 'cotizacion'],
        ['stock', 'inventario', 'repuesto', 'material', 'deposito'],
        ['qr', 'codigo'],
        ['emergencia', 'atrapado', 'atrapamiento', 'socorro', 'auxilio'],
        ['portal', 'acceso'],
        ['permiso', 'rol', 'acceso'],
        ['contrasena', 'clave', 'password'],
        ['baja', 'borrar', 'eliminar', 'desactivar'],
        ['aviso', 'notificacion', 'alerta', 'push'],
        ['mantenimiento', 'visita', 'service'],
        ['plan', 'suscripcion', 'abono'],
        ['exportar', 'descargar', 'backup', 'respaldo', 'copia'],
        ['mapa', 'ubicacion', 'direccion'],
        ['menu', 'categoria', 'seccion'],
    ];

    /**
     * @param  list<array>  $items  artículos (HelpGuides) o preguntas frecuentes
     * @return list<array> los que coinciden, del más al menos relevante
     */
    public static function search(array $items, string $query): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach ($items as $i => $item) {
            $fields = self::fields($item);
            $score = 0.0;
            $matched = 0;
            foreach ($terms as $term) {
                $best = 0.0;
                foreach (self::expand($term) as [$word, $factor]) {
                    foreach ($fields as [$tokens, $weight]) {
                        if (self::matches($word, $tokens)) {
                            $best = max($best, $weight * $factor);
                        } elseif (($root = self::root($word)) !== null && self::matches($root, $tokens)) {
                            $best = max($best, $weight * $factor * 0.8);
                        }
                    }
                }
                if ($best > 0) {
                    $matched++;
                    $score += $best;
                }
            }
            if ($matched > 0) {
                $scored[] = ['item' => $item, 'all' => $matched === count($terms), 'score' => $score, 'order' => $i];
            }
        }

        $complete = array_filter($scored, fn ($s) => $s['all']);
        $results = $complete !== [] ? $complete : $scored;
        usort($results, fn ($a, $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_map(fn ($s) => $s['item'], array_values($results));
    }

    /** Palabras de la búsqueda, normalizadas y sin palabras vacías. @return list<string> */
    public static function terms(string $query): array
    {
        $words = self::tokens($query);
        $useful = array_values(array_filter($words, fn ($w) => ! in_array($w, self::STOPWORDS, true)));

        return array_values(array_unique($useful !== [] ? $useful : $words));
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        $text = Str::lower(Str::ascii($text));
        preg_match_all('/[a-z0-9]+/', $text, $m);

        return array_map(fn ($w) => self::stem($w), $m[0]);
    }

    /** Singular aproximado: «edificios» → «edificio», «ordenes» → «orden». */
    private static function stem(string $word): string
    {
        if (strlen($word) > 4 && str_ends_with($word, 'es') && ! str_ends_with($word, 'ees')) {
            $base = substr($word, 0, -2);
            if (preg_match('/[nrldz]$/', $base)) {
                return $base;
            }
        }

        return strlen($word) > 3 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
    }

    /** La palabra y sus sinónimos (con menos peso). @return list<array{0: string, 1: float}> */
    private static function expand(string $term): array
    {
        $out = [[$term, 1.0]];
        foreach (self::SYNONYMS as $group) {
            $group = array_map(fn ($w) => self::stem($w), $group);
            if (in_array($term, $group, true)) {
                foreach ($group as $word) {
                    if ($word !== $term) {
                        $out[] = [$word, 0.6];
                    }
                }
            }
        }

        return $out;
    }

    /** Raíz para comparar otras formas de la palabra (desde 6 letras, sin la última). */
    private static function root(string $word): ?string
    {
        return strlen($word) >= 6 ? substr($word, 0, -1) : null;
    }

    /** @param  list<string>  $tokens */
    private static function matches(string $word, array $tokens): bool
    {
        foreach ($tokens as $token) {
            // Exacta, o palabra incompleta (desde 3 letras): «remi» → «remito».
            if ($token === $word || (strlen($word) >= 3 && str_starts_with($token, $word))) {
                return true;
            }
        }

        return false;
    }

    /** Campos buscables con su peso. @return list<array{0: list<string>, 1: float}> */
    private static function fields(array $item): array
    {
        $flat = fn ($v) => is_array($v) ? implode(' ', array_map(fn ($x) => is_array($x) ? implode(' ', $x) : (string) $x, $v)) : (string) $v;

        return [
            [self::tokens($item['title'] ?? $item['q'] ?? ''), 6.0],
            [self::tokens($flat($item['keywords'] ?? [])), 5.0],
            [self::tokens(($item['summary'] ?? '').' '.($item['module'] ?? '')), 3.0],
            [self::tokens($flat($item['steps'] ?? []).' '.$flat($item['notes'] ?? []).' '.$flat($item['before'] ?? []).' '.($item['after'] ?? '').' '.$flat($item['problems'] ?? []).' '.($item['a'] ?? '')), 1.0],
        ];
    }
}
