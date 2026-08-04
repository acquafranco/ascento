<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\Report;

class NewReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Report $report
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'title' => 'Nuevo reporte creado',
            'body' => 'Se creó un reporte para ' . $this->report->building->name,
            'report_id' => $this->report->id,
        ];
    }
}
