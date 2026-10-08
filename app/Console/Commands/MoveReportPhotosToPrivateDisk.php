<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportPhoto;
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

        // Paths de la tabla de fotos y de la columna vieja (una sola foto).
        $paths = ReportPhoto::withoutGlobalScopes()->pluck('path')
            ->merge(Report::withoutGlobalScopes()->withTrashed()->whereNotNull('photo')->pluck('photo'))
            ->filter()
            ->unique();

        foreach ($paths as $path) {
            if (str_contains($path, '..') || ! str_starts_with($path, 'reports/') || ! $public->exists($path)) {
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

        // Lo que quede en public/reports sin ningún reporte que lo use.
        $known = $paths->flip();
        $orphans = collect($public->allFiles('reports'))->reject(fn ($path) => $known->has($path));

        if ($orphans->isNotEmpty()) {
            $this->warn('Archivos en el disco público sin reporte asociado (no se tocan; revisalos): '.$orphans->count());
            $orphans->take(20)->each(fn ($path) => $this->line('  '.$path));
        }

        $this->info("Fotos movidas: {$moved}.");

        return self::SUCCESS;
    }
}
