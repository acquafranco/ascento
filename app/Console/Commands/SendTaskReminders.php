<?php

namespace App\Console\Commands;

use App\Services\Tasks\TaskService;
use Illuminate\Console\Command;

/** Recordatorios de tareas vencidos: uno por tarea y fecha (Etapa 6, PR 3). */
class SendTaskReminders extends Command
{
    protected $signature = 'tasks:remind';

    protected $description = 'Avisa los recordatorios de tareas que llegaron a su hora';

    public function handle(TaskService $tasks): int
    {
        $this->info('Recordatorios enviados: '.$tasks->sendReminders());

        return self::SUCCESS;
    }
}
