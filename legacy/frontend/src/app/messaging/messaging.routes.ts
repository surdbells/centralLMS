// messaging/messaging.routes.ts

import { Routes } from '@angular/router';
import { MessagingComponent } from './messaging.component';
import { MessagingSettingsComponent } from './settings/messaging-settings.component';
import { SavedMessagesComponent } from './saved/saved-messages.component';
import { BroadcastManagementComponent } from './broadcast/broadcast-management.component';

// Routes for lazy loading standalone components
// Usage in app.routes.ts:
// { path: 'messaging', loadChildren: () => import('./messaging/messaging.routes').then(m => m.MESSAGING_ROUTES) }

export const MESSAGING_ROUTES: Routes = [
  {
    path: '',
    component: MessagingComponent
  },
  {
    path: 'conversation/:id',
    component: MessagingComponent
  },
  {
    path: 'settings',
    component: MessagingSettingsComponent
  },
  {
    path: 'saved',
    component: SavedMessagesComponent
  },
  {
    path: 'broadcast',
    component: BroadcastManagementComponent
  }
];
