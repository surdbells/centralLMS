import { Component, OnDestroy, OnInit, effect, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { NotificationsService } from '../../services/notifications.service';
import { MyProfileService } from '../../services/my-profile.service';
import { SessionStore } from '../../core/auth/session.store';
import { AuthService } from '../../core/auth/auth.service';
import { ApiClient, AuthImgDirective } from '../../core/api';
import { BrandingService } from '../../core/branding.service';

/** One item in the bell dropdown, in the shape the template already reads. */
export interface Notifications {
  id: string;
  message: string;
  is_read: boolean;
  created_at: string | null;
}

/**
 * The bar across the top of every learner screen.
 *
 * The bell never rang. `get_notifications()` was commented out of `ngOnInit`,
 * so the badge was permanently zero and the list only filled once somebody
 * clicked — and when they did, the request went to the legacy host with an id
 * and a token pulled out of the `SESSION` blob in `sessionStorage`. It reads
 * the caller's own inbox now, and takes no id.
 *
 * `user_session` is kept as the name the template binds to, filled from
 * {@link SessionStore} and the person's own record. The four `is_*` booleans
 * the legacy wrote at sign-in are worked out from what somebody may actually
 * do, because nothing kept those booleans in step with their roles.
 */
@Component({
  selector: 'app-top',
  standalone: true,
  imports: [CommonModule, RouterLink, LanguageSwitcherComponent, TuiIcon, RouterLinkActive, AuthImgDirective],
  templateUrl: './top.component.html',
  styleUrl: './top.component.css',
})
export class TopComponent implements OnInit, OnDestroy {
  private readonly notificationsService = inject(NotificationsService);
  private readonly profiles = inject(MyProfileService);
  private readonly session = inject(SessionStore);
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiClient);
  private readonly router = inject(Router);
  /** The organisation's logo and name, painted by BrandingService. */
  readonly branding = inject(BrandingService);
  private readonly toast = inject(HotToastService);
  private readonly destroy$ = new Subject<void>();

  notifications: Notifications[] = [];

  ui_controls = {
    is_loading: false,
    count: 0,
  };

  /**
   * Keeps the badge on the one unread count there is.
   *
   * The inbox screen marks things read as well, and each screen holding
   * its own copy of the number meant the badge went on saying the old
   * one until the page was reloaded.
   */
  private readonly badge = effect(() => {
    this.ui_controls.count = this.notificationsService.unreadCount$();
  });

  /**
   * What the template reads about the signed-in person.
   *
   * `avatar` holds the API's URL rather than base64 — see {@link avatarSrc},
   * the one binding in the markup that had to change with it.
   */
  user_session = {
    id: '',
    tenant_id: '',
    first_name: '',
    last_name: '',
    email: '',
    avatar: '',
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  /** The template writes `IS_CURRENT` before crossing into administration. */
  protected readonly sessionStorage = sessionStorage;

  ngOnInit(): void {
    const session = this.session.session();

    if (session === null) {
      return;
    }

    const can = this.session.can();

    this.user_session = {
      ...this.user_session,
      id: session.userId,
      tenant_id: session.tenantId,
      email: session.email ?? '',
      // Derived from what this person may actually do, not from four booleans
      // written once at sign-in that nothing kept in step with their roles.
      is_admin: can('user.manage') || can('course.publish') || can('settings.manage'),
      is_content: can('course.manage'),
      is_instructor: can('progress.manage'),
      is_learner: can('enrollment.self'),
    };

    this.profiles
      .show()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (me) => {
          this.user_session = {
            ...this.user_session,
            first_name: me.firstName,
            last_name: me.lastName,
            email: me.email,
            avatar: me.avatarUrl ?? '',
          };
        },
        // The bar is navigation. It stays up whatever the profile call did.
        error: () => undefined,
      });

    // On load, not on click. The badge is the whole point of a bell.
    this.get_notifications();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  /** The avatar's address, or empty when there is none to show. */
  get avatarSrc(): string {
    return this.user_session.avatar === '' ? '' : this.api.urlFor(this.user_session.avatar);
  }

  get_notifications(): void {
    this.ui_controls.is_loading = true;

    // The list and its unread count from one request, so the badge and the
    // dropdown it opens cannot disagree.
    this.notificationsService
      .listWithUnread()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ items, unread }) => {
          this.notifications = items.map((n) => ({
            id: n.id,
            // The bell shows one line; the title is that line, and the body is
            // the detail the notifications screen shows.
            message: n.title,
            is_read: n.isRead,
            created_at: n.createdAt,
          }));
          this.ui_controls.count = unread;
          this.ui_controls.is_loading = false;
        },
        // A quiet bell beats an error toast on every page load.
        error: () => {
          this.notifications = [];
          this.ui_controls.is_loading = false;
        },
      });
  }

  mark_notifications(): void {
    this.ui_controls.is_loading = true;

    this.notificationsService
      .markAllRead()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.get_notifications(),
        error: () => {
          this.error_notification('Could not mark them as read.');
          this.ui_controls.is_loading = false;
        },
      });
  }

  /**
   * Opens one, and marks it read on the way.
   *
   * Reading a notification without marking it read is how a badge stays at
   * eleven for ever.
   */
  open_notification(notification: Notifications): void {
    if (!notification.is_read) {
      this.notificationsService
        .markRead(notification.id)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: ({ unreadCount }) => {
            notification.is_read = true;
            this.ui_controls.count = unreadCount;
          },
          error: () => undefined,
        });
    }

    void this.router.navigate(['/notifications']);
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }

  /**
   * Signs out properly.
   *
   * Clearing storage left the refresh token live on the server for up to
   * thirty days — signing out of a shared machine did not end the session, it
   * only hid it. This revokes it.
   */
  sign_out(): void {
    this.auth
      .signOut()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.leave(),
        // The token may already be gone. Either way the person asked to
        // leave, so they leave.
        error: () => this.leave(),
      });
  }

  private leave(): void {
    this.success_notification('User logged out successfully.');
    void this.router.navigate(['/']);
  }
}
