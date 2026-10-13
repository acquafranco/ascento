<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;
use App\Services\Parachute\ParachuteService;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Recordatorio de una prueba de paracaídas próxima o vencida (Etapa 7). Es
 * solo un aviso: dice que la prueba TODAVÍA NO está registrada, nunca la da
 * por hecha. Uno por ascensor, motivo, fecha prevista y destinatario (clave
 * del evento): un reintento no duplica y una fecha nueva avisa de nuevo.
 */
class ParachuteReminderNotification extends AppNotification
{
    public function __construct(
        public string $status,
        public int $elevatorId,
        public string $elevatorLabel,
        public string $buildingName,
        public string $dueDate,
        public string $target,
    ) {}

    public static function keyPrefix(int $elevatorId): string
    {
        return 'parachute:'.$elevatorId.':';
    }

    private function date(): string
    {
        return Carbon::parse($this->dueDate)->format('d/m/Y');
    }

    public function title(): string
    {
        return $this->status === ParachuteService::OVERDUE ? 'Prueba de paracaídas vencida' : 'Prueba de paracaídas próxima';
    }

    public function body(): string
    {
        $what = trim($this->elevatorLabel.($this->buildingName !== '' ? ' · '.$this->buildingName : ''));

        return $what.': '.($this->status === ParachuteService::OVERDUE ? 'estaba prevista para el ' : 'prevista para el ').$this->date()
            .'. Todavía no hay una prueba registrada para esa fecha.';
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function dedupeKey(): ?string
    {
        return self::keyPrefix($this->elevatorId).$this->status.':'.$this->dueDate;
    }

    public function actionLabel(): string
    {
        return 'Ver pruebas';
    }

    public function icon(): string
    {
        return 'heroicon-o-shield-exclamation';
    }

    public function color(): string
    {
        return $this->status === ParachuteService::OVERDUE ? 'danger' : 'warning';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return null; // en la app (campanita y push), no por correo
    }
}
