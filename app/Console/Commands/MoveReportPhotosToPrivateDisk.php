<?php

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Las fotos de reportes subidas antes de este cambio quedaron en el disco
 * público (accesibles por URL sin login). Este comando las copia al disco
 * privado y, recién cuando la copia está verificada, borra la pública.
 *
 * Es idempotente y no toca la base de datos (el path relativo no cambia).
 * Usar --dry-run primero.
 */
class MoveReportPhotosToPrivateDisk extends Command
{
    protected $signature = 'reports:move-photos-private {--dry-run : Solo muestra qué movería}';

    protected $description = 'Mueve las fotos de reportes del disco público al privado.';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $moved = 0;

        Report::withoutGlobalScopes()->withTrashed()->whereNotNull('photo')->chunkById(200, function ($reports) use ($public, $private, &$moved) {
            foreach ($reports as $report) {
                $path = $report->photo;

                if (str_contains($path, '..') || ! $public->exists($path)) {
                    continue;
                }

                $this->line(($this->option('dry-run') ? '[dry-run] ' : '').$path);

                if ($this->option('dry-run')) {
                    continue;
                }

                if (! $private->exists($path)) {
                    $private->writeStream($path, $public->readStream($path));
                }

                if ($private->size($path) === $public->size($path)) {
                    $public->delete($path);
                    $moved++;
                } else {
                    $this->error("La copia de {$path} no coincide; se deja la original.");
                }
            }
        });

        $this->info("Fotos movidas: {$moved}.");

        return self::SUCCESS;
    }
}
