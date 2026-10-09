<?php

namespace App\Services\Exports;

use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de una exportación: se solicita (admin), la genera el scheduler
 * (`exports:process`, cada minuto, sin límite de tiempo de PHP-FPM), se
 * descarga mientras no venza y `exports:prune` borra el archivo vencido
 * dejando el registro como historial.
 */
class CompanyExportService
{
    public function __construct(private CompanyDataExporter $exporter) {}

    public function request(User $user): CompanyExport
    {
        abort_unless($user->isAdmin() && ! $user->isSuperAdmin() && $user->company_id, 403);

        return DB::transaction(function () use ($user) {
            // Una a la vez por empresa (lock para dos clics simultáneos).
            Company::whereKey($user->company_id)->lockForUpdate()->first();

            $base = CompanyExport::withoutGlobalScopes()->where('company_id', $user->company_id);

            if ((clone $base)->whereIn('status', [CompanyExport::REQUESTED, CompanyExport::GENERATING])->exists()) {
                throw ValidationException::withMessages(['export' => 'Ya hay una exportación en preparación. Esperá a que termine.']);
            }

            if ((clone $base)->where('created_at', '>=', now()->subDay())->count() >= CompanyExport::DAILY_LIMIT) {
                throw ValidationException::withMessages(['export' => 'Llegaste al máximo de '.CompanyExport::DAILY_LIMIT.' exportaciones por día. Probá de nuevo mañana.']);
            }

            $export = new CompanyExport;
            $export->forceFill(['company_id' => $user->company_id, 'requested_by' => $user->id, 'status' => CompanyExport::REQUESTED])->save();

            return $export;
        });
    }

    public function process(CompanyExport $export): CompanyExport
    {
        // Tomarla de forma atómica (dos procesos nunca generan la misma).
        $taken = CompanyExport::withoutGlobalScopes()->whereKey($export->id)->where('status', CompanyExport::REQUESTED)
            ->update(['status' => CompanyExport::GENERATING, 'started_at' => now(), 'updated_at' => now()]);

        if ($taken === 0) {
            return $export->fresh();
        }

        $export->refresh();

        try {
            $result = $this->exporter->export($export);

            $export->forceFill([
                'status' => CompanyExport::COMPLETED,
                'path' => $result['path'],
                'file_name' => $result['file_name'],
                'size' => $result['size'],
                'record_count' => $result['record_count'],
                'categories' => $result['categories'],
                'warnings' => $result['warnings'] ?: null,
                'completed_at' => now(),
                'expires_at' => now()->addDays(CompanyExport::KEEP_DAYS),
            ])->save();
        } catch (\Throwable $e) {
            // Al usuario, un motivo entendible. Al log, dónde falló pero no el
            // mensaje: el de un error SQL incluye los valores (datos personales).
            Log::error('Falló una exportación de empresa', ['export_id' => $export->id, 'company_id' => $export->company_id,
                'exception' => $e::class, 'at' => basename($e->getFile()).':'.$e->getLine()]);

            $export->forceFill([
                'status' => CompanyExport::FAILED,
                'error' => 'No se pudo generar la exportación. Probá de nuevo; si vuelve a fallar, escribinos a '.config('app.support_email').'.',
                'completed_at' => now(),
            ])->save();
        }

        return $export->fresh();
    }

    /** Pendientes (las más viejas primero) y las que quedaron colgadas. */
    public function processPending(int $max = 3): int
    {
        CompanyExport::withoutGlobalScopes()->where('status', CompanyExport::GENERATING)->where('started_at', '<', now()->subMinutes(30))
            ->update(['status' => CompanyExport::FAILED, 'error' => 'La exportación se interrumpió. Probá de nuevo.', 'completed_at' => now()]);

        $done = 0;

        CompanyExport::withoutGlobalScopes()->where('status', CompanyExport::REQUESTED)->orderBy('id')->limit($max)->get()
            ->each(function (CompanyExport $export) use (&$done) {
                $this->process($export);
                $done++;
            });

        return $done;
    }

    /** Borra los archivos vencidos (el historial queda). */
    public function prune(): int
    {
        $count = 0;

        CompanyExport::withoutGlobalScopes()->whereNotNull('path')->whereNull('file_deleted_at')->where('expires_at', '<', now())
            ->each(function (CompanyExport $export) use (&$count) {
                if (str_starts_with((string) $export->path, 'exports/'.$export->company_id.'/') && ! str_contains((string) $export->path, '..')) {
                    Storage::disk('local')->delete($export->path);
                }

                $export->forceFill(['path' => null, 'file_deleted_at' => now()])->save();
                $count++;
            });

        // Temporales huérfanos (un Excel a medio armar si el proceso murió).
        foreach (Storage::disk('local')->allFiles('exports') as $file) {
            if (str_contains(basename($file), 'tmp-') && Storage::disk('local')->lastModified($file) < now()->subDay()->timestamp) {
                Storage::disk('local')->delete($file);
            }
        }

        return $count;
    }
}
