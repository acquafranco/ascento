<?php

namespace App\Notifications\App;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Aviso de una tarea (Etapa 6, PR 3) a su responsable: «te asignaron» o el
 * recordatorio programado. Uno por tarea, motivo y fecha (dedupe): un
 * reintento del scheduler o una doble asignación no avisan dos veces.
 */
class TaskNotification extends AppNotification
{
    public const ASSIGNED = 'assigned';

    public const REMINDER = 'reminder';

    public function __construct(public Task $task, public string $reason) {}

    public function title(): string
    {
        return $this->reason === self::REMINDER ? 'Recordatorio: '.$this->task->title : 'Te asignaron una tarea';
    }

    public function body(): string
    {
        return collect([
            $this->reason === self::ASSIGNED ? $this->task->title : null,
            $this->task->due_at ? 'Vence el '.$this->task->due_at->format('d/m/Y H:i') : null,
            'Prioridad: '.(Task::PRIORITIES[$this->task->priority] ?? $this->task->priority),
        ])->filter()->implode(' · ');
    }

    public function path(): ?string
    {
        // «Mis tareas» (el responsable con solo «ver» también la abre y la completa desde ahí).
        $url = TaskResource::getUrl('index', ['tab' => 'mine'], panel: 'ascensores_app');

        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function dedupeKey(): ?string
    {
        return 'task:'.$this->task->id.':'.$this->reason.':'.($this->reason === self::REMINDER ? $this->task->remind_at?->format('YmdHi') : $this->task->assigned_to);
    }

    public function actionLabel(): string
    {
        return 'Ver tarea';
    }

    public function icon(): string
    {
        return $this->reason === self::REMINDER ? 'heroicon-o-bell-alert' : 'heroicon-o-clipboard-document-check';
    }

    public function color(): string
    {
        return in_array($this->task->priority, ['high', 'urgent'], true) ? 'danger' : 'info';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return null; // en la app (campanita y push), no por correo
    }
}
