import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface Notification {
  id: string;
  type: string;
  typeLabel: string;
  title: string;
  body: string | null;
  subjectType: string | null;
  subjectId: string | null;
  data: Record<string, unknown>;
  priority: string;
  isRead: boolean;
  readAt: string | null;
  expiresAt: string | null;
  createdAt: string | null;
}

export interface NotificationPreferences {
  [channel: string]: boolean | Record<string, boolean>;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The signed-in person's inbox.
 *
 * No id anywhere: the inbox is whoever is calling. Reading somebody else's is
 * not a thing this can be asked to do, which is what the legacy's
 * `get_notifications` with a `user_id` in the body could be.
 *
 * The unread count comes back with the list as well as on its own, so a badge
 * and the list it opens cannot disagree — see {@link listWithUnread}.
 */
@Injectable({ providedIn: 'root' })
export class NotificationsService {
  private readonly api = inject(ApiClient);

  list(filters: { unreadOnly?: boolean; page?: number; perPage?: number } = {}):
  Observable<Paged<Notification>> {
    return this.api.getPaged<Notification>(Endpoints.notifications.list, { ...filters });
  }

  /**
   * The inbox and its unread count, from one request.
   *
   * Two calls would let the badge say three while the list shows four.
   */
  listWithUnread(perPage = 10): Observable<{ items: Notification[]; unread: number }> {
    return this.api
      .getPaged<Notification>(Endpoints.notifications.list, { perPage })
      .pipe(map((page) => ({
        items: page.items,
        unread: (page.meta as { unread?: number }).unread ?? page.items.filter((n) => !n.isRead).length,
      })));
  }

  unreadCount(): Observable<{ unread: number }> {
    return this.api.get<{ unread: number }>(Endpoints.notifications.unreadCount);
  }

  markRead(id: string): Observable<Notification> {
    return this.api.post<Notification>(Endpoints.notifications.read(id), {});
  }

  markAllRead(): Observable<{ marked: number }> {
    return this.api.post<{ marked: number }>(Endpoints.notifications.readAll, {});
  }

  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.notifications.delete(id));
  }

  preferences(): Observable<NotificationPreferences> {
    return this.api.get<NotificationPreferences>(Endpoints.notifications.preferences);
  }

  savePreferences(preferences: NotificationPreferences): Observable<NotificationPreferences> {
    return this.api.put<NotificationPreferences>(Endpoints.notifications.savePreferences, preferences);
  }
}
