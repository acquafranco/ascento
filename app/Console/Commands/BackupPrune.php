<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupManager;
use Illuminate\Console\Command;

/** backup:prune — aplica la retención (borra archivos viejos, conserva el registro). */
class BackupPrune extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Aplica la retención de backups';

    public function handle(BackupManager $manager): int
    {
        $this->info('Archivos de backup borrados: '.$manager->prune());

        return self::SUCCESS;
    }
}
