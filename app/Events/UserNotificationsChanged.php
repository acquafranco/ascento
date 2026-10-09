<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\DatabaseNotification;

/**
 * "Tu bandeja cambió": contador de no leídos y, si es un aviso nuevo, su
 * título y texto (que el usuario ya tiene derecho a ver: es SU aviso). Va por
 * el canal privado del usuario con el nombre que escucha Filament.
 */
class UserNotificationsChanged implements ShouldBroadcastNow
{
    public function __construct(public User $user, public ?DatabaseNotification $latest = null) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->user->id);
    }

    public function broadcastAs(): string
    {
        return 'database-notifications.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'unread' => $this->user->unreadNotifications()->count(),
            'notification' => $this->latest ? [
                'id' => $this->latest->id,
                'title' => (string) ($this->latest->data['title'] ?? ''),
                'body' => (string) ($this->latest->data['body'] ?? ''),
            ] : null,
        ];
    }
}
