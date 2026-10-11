import './bootstrap';

import Alpine from 'alpinejs';
import { listenForNavigation, pushNotificationsComponent, silentSync } from './push-notifications';
import { initTechUi } from './tech-ui';

window.Alpine = Alpine;

Alpine.data('pushNotifications', pushNotificationsComponent);

Alpine.start();

initTechUi();
listenForNavigation();
silentSync();
