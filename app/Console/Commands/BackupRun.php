<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backups\BackupManager;
use Illuminate\Console\Command;

/** backup:run — backup automático (lo programa el scheduler todos los días). */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--type=scheduled : scheduled | manual}';

    protected $description = 'Genera un backup completo de Ascento (base + archivos)';

    public function handle(BackupManager $manager): int
    {
        $backup = $manager->run($manager->request($this->option('type') === 'manual' ? 'manual' : 'scheduled'));

        if ($backup->status !== Backup::COMPLETED) {
            $this->error('El backup falló: '.$backup->error);

            return self::FAILURE;
        }

        $this->info("Backup #{$backup->id} completo: {$backup->sizeLabel()}, {$backup->summary['rows']} filas, {$backup->summary['files']} archivos"
            .($backup->encrypted ? ', cifrado' : ', SIN cifrar (falta BACKUP_ARCHIVE_PASSWORD)')
            .($backup->summary['missing_referenced_files'] ? ", {$backup->summary['missing_referenced_files']} archivos mencionados que no estaban" : ''));

        return self::SUCCESS;
    }
}
