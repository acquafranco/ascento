<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportVideoService;
use Illuminate\Console\Command;

/** Comprime los videos de reportes pendientes (solo si el servidor tiene FFmpeg). */
class ProcessReportVideos extends Command
{
    protected $signature = 'media:process-videos {--max=2}';

    protected $description = 'Comprime los videos de reportes pendientes';

    public function handle(ReportVideoService $service): int
    {
        $this->info('Videos procesados: '.$service->processPending((int) $this->option('max')));

        return self::SUCCESS;
    }
}
