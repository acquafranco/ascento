<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Tus contadores cambiaron". No lleva datos de trabajos ni de personas: la
 * pantalla vuelve a pedir sus números al servidor (con sus permisos), así un
 * evento repetido o perdido nunca deja un contador mal. Va por el canal
 * privado de cada usuario afectado (un solo envío para todos).
 */
class CountersChanged implements ShouldBroadcastNow
{
    /** @param array<int> $userIds */
    public function __construct(public array $userIds) {}

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'dashboard.counters';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
