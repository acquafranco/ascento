<?php

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso interno de Ascento (lo entrega App\Services\Notifications\Notifier).
 *
 * - Se guarda en `notifications` con el formato de Filament: el admin lo ve en
 *   la campanita del panel y el técnico / cliente en su bandeja
 *   (/notificaciones o /portal/notificaciones). Un solo formato para todos.
 * - El enlace es una ruta relativa: al abrirlo, la pantalla destino vuelve a
 *   comprobar los permisos (un aviso viejo no da acceso a nada).
 * - Correo y push son opcionales y por tipo de aviso (ver docs).
 * - Nunca lleva contraseñas, tokens ni datos que el destinatario no pueda ver.
 */
abstract class AppNotification extends Notification
{
    abstract public function title(): string;

    abstract public function body(): string;

    /** Ruta relativa ("/portal/remitos/00000012"); null si no hay a dónde ir. */
    abstract public function path(): ?string;

    /**
     * Clave del evento: el mismo evento no se avisa dos veces al mismo
     * usuario (índice único). Null = sin deduplicación.
     */
    abstract public function dedupeKey(): ?string;

    /** Edificio al que se refiere (obligatorio para avisos a clientes). */
    public function buildingId(): ?int
    {
        return null;
    }

    public function actionLabel(): string
    {
        return 'Ver';
    }

    public function icon(): string
    {
        return 'heroicon-o-bell';
    }

    public function color(): string
    {
        return 'info';
    }

    /** Correo para este destinatario, o null si este aviso no va por correo. */
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }

    /** Push a los dispositivos del usuario (si los activó). */
    public function wantsPush(): bool
    {
        return false;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon($this->icon())
            ->iconColor($this->color());

        if ($path = $this->path()) {
            $notification->actions([
                Action::make('view')->label($this->actionLabel())->url(url($path))->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage() + ['path' => $path];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/images/pwa/icon-192.png')
            ->badge('/images/pwa/badge-96.png')
            ->tag($this->dedupeKey() ?? class_basename($this))
            ->data(['url' => $this->path() ?? '/'])
            ->options(['TTL' => 24 * 3600]);
    }

    /** Correo con el formato común de Ascento. */
    protected function mail(string $subject, array $lines, ?string $action = null): MailMessage
    {
        $message = (new MailMessage)->subject($subject.' - Ascento')->greeting('Hola');

        foreach ($lines as $line) {
            $message->line($line);
        }

        if ($path = $this->path()) {
            $message->action($action ?? $this->actionLabel(), url($path));
        }

        return $message->salutation('Ascento');
    }
}
