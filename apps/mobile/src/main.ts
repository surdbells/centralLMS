import { bootstrapApplication } from '@angular/platform-browser';
import { inject, provideAppInitializer } from '@angular/core';
import {
  PreloadAllModules,
  RouteReuseStrategy,
  provideRouter,
  withPreloading,
} from '@angular/router';
import { IonicRouteStrategy, provideIonicAngular } from '@ionic/angular/standalone';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { Capacitor } from '@capacitor/core';

import { AppComponent } from './app/app.component';
import { routes } from './app/app.routes';
import { authInterceptor } from './app/core/auth.interceptor';
import { I18nService } from './app/core/i18n.service';
import { environment } from './environments/environment';

bootstrapApplication(AppComponent, {
  providers: [
    { provide: RouteReuseStrategy, useClass: IonicRouteStrategy },
    provideIonicAngular({ mode: 'ios' }),
    provideRouter(routes, withPreloading(PreloadAllModules)),
    provideHttpClient(withInterceptors([authInterceptor])),
    // Load the remembered language (and set RTL for Arabic) before first paint.
    provideAppInitializer(() => inject(I18nService).init()),
  ],
}).catch((err) => console.error(err));

// Register the PWA service worker (app-shell offline + installability). Only in
// a real browser build: never inside the Capacitor native shell — its assets
// are already local and a WebView service worker would only get in the way —
// and only in production, so dev's live reload is never shadowed by a cache.
if (
  environment.production &&
  'serviceWorker' in navigator &&
  !Capacitor.isNativePlatform()
) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('sw.js')
      .catch((err) => console.error('Service worker registration failed', err));
  });
}
