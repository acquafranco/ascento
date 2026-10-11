<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupManager;
use Illuminate\Console\Command;

/** backup:process — genera los backups manuales pedidos desde el panel (scheduler, cada minuto). */
class BackupProcess extends Command
{
    protected $signature = 'backup:process';

    protected $description = 'Genera los backups manuales pendientes';

    public function handle(BackupManager $manager): int
    {
        $this->info('Backups generados: '.$manager->processPending());

        return self::SUCCESS;
    }
}
