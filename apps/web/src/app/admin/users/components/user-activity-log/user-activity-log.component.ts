import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import { AuditService, type AuditEvent, type AuditFilters } from '../../../../services/audit.service';
import { UsersService, type User } from '../../../../services/users.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';

/**
 * Who did what.
 *
 * The screen this replaces read `user_activity_log`, which only the login
 * handler ever wrote to — so it was a list of sign-ins presented as a full
 * history, and every column except the timestamp was empty for anything else.
 * The trail behind this one is written by the API's own middleware from what
 * the router matched, so it covers every write there is.
 *
 * The action filter offers what this organisation's trail actually contains,
 * not every route the application has.
 */
@Component({
  selector: 'app-user-activity-log',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  templateUrl: './user-activity-log.component.html',
  styles: [`
    .mono { font-family: var(--bs-font-monospace); font-size: .75rem; }
    .cursor-pointer { cursor: pointer; }
  `],
})
export class UserActivityLogComponent implements OnInit, OnDestroy {
  private readonly auditService = inject(AuditService);
  private readonly usersService = inject(UsersService);

  private readonly destroy$ = new Subject<void>();
  private readonly change$ = new Subject<void>();

  events: AuditEvent[] = [];
  actions: string[] = [];
  people: User[] = [];

  filters: AuditFilters = { page: 1, perPage: 25 };
  meta = { total: 0, page: 1, perPage: 25, totalPages: 0 };

  loading = true;
  error: string | null = null;

  /** The row whose full detail is open, if any. */
  expanded: string | null = null;

  ngOnInit(): void {
    this.change$
      .pipe(debounceTime(250), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => this.load());

    forkJoin({
      actions: this.auditService.actions(),
      people: this.usersService.options(),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ actions, people }) => {
          this.actions = actions;
          this.people = people.items;
        },
        error: (error: ApiError) => (this.error = error.message),
      });

    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  load(): void {
    this.loading = true;
    this.error = null;

    this.auditService
      .list(this.filters)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.events = page.items;
          this.meta = page.meta;
          this.loading = false;
        },
        error: (error: ApiError) => {
          // A filter the server cannot parse is a 422 naming the field, not a
          // silently unnarrowed list.
          this.error = error.isValidation
            ? Object.values(error.violations).flat().join(' ')
            : error.message;
          this.loading = false;
        },
      });
  }

  onFilterChange(): void {
    this.filters.page = 1;
    this.change$.next();
  }

  resetFilters(): void {
    this.filters = { page: 1, perPage: 25 };
    this.load();
  }

  goPage(page: number): void {
    this.filters.page = page;
    this.load();
  }

  toggle(event: AuditEvent): void {
    this.expanded = this.expanded === event.id ? null : event.id;
  }

  // --- presentation -------------------------------------------------------

  /** The module an action belongs to, for grouping the filter. */
  moduleOf(action: string): string {
    return action.split('.')[0];
  }

  get actionModules(): string[] {
    return [...new Set(this.actions.map((a) => this.moduleOf(a)))].sort();
  }

  statusClass(status: number): string {
    if (status < 300) {
      return 'bg-success-subtle text-success';
    }

    if (status < 500) {
      return 'bg-warning-subtle text-warning';
    }

    return 'bg-danger-subtle text-danger';
  }

  methodClass(method: string): string {
    const classes: Record<string, string> = {
      POST: 'bg-success',
      PUT: 'bg-primary',
      PATCH: 'bg-info text-dark',
      DELETE: 'bg-danger',
    };

    return classes[method] ?? 'bg-secondary';
  }

  /** A user-agent string, as the device it describes. */
  device(userAgent: string | null): string {
    if (userAgent === null || userAgent === '') {
      return '-';
    }

    const browser = /Edg\//.test(userAgent) ? 'Edge'
      : /Chrome\//.test(userAgent) ? 'Chrome'
      : /Safari\//.test(userAgent) ? 'Safari'
      : /Firefox\//.test(userAgent) ? 'Firefox'
      : /curl/i.test(userAgent) ? 'curl'
      : 'Other';

    const platform = /Android/.test(userAgent) ? 'Android'
      : /iPhone|iPad/.test(userAgent) ? 'iOS'
      : /Windows/.test(userAgent) ? 'Windows'
      : /Mac OS/.test(userAgent) ? 'macOS'
      : /Linux/.test(userAgent) ? 'Linux'
      : '';

    return platform === '' ? browser : `${browser} on ${platform}`;
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }
}
