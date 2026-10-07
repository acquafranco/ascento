import './bootstrap';

import Alpine from 'alpinejs';
import { listenForNavigation, pushNotificationsComponent, silentSync } from './push-notifications';

window.Alpine = Alpine;

Alpine.data('pushNotifications', pushNotificationsComponent);

Alpine.start();

listenForNavigation();
silentSync();
