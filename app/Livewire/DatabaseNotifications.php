<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Realtime;
use Filament\Livewire\DatabaseNotifications as BaseDatabaseNotifications;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Campanita del panel (admins de empresa y SuperAdmin), con las mismas reglas
 * que la bandeja de técnicos y clientes:
 *
 * - La lista se dibuja en resources/views/vendor/filament-notifications/
 *   database-notifications.blade.php. Antes no mostraba ningún botón: los
 *   avisos no se podían abrir ni marcar de a uno.
 * - "Abrir" pasa por /notificaciones/{id}, que marca leído en el servidor y
 *   redirige (no depende de un evento del navegador que se corta al navegar).
 * - Todo aviso sin leer tiene "Marcar como leída" (también los que no tienen
 *   destino, como "Falló un backup").
 * - Si algún componente dispara "notificationClosed", se marca leído: nunca
 *   se borra un aviso por cerrarlo.
 * - Al cerrar la campanita se marcan leídos los avisos que llegaron a verse
 *   en pantalla mientras estaba abierta (ver filament/partials/notifications-seen).
 *   Los que no se vieron (más abajo, sin desplazar) quedan sin leer.
 * - Cada cambio avisa a las otras pestañas del usuario (Realtime).
 *
 * Todo pasa por getNotificationsQuery(): solo los avisos del usuario
 * autenticado. Un id ajeno o inválido no hace nada.
 */
class DatabaseNotifications extends BaseDatabaseNotifications
{
    public const SEEN_EVENT = 'ascento-notifications-seen';

    private const MAX_IDS = 100;

    #[On('notificationClosed')]
    public function removeNotification(string $id): void
    {
        $this->markNotificationAsRead($id);
    }

    #[On('markedNotificationAsRead')]
    public function markNotificationAsRead(string $id): void
    {
        $this->markRead([$id]);
    }

    #[On('markedNotificationAsUnread')]
    public function markNotificationAsUnread(string $id): void
    {
        if (Str::isUuid($id) && $this->getNotificationsQuery()->whereKey($id)->whereNotNull('read_at')->update(['read_at' => null])) {
            $this->announce();
        }
    }

    /** @param  array<int, mixed>  $ids  avisos que se vieron con la campanita abierta */
    #[On(self::SEEN_EVENT)]
    public function markSeen(array $ids = []): void
    {
        $this->markRead($ids);
    }

    public function markAllNotificationsAsRead(): void
    {
        if ($this->getUnreadNotificationsQuery()->update(['read_at' => now()])) {
            $this->announce();
        }
    }

    public function clearNotifications(): void
    {
        parent::clearNotifications();
        $this->announce();
    }

    /** @param  array<int, mixed>  $ids */
    private function markRead(array $ids): void
    {
        $ids = collect($ids)->take(self::MAX_IDS)->filter(fn ($id) => is_string($id) && Str::isUuid($id))->values()->all();

        if ($ids !== [] && $this->getUnreadNotificationsQuery()->whereIn('id', $ids)->update(['read_at' => now()])) {
            $this->announce();
        }
    }

    private function announce(): void
    {
        $user = $this->getUser();
        $user instanceof User && Realtime::notificationsChanged($user);
    }
}
