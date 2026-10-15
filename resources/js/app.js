import './bootstrap';

import Alpine from 'alpinejs';
import { listenForNavigation, pushNotificationsComponent, silentSync } from './push-notifications';
import { initTechUi } from './tech-ui';
import { initDrafts } from './drafts';
import { initTechLocation } from './tech-location';

window.Alpine = Alpine;

Alpine.data('pushNotifications', pushNotificationsComponent);

Alpine.start();

initTechUi();
initDrafts();
listenForNavigation();
silentSync();
initTechLocation();
