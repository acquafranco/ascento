<?php

namespace App\Support\DeliveryNotes;

/**
 * Firma dibujada del remito (data URL de imagen) validada en el servidor.
 *
 * No alcanza con que "parezca" un data URL: se decodifica la imagen, se
 * controla su tamaño y que tenga trazo (una imagen en blanco o transparente
 * no es una firma), y se vuelve a codificar como PNG, así no se guarda nada
 * que no sea una imagen. El control de trazo tolera el "ruido" que algunos
 * navegadores (p. ej. Brave) agregan al leer el lienzo para evitar el
 * rastreo: ese ruido es de 1 o 2 niveles de color y nunca llega a "tinta".
 */
final class SignatureImage
{
    public const PATTERN = '/^data:image\/(png|jpeg);base64,([A-Za-z0-9+\/=]+)$/';

    /** Bytes máximos de la imagen decodificada. */
    public const MAX_BYTES = 750 * 1024;

    /** Píxeles de tinta mínimos, medidos sobre la imagen achicada a 400 px de ancho. */
    public const MIN_INK = 40;

    /**
     * PNG normalizado (data URL) o el motivo por el que no es una firma.
     *
     * @return array{0: ?string, 1: ?string} [dataUrl, error]
     */
    public static function normalize(string $dataUrl): array
    {
        if (! preg_match(self::PATTERN, $dataUrl, $m)) {
            return [null, 'invalid'];
        }

        $binary = base64_decode($m[2], true);

        if ($binary === false || $binary === '' || strlen($binary) > self::MAX_BYTES) {
            return [null, 'invalid'];
        }

        $info = @getimagesizefromstring($binary);

        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $info[0] < 50 || $info[1] < 20 || $info[0] > 4000 || $info[1] > 2000) {
            return [null, 'invalid'];
        }

        $image = @imagecreatefromstring($binary);

        if (! $image) {
            return [null, 'invalid'];
        }

        try {
            // Un PNG con paleta devuelve índices, no colores: se pasa a color real.
            imagepalettetotruecolor($image);

            if (self::ink($image) < self::MIN_INK) {
                return [null, 'empty'];
            }

            imagesavealpha($image, true);
            ob_start();
            imagepng($image, null, 9);
            $png = (string) ob_get_clean();

            return ['data:image/png;base64,'.base64_encode($png), null];
        } finally {
            imagedestroy($image);
        }
    }

    /** Píxeles oscuros y opacos (trazo), sobre una copia de 400 px de ancho. */
    private static function ink(\GdImage $image): int
    {
        $width = imagesx($image);
        $scaled = $width > 400 ? imagescale($image, 400) : $image;
        $w = imagesx($scaled);
        $h = imagesy($scaled);
        $ink = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($scaled, $x, $y);
                $alpha = ($c >> 24) & 0x7F; // 0 = opaco, 127 = transparente
                $lum = (int) ((($c >> 16) & 0xFF) * 0.3 + (($c >> 8) & 0xFF) * 0.59 + ($c & 0xFF) * 0.11);

                if ($alpha < 64 && $lum < 160) {
                    $ink++;
                }
            }
        }

        if ($scaled !== $image) {
            imagedestroy($scaled);
        }

        return $ink;
    }
}
