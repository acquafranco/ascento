<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;

/**
 * Al técnico: su planificación de guardias cambió (o se acerca un turno).
 *
 * - assigned: le asignaron una guardia (o un cambio de guardia a su favor).
 * - removed: ya no cubre (parte de) un turno: se canceló, se finalizó o lo cubre otro técnico.
 * - upcoming: recordatorio de un turno que empieza en las próximas horas.
 *
 * Solo informa: no confirma que el técnico lo haya leído. El enlace lleva a su
 * pestaña «Guardias», que vuelve a comprobar permisos y muestra lo vigente.
 */
class OnCallNotification extends AppNotification
{
    public function __construct(
        public string $kind,
        public string $scheduleName,
        public string $period,
        public ?string $target,
        public string $key,
        public ?string $detail = null,
    ) {}

    public function title(): string
    {
        return match ($this->kind) {
            'assigned' => 'Nueva guardia asignada',
            'removed' => 'Cambió una de tus guardias',
            'upcoming' => 'Tu guardia empieza pronto',
        };
    }

    public function body(): string
    {
        return match ($this->kind) {
            'assigned' => $this->scheduleName.': '.$this->period.'.'.($this->detail ? ' '.$this->detail : ''),
            'removed' => $this->scheduleName.': ya no cubrís '.$this->period.'.'.($this->detail ? ' '.$this->detail : ''),
            'upcoming' => $this->scheduleName.': '.$this->period.'.',
        };
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function dedupeKey(): ?string
    {
        return $this->key;
    }

    public function actionLabel(): string
    {
        return 'Ver mis guardias';
    }

    public function wantsPush(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return $this->kind === 'removed' ? 'heroicon-o-arrows-right-left' : 'heroicon-o-shield-check';
    }

    public function color(): string
    {
        return $this->kind === 'removed' ? 'warning' : 'info';
    }
}
