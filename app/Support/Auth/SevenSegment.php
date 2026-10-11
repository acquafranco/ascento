<?php

namespace App\Support\Auth;

/**
 * Dígitos de 7 segmentos (como el indicador de piso de un ascensor) en SVG,
 * para el desafío del registro. Cada dibujo varía: segmentos desplazados,
 * inclinación, segmentos apagados tenues y puntos de ruido. El número no
 * aparece como texto en el HTML.
 */
final class SevenSegment
{
    // Segmentos a–g en una caja de 40×70: [x, y, ancho, alto].
    private const SEGMENTS = [
        'a' => [6, 2, 28, 6], 'b' => [32, 6, 6, 27], 'c' => [32, 37, 6, 27], 'd' => [6, 62, 28, 6],
        'e' => [2, 37, 6, 27], 'f' => [2, 6, 6, 27], 'g' => [6, 32, 28, 6],
    ];

    private const DIGITS = [
        0 => 'abcdef', 1 => 'bc', 2 => 'abdeg', 3 => 'abcdg', 4 => 'bcfg',
        5 => 'acdfg', 6 => 'acdefg', 7 => 'abc', 8 => 'abcdefg', 9 => 'abcdfg',
    ];

    /** Indicador grande (fondo oscuro, segmentos naranja). */
    public static function display(int $digit): string
    {
        $noise = self::noise(140, 110, 14, '#FF6600');
        $skew = random_int(-8, 8);
        $segments = self::segments($digit, '#FF6600');

        return '<svg viewBox="0 0 140 110" width="140" height="110" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">'
            .'<rect x="0" y="0" width="140" height="110" rx="14" fill="#11161D"/>'
            .$noise
            .'<g transform="translate(50 18) skewX('.$skew.') scale(1.05)">'.$segments.'</g>'
            .'</svg>';
    }

    /** Botón de piso (círculo claro, segmentos oscuros). */
    public static function button(int $digit): string
    {
        $skew = random_int(-10, 10);

        return '<svg viewBox="0 0 64 64" width="44" height="44" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">'
            .self::noise(64, 64, 6, '#11161D')
            .'<g transform="translate(20 9) skewX('.$skew.') scale(0.62)">'.self::segments($digit, '#11161D').'</g>'
            .'</svg>';
    }

    private static function segments(int $digit, string $color): string
    {
        $lit = self::DIGITS[$digit] ?? '';
        $out = '';

        foreach (self::SEGMENTS as $name => [$x, $y, $w, $h]) {
            $on = str_contains($lit, $name);
            $dx = random_int(-15, 15) / 10;
            $dy = random_int(-15, 15) / 10;
            $out .= sprintf(
                '<rect x="%.1f" y="%.1f" width="%d" height="%d" rx="2.5" fill="%s" opacity="%.2f"/>',
                $x + $dx, $y + $dy, $w, $h, $color, $on ? random_int(85, 100) / 100 : random_int(8, 16) / 100,
            );
        }

        return $out;
    }

    private static function noise(int $width, int $height, int $count, string $color): string
    {
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="%s" opacity="%.2f"/>', random_int(0, $width), random_int(0, $height), random_int(5, 20) / 10, $color, random_int(10, 30) / 100);
        }

        return $out;
    }
}
