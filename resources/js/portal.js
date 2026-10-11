/**
 * Portal del cliente: avisos push con el mismo componente que usan técnicos y
 * admins (resources/js/push-notifications.js). El permiso del navegador se
 * pide solo cuando la persona toca "Activar".
 */
import Alpine from 'alpinejs';
import { listenForNavigation, pushNotificationsComponent, silentSync } from './push-notifications';

window.Alpine = window.Alpine || Alpine;
window.Alpine.data('pushNotifications', pushNotificationsComponent);
window.Alpine.start();

listenForNavigation();
silentSync();
