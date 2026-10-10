<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backups\BackupManager;
use Illuminate\Console\Command;

/** backup:verify {id?} — comprueba que un backup se puede abrir y está completo. */
class BackupVerify extends Command
{
    protected $signature = 'backup:verify {backup? : ID (por defecto, el último completo)}';

    protected $description = 'Verifica la integridad de un backup';

    public function handle(BackupManager $manager): int
    {
        $backup = $this->argument('backup')
            ? Backup::findOrFail($this->argument('backup'))
            : Backup::where('status', Backup::COMPLETED)->whereNull('file_deleted_at')->latest('completed_at')->firstOrFail();

        $result = $manager->verify($backup);

        if (! $result['ok']) {
            foreach ($result['problems'] as $problem) {
                $this->error('  ✗ '.$problem);
            }

            return self::FAILURE;
        }

        $this->info("Backup #{$backup->id} verificado: ".count($result['manifest']['tables']).' tablas, '.$result['manifest']['files'].' archivos.');

        return self::SUCCESS;
    }
}
