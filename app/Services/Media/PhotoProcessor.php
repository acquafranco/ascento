<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Procesamiento seguro de fotos (reportes y visitas): cada imagen se
 * decodifica y se vuelve a codificar como JPEG (no pasa nada que no sea una
 * imagen), se orienta sola, se le quitan los metadatos (EXIF, incluida la
 * ubicación GPS), se achica a 2000 px y se guarda con miniatura en el disco
 * privado con nombre aleatorio. Antes vivía dentro de ReportPhotoService.
 */
class PhotoProcessor
{
    public const MAX_SIDE = 2000;

    /**
     * Imagick si está (lee HEIC de iPhone); si no, GD. Corrige la orientación
     * y quita los metadatos al re-codificar.
     */
    public static function manager(): ImageManager
    {
        return ImageManager::usingDriver(extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class, autoOrientation: true, strip: true);
    }

    public static function thumbPathFor(string $path): string
    {
        return preg_replace('/\.jpg$/', '', $path).'-thumb.jpg';
    }

    /**
     * Guarda la foto (y su miniatura) en "{directory}/{aleatorio}.jpg" del
     * disco privado. Si no es una imagen válida, no queda ningún archivo.
     *
     * @return array{path: string, thumb_path: string, width: ?int, height: ?int, size: int}
     */
    public function store(UploadedFile|string $file, string $directory, string $field = 'photos', string $logMessage = 'Foto inválida'): array
    {
        $source = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $path = trim($directory, '/').'/'.Str::random(40).'.jpg';
        $fullPath = Storage::disk('local')->path($path);

        try {
            if (! is_dir(dirname($fullPath))) {
                mkdir(dirname($fullPath), 0755, true);
            }

            $image = static::manager()->decode(fopen($source, 'rb'))
                ->scaleDown(width: (int) config('media.photo_max_side', self::MAX_SIDE), height: (int) config('media.photo_max_side', self::MAX_SIDE));

            $image->encode(new JpegEncoder(quality: (int) config('media.photo_quality', 82)))->save($fullPath);

            // Miniatura para listas, el portal y conexiones lentas.
            $thumbPath = static::thumbPathFor($path);
            $image->scaleDown(width: (int) config('media.thumb_side', 480), height: (int) config('media.thumb_side', 480))
                ->encode(new JpegEncoder(quality: 75))->save(Storage::disk('local')->path($thumbPath));

            return [
                'path' => $path,
                'thumb_path' => $thumbPath,
                'width' => getimagesize($fullPath)[0] ?? null,
                'height' => getimagesize($fullPath)[1] ?? null,
                'size' => (int) filesize($fullPath),
            ];
        } catch (\Throwable $e) {
            Storage::disk('local')->delete([$path, static::thumbPathFor($path)]);

            logger()->warning($logMessage, ['message' => $e->getMessage()]);

            throw ValidationException::withMessages([
                $field => 'No se pudo procesar una de las fotos. Probá con otra foto o formato.',
            ]);
        }
    }
}
