import { provideAnimations } from "@angular/platform-browser/animations";
import {APP_INITIALIZER, ApplicationConfig, provideZoneChangeDetection} from '@angular/core';
import { provideRouter, withInMemoryScrolling  } from '@angular/router';

import { routes } from './app.routes';
import {provideHotToastConfig} from '@ngneat/hot-toast';
import {provideHttpClient} from '@angular/common/http';
import {NG_EVENT_PLUGINS} from '@taiga-ui/event-plugins';
import {I18nService} from './i18n.service';
function initI18n(i18n: I18nService) {
  return () => i18n.init();
}
export const appConfig: ApplicationConfig = {
  providers: [
    provideAnimations(),
    provideHttpClient(),
    provideHotToastConfig(),
    NG_EVENT_PLUGINS,
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes,
      withInMemoryScrolling({
        scrollPositionRestoration: 'top', // 👈 fixes your issue
        anchorScrolling: 'enabled'
      })
    ),
    { provide: APP_INITIALIZER, useFactory: initI18n, deps: [I18nService], multi: true }
  ]
};

