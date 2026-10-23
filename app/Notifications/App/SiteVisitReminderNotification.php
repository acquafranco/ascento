<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;

/**
 * Al técnico: el siguiente paso de su visita técnica (SiteVisits).
 *
 * - arrival: tomó un trabajo hace rato y no registró la llegada al edificio.
 * - exit: terminó los trabajos de la visita (o pasó mucho tiempo) y no la finalizó.
 *
 * Una vez por orden / visita (clave del aviso y la marca *_reminded_at). Nunca
 * dice que salió: solo recuerda registrarlo. El enlace lleva a la pantalla que
 * vuelve a comprobar permisos y muestra el control que corresponde.
 */
class SiteVisitReminderNotification extends AppNotification
{
    public function __construct(
        public string $kind,
        public string $buildingName,
        public string $target,
        public string $key,
    ) {}

    public function title(): string
    {
        return $this->kind === 'arrival' ? '¿Ya llegaste?' : '¿Terminaste la visita?';
    }

    public function body(): string
    {
        return $this->kind === 'arrival'
            ? 'Tomaste un trabajo en '.$this->buildingName.'. Cuando estés en el edificio, registrá la llegada.'
            : 'Seguís en '.$this->buildingName.' según la app. Si ya te fuiste, finalizá la visita.';
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
        return $this->kind === 'arrival' ? 'Ver la orden' : 'Finalizar visita';
    }

    public function wantsPush(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'heroicon-o-map-pin';
    }

    public function color(): string
    {
        return 'warning';
    }
}
