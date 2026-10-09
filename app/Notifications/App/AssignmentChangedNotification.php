<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;

/**
 * Al técnico: cambios en lo que tiene asignado.
 *
 * - assigned: nuevo edificio (mantenimiento / inspección), con enlace a su lista.
 * - unassigned / wo_removed / wo_cancelled: ya no lo tiene. Sin enlace ni
 *   detalle del trabajo (perdió el acceso); solo lo necesario para que sepa
 *   que no tiene que ir.
 */
class AssignmentChangedNotification extends AppNotification
{
    public function __construct(
        public string $kind,
        public string $subject,
        public ?string $target = null,
        public ?string $key = null,
    ) {}

    public function title(): string
    {
        return match ($this->kind) {
            'assigned' => 'Nuevo edificio asignado',
            'unassigned' => 'Te quitaron una asignación',
            'wo_removed' => 'Ya no tenés asignada una orden',
            'wo_cancelled' => 'Orden de trabajo cancelada',
        };
    }

    public function body(): string
    {
        return match ($this->kind) {
            'assigned' => 'Te asignaron '.$this->subject.'.',
            'unassigned' => 'Ya no tenés asignado '.$this->subject.'.',
            'wo_removed' => 'Te quitaron de '.$this->subject.'. No tenés que ir.',
            'wo_cancelled' => 'Se canceló '.$this->subject.'. No tenés que ir.',
        };
    }

    public function path(): ?string
    {
        return $this->kind === 'assigned' ? $this->target : null;
    }

    public function dedupeKey(): ?string
    {
        return $this->key;
    }

    public function wantsPush(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return $this->kind === 'assigned' ? 'heroicon-o-building-office' : 'heroicon-o-x-circle';
    }

    public function color(): string
    {
        return $this->kind === 'assigned' ? 'info' : 'warning';
    }
}
