import { Routes } from '@angular/router';
import { websiteRoutes } from './website/website.routes';

/**
 * Every page this application serves.
 *
 * The application itself is not among them — it lives at its own address and
 * opens on the sign-in screen. Anything unrecognised here is the home page
 * rather than a blank one.
 */
export const routes: Routes = [
  ...websiteRoutes,
  {
    path: '**',
    redirectTo: '',
  },
];
