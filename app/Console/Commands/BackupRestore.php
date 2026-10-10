<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backups\BackupManager;
use Illuminate\Console\Command;

/**
 * backup:restore — restaura un backup en una base y carpeta AISLADAS (para
 * probarlo o para levantar un servidor nuevo). Nunca sobre la base de la app.
 * Ver docs/backups.md para la recuperación completa.
 */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore {backup : ID del backup} {--database= : Base destino (distinta de la de la app)} {--files= : Carpeta destino de los archivos}';

    protected $description = 'Restaura un backup en una base aislada y compara las filas';

    public function handle(BackupManager $manager): int
    {
        $backup = Backup::findOrFail($this->argument('backup'));
        $database = (string) $this->option('database');
        $files = (string) ($this->option('files') ?: storage_path('app/restore/backup-'.$backup->id));

        if (! $this->confirm("Se va a restaurar el backup #{$backup->id} en la base \"{$database}\" y la carpeta {$files}. ¿Continuar?", true)) {
            return self::FAILURE;
        }

        $result = $manager->restore($backup, $database, $files);

        $bad = collect($result['tables'])->filter(fn ($t) => $t['expected'] !== $t['restored']);
        $this->table(['Tabla', 'Filas en el backup', 'Filas restauradas'], collect($result['tables'])->map(fn ($t, $name) => [$name, $t['expected'], $t['restored']])->values());
        $this->info("Archivos restaurados: {$result['files']}");

        if ($bad->isNotEmpty()) {
            $this->error('Hay tablas con filas distintas: '.$bad->keys()->implode(', '));

            return self::FAILURE;
        }

        $this->info('Restauración verificada: todas las tablas coinciden.');

        return self::SUCCESS;
    }
}
