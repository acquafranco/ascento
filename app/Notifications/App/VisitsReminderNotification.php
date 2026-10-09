<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Recordatorios mensuales de la agenda (los manda notifications:visits).
 *
 * - pending (técnico, desde el día 20): edificios suyos sin remito este mes.
 * - overdue (técnico, día 1): los que quedaron sin remito el mes anterior.
 * - overdue_summary (admin, día 1, también por correo): total vencido del mes.
 *
 * Una vez por mes y destinatario (clave del evento). El técnico trabaja a su
 * ritmo: nada se avisa como vencido antes de que termine el mes.
 */
class VisitsReminderNotification extends AppNotification
{
    public function __construct(
        public string $kind,
        public string $period,
        public int $count,
        public string $target,
    ) {}

    private function monthName(): string
    {
        return Carbon::createFromFormat('Y-m', $this->period)->translatedFormat('F Y');
    }

    public function title(): string
    {
        return match ($this->kind) {
            'pending' => 'Visitas pendientes este mes',
            'overdue' => 'Visitas que quedaron sin hacer',
            'overdue_summary' => 'Mantenimientos e inspecciones vencidos',
        };
    }

    public function body(): string
    {
        $n = $this->count.' '.($this->count === 1 ? 'edificio' : 'edificios');

        return match ($this->kind) {
            'pending' => "Te quedan {$n} sin remito en {$this->monthName()}.",
            'overdue' => "En {$this->monthName()} quedaron {$n} sin remito.",
            'overdue_summary' => "En {$this->monthName()} quedaron {$this->count} ".($this->count === 1 ? 'visita' : 'visitas').' sin remito. Revisalas en la agenda.',
        };
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function dedupeKey(): ?string
    {
        return 'visits-'.$this->kind.':'.$this->period;
    }

    public function wantsPush(): bool
    {
        return $this->kind !== 'overdue_summary';
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public function color(): string
    {
        return $this->kind === 'pending' ? 'info' : 'warning';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return $this->kind === 'overdue_summary' ? $this->mail($this->title(), [$this->body()], 'Ver agenda') : null;
    }
}
