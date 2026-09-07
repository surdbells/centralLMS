import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { TuiIcon, TuiLoader } from '@taiga-ui/core';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { TopComponent } from '../../partials/top/top.component';
import {
  NotificationsService,
  type Notification,
} from '../../services/notifications.service';
import { ApiError } from '../../core/api';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

/**
 * Everything that has been sent to the signed-in person.
 *
 * The bell in the top bar has linked to `/notifications` since it was written
 * and no such route existed, so "View all notifications" went nowhere. The
 * inbox behind it was there all along — `notification_deliveries` has been
 * filling up, and the in-app channel writes every one of them.
 *
 * No id is sent. The legacy `get-notifications` took `id` and `token` from the
 * body and returned whatever inbox the id named.
 */
@Component({
  selector: 'app-notifications',
  standalone: true,
  imports: [CommonModule, TopComponent, TuiIcon, TuiLoader, TranslatePipe],
  templateUrl: './notifications.component.html',
  styleUrl: './notifications.component.css',
})
export class NotificationsComponent implements OnInit, OnDestroy {
  private readonly notificationsService = inject(NotificationsService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly destroy$ = new Subject<void>();

  notifications: Notification[] = [];
  unread = 0;
  total = 0;

  page = 1;
  readonly perPage = 25;

  unreadOnly = false;
  loading = true;
  error: string | null = null;

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  load(): void {
    this.loading = true;
    this.error = null;

    this.notificationsService
      .list({ unreadOnly: this.unreadOnly, page: this.page, perPage: this.perPage })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.notifications = result.items;
          this.total = result.meta.total;
          this.unread = (result.meta as { unreadCount?: number }).unreadCount ?? this.unread;
          this.loading = false;
        },
        error: (failure: ApiError) => {
          this.error = failure.message;
          this.loading = false;
        },
      });
  }

  get totalPages(): number {
    return Math.max(1, Math.ceil(this.total / this.perPage));
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages || page === this.page) {
      return;
    }

    this.page = page;
    this.load();
  }

  showUnreadOnly(only: boolean): void {
    if (only === this.unreadOnly) {
      return;
    }

    this.unreadOnly = only;
    this.page = 1;
    this.load();
  }

  /**
   * Marks one read.
   *
   * The new unread count comes back with it, so the badge in the top bar and
   * this list cannot drift apart.
   */
  markRead(notification: Notification): void {
    if (notification.isRead) {
      return;
    }

    this.notificationsService
      .markRead(notification.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ unreadCount }) => {
          this.unread = unreadCount;

          // Filtered to unread, a read one no longer belongs in the list.
          if (this.unreadOnly) {
            this.load();

            return;
          }

          this.notifications = this.notifications.map(
            (n) => (n.id === notification.id ? { ...n, isRead: true } : n),
          );
        },
        error: (failure: ApiError) => (this.error = failure.message),
      });
  }

  markAllRead(): void {
    this.notificationsService
      .markAllRead()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.page = 1;
          this.load();
        },
        error: (failure: ApiError) => (this.error = failure.message),
      });
  }

  remove(notification: Notification): void {
    if (!confirm(this.i18n.t('notifications.delete_confirm'))) {
      return;
    }

    this.notificationsService
      .delete(notification.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ unreadCount }) => {
          this.unread = unreadCount;
          this.load();
        },
        error: (failure: ApiError) => (this.error = failure.message),
      });
  }

  /**
   * Opens whatever the notification is about.
   *
   * `subjectType` and `subjectId` say what it refers to; anything this screen
   * has no route for stays here rather than navigating somewhere wrong.
   */
  open(notification: Notification): void {
    this.markRead(notification);

    const where: Record<string, string[]> = {
      course: ['/player', notification.subjectId ?? ''],
      enrollment: ['/learning'],
      certificate: ['/certificates'],
      training: ['/mytraining'],
      session: ['/mytraining'],
      message: ['/messaging'],
    };

    const destination = where[notification.subjectType ?? ''];

    if (destination !== undefined && destination.every((part) => part !== '')) {
      void this.router.navigate(destination);
    }
  }

  /** The icon for a kind of notification, from the material set the bar uses. */
  icon(notification: Notification): string {
    const icons: Record<string, string> = {
      enrollment_assigned: 'assignment',
      enrollment_due_soon: 'schedule',
      enrollment_overdue: 'warning',
      course_completed: 'school',
      certificate_issued: 'workspace_premium',
      assessment_graded: 'grading',
      session_reminder: 'event',
      message_received: 'chat',
      welcome: 'waving_hand',
    };

    return icons[notification.type] ?? 'notifications';
  }

  /** Urgent things look urgent. */
  tone(notification: Notification): string {
    return ({
      urgent: 'text-danger',
      high: 'text-warning',
      low: 'text-muted',
    } as Record<string, string>)[notification.priority] ?? 'text-primary';
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }
}
