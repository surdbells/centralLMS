import { Component, OnDestroy, OnInit, effect, inject } from '@angular/core';
import { CommonModule, NgForOf, NgIf } from '@angular/common';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { LanguageSwitcherComponent } from '../../../language-switcher.component';
import { NotificationsService } from '../../../services/notifications.service';
import { MyProfileService } from '../../../services/my-profile.service';
import { SessionStore } from '../../../core/auth/session.store';
import { AuthService } from '../../../core/auth/auth.service';
import { ApiClient, AuthImgDirective } from '../../../core/api';
import { BrandingService } from '../../../core/branding.service';

/** One item in the bell dropdown, in the shape the template already reads. */
export interface Notifications {
  id: string;
  message: string;
  is_read: boolean;
  created_at: string | null;
}

/**
 * The admin navigation bar.
 *
 * **It used to disappear.** The whole bar hung off `user_session.is_admin`,
 * read out of the legacy `sessionStorage.SESSION` blob — so any session that
 * did not happen to write that blob rendered no navigation at all, on every
 * screen, with no error to explain it. It now reads {@link SessionStore},
 * which is where a signed-in session actually lives, and works out whether to
 * show itself from the permissions the sign-in returned rather than from a
 * boolean somebody has to remember to set.
 *
 * `is_admin` is kept as the name the template binds to, because the template
 * is the part that was working.
 */
@Component({
  selector: 'app-admintop',
  standalone: true,
  imports: [
    LanguageSwitcherComponent,
    NgForOf,
    NgIf,
    CommonModule,
    RouterLink,
    RouterLinkActive,
    TuiIcon,
    AuthImgDirective,
  ],
  templateUrl: './admintop.component.html',
  styleUrl: './admintop.component.css',
})
export class AdmintopComponent implements OnInit, OnDestroy {
  private readonly notificationsService = inject(NotificationsService);
  private readonly profileService = inject(MyProfileService);
  private readonly session = inject(SessionStore);
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiClient);
  /** The organisation's logo and name, painted by BrandingService. */
  readonly branding = inject(BrandingService);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);
  private readonly destroy$ = new Subject<void>();

  notifications?: Notifications[];

  ui_controls = { is_loading: false, count: 0 };

  /**
   * Keeps the badge on the one unread count there is.
   *
   * The inbox screen marks things read as well, and each screen holding
   * its own copy of the number meant the badge went on saying the old one
   * until the page was reloaded.
   */
  private readonly badge = effect(() => {
    this.ui_controls.count = this.notificationsService.unreadCount$();
  });

  /**
   * Who is signed in, under the names the template uses.
   *
   * `avatar` is the URL the API gives, not the base64 the legacy inlined —
   * see {@link avatarSrc}, which is the one binding in the template that had
   * to change with it.
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
      // Derived from what this person may actually do. The legacy set four
      // booleans on sign-in and nothing kept them in step with the roles.
      is_admin: can('user.manage') || can('course.publish') || can('settings.manage'),
      is_content: can('course.manage'),
      is_instructor: can('progress.manage'),
      is_learner: can('enrollment.self'),
    };

    // The name and picture come from the person's own record rather than
    // from a copy pasted into storage at sign-in, which went stale the moment
    // they changed either.
    this.profileService
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
        // The bar is navigation; it stays up whether or not the profile
        // request succeeds.
        error: () => undefined,
      });

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
            // The bell shows one line; the title is that line, and the body
            // is the detail the notifications screen shows.
            message: n.title,
            is_read: n.isRead,
            created_at: n.createdAt,
          }));
          this.ui_controls.count = unread;
          this.ui_controls.is_loading = false;
        },
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
