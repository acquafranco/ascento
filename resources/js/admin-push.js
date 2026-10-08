/**
 * Avisos push para el ADMIN dentro del panel (Filament): mismo componente
 * que usan los técnicos (resources/js/push-notifications.js).
 */
import { listenForNavigation, pushNotificationsComponent, silentSync } from './push-notifications';

function register() {
    window.Alpine.data('pushNotifications', pushNotificationsComponent);
}

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}

listenForNavigation();
silentSync();
