import { provideAnimations } from '@angular/platform-browser/animations';
import {
  ApplicationConfig,
  provideAppInitializer,
  provideZoneChangeDetection,
  inject,
} from '@angular/core';
import { provideRouter, TitleStrategy, withInMemoryScrolling } from '@angular/router';

import { routes } from './app.routes';
import { provideHotToastConfig } from '@ngneat/hot-toast';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { NG_EVENT_PLUGINS } from '@taiga-ui/event-plugins';
import { I18nService } from './i18n.service';
import { authInterceptor, requestIdInterceptor } from './core/api';
import { enableBootstrapTabs } from './core/bootstrap-tabs';
import { BrandingTitleStrategy } from './core/branding-title.strategy';

export const appConfig: ApplicationConfig = {
  providers: [
    provideAnimations(),

    /**
     * Interceptor order matters: `authInterceptor` may rewrite the body to add
     * legacy credentials, so it runs first and `requestIdInterceptor` only
     * adds a header afterwards.
     */
    provideHttpClient(withInterceptors([authInterceptor, requestIdInterceptor])),

    provideHotToastConfig(),
    NG_EVENT_PLUGINS,
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(
      routes,
      withInMemoryScrolling({
        scrollPositionRestoration: 'top',
        anchorScrolling: 'enabled',
      }),
    ),

    // Compose every tab title with the organisation name instead of a fixed
    // product name (see BrandingTitleStrategy / BrandingService).
    { provide: TitleStrategy, useClass: BrandingTitleStrategy },

    // Replaces the deprecated APP_INITIALIZER token, removed in Angular 20.
    provideAppInitializer(() => inject(I18nService).init()),

    // Bootstrap's tab handler, which the vendor bundle leaves unregistered.
    provideAppInitializer(() => enableBootstrapTabs()),
  ],
};
