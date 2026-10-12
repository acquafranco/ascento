<?php

namespace Tests\Concerns;

/**
 * Firmas de remito para las pruebas: imágenes PNG reales (el servidor
 * decodifica la firma y exige trazo), con o sin trazo.
 */
trait MakesSignatures
{
    /** Firma real: PNG transparente con un trazo (como la del lienzo del remito). */
    protected function validSignature(): string
    {
        return $this->signaturePng(true);
    }

    /** PNG del tamaño del lienzo del remito, con trazo o en blanco (transparente). */
    protected function signaturePng(bool $withStroke): string
    {
        $image = imagecreatetruecolor(600, 200);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);

        if ($withStroke) {
            imagesetthickness($image, 4);
            $ink = imagecolorallocate($image, 17, 17, 17);
            for ($i = 0; $i < 12; $i++) {
                imageline($image, 40 + $i * 40, 120 + ($i % 2 ? 40 : -40), 80 + $i * 40, 120 + ($i % 2 ? -40 : 40), $ink);
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
