<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportPhotoService;
use Illuminate\Console\Command;
use Intervention\Image\Encoders\JpegEncoder;

/**
 * Optimiza las capturas de la landing (tools/landing-screenshots) y las deja
 * en public/images/landing: JPEG liviano, teléfono 640 px de ancho y
 * escritorio 1280×800, para que el carrusel cargue rápido y sin cortes.
 */
class OptimizeLandingScreenshots extends Command
{
    protected $signature = 'landing:optimize-screenshots {source : Carpeta con los PNG capturados} {--quality=78}';

    protected $description = 'Optimiza las capturas de la landing y las copia a public/images/landing';

    public function handle(): int
    {
        $source = rtrim((string) $this->argument('source'), '/');
        $target = public_path('images/landing');
        if (! is_dir($source)) {
            $this->error("No existe {$source}");

            return self::FAILURE;
        }
        @mkdir($target, 0755, true);

        foreach (glob($source.'/*.png') as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $image = ReportPhotoService::manager()->decode(fopen($file, 'rb'));
            $image->width() > $image->height()
                ? $image->cover(1280, 800, 'top')
                : $image->scaleDown(width: 640);
            $image->encode(new JpegEncoder(quality: (int) $this->option('quality'), progressive: true))->save($target.'/'.$name.'.jpg');
            $this->line(sprintf('%-22s %4d×%-4d %5.0f KB', $name, $image->width(), $image->height(), filesize($target.'/'.$name.'.jpg') / 1024));
        }

        return self::SUCCESS;
    }
}
