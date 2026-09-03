import { Component, OnInit, inject, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { OperatorService } from '../core/operator.service';
import type { PageMeta, ProvisionResult, TenantRow, TenantStatus } from '../core/models';
import { ofLimit } from '../core/format';
import { statusBadgeClass } from '../core/status';
import { ProvisionDialogComponent } from './provision-dialog.component';

/**
 * Every organisation on the platform.
 *
 * Searchable by name, code, email or domain and filterable by state — the
 * whole estate is more than a screenful — with provisioning a new one a button
 * away. A row leads to that organisation in full.
 */
@Component({
  selector: 'app-tenants',
  standalone: true,
  imports: [FormsModule, RouterLink, DecimalPipe, ProvisionDialogComponent],
  template: `
    <header class="op-topbar">
      <div>
        <h1 class="op-topbar__title">Organisations</h1>
        <p class="op-topbar__sub">{{ meta().total | number }} on the platform.</p>
      </div>
      <button class="op-btn op-btn--primary" (click)="showProvision.set(true)">
        <i class="bi bi-plus-lg"></i> New organisation
      </button>
    </header>

    <div class="op-content">
      <div class="op-toolbar">
        <div class="op-search">
          <i class="bi bi-search"></i>
          <input class="op-input" [(ngModel)]="search" (ngModelChange)="search$.next($event)"
                 placeholder="Search name, code, email or domain…" />
        </div>
        <select class="op-select" style="width:auto" [(ngModel)]="status" (ngModelChange)="onFilter()">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="trial">Trial</option>
          <option value="suspended">Suspended</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      <div class="op-card">
        @if (loading()) {
          <div class="op-loading"><span class="op-spinner"></span> Loading organisations…</div>
        } @else if (error()) {
          <div class="op-empty">
            <i class="bi bi-exclamation-triangle" style="color:var(--op-danger)"></i>
            Could not load organisations.
            <div style="margin-top:0.75rem"><button class="op-btn op-btn--sm" (click)="load(meta().page)">Retry</button></div>
          </div>
        } @else if (rows().length === 0) {
          <div class="op-empty">
            <i class="bi bi-inbox"></i>
            @if (search || status) { No organisation matches those filters. }
            @else { No organisations yet. }
          </div>
        } @else {
          <div style="overflow-x:auto">
            <table class="op-table">
              <thead>
                <tr>
                  <th>Organisation</th>
                  <th>Status</th>
                  <th>Plan</th>
                  <th>People</th>
                  <th>Created</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @for (t of rows(); track t.id) {
                  <tr class="is-clickable" (click)="open(t)">
                    <td>
                      <div class="op-table__name">{{ t.name }}</div>
                      <div class="op-table__code">{{ t.code }}</div>
                    </td>
                    <td><span [class]="badge(t.status)">{{ t.status }}</span></td>
                    <td>@if (t.plan) { <span class="op-pill">{{ t.plan.name }}</span> } @else { <span style="color:var(--op-ink-faint)">—</span> }</td>
                    <td>{{ people(t) }}</td>
                    <td style="color:var(--op-ink-soft)">{{ created(t) }}</td>
                    <td style="text-align:right"><i class="bi bi-chevron-right" style="color:var(--op-ink-faint)"></i></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>

          @if (meta().totalPages > 1) {
            <div class="op-pager">
              <span>Page {{ meta().page }} of {{ meta().totalPages }} · {{ meta().total | number }} total</span>
              <div style="display:flex;gap:0.5rem">
                <button class="op-btn op-btn--ghost op-btn--sm" [disabled]="meta().page <= 1" (click)="go(meta().page - 1)">
                  <i class="bi bi-chevron-left"></i> Prev
                </button>
                <button class="op-btn op-btn--ghost op-btn--sm" [disabled]="meta().page >= meta().totalPages" (click)="go(meta().page + 1)">
                  Next <i class="bi bi-chevron-right"></i>
                </button>
              </div>
            </div>
          }
        }
      </div>
    </div>

    @if (showProvision()) {
      <app-provision-dialog (close)="showProvision.set(false)" (provisioned)="onProvisioned($event)" />
    }

    @if (result(); as r) {
      <!-- No backdrop dismissal: a generated password shows here once and a
           misclick outside the box would lose it. Only the explicit ×/Close. -->
      <div class="op-overlay">
        <div class="op-dialog">
          <div class="op-dialog__head">
            <h2 class="op-dialog__title"><i class="bi bi-check-circle-fill" style="color:var(--op-ok)"></i> Organisation created</h2>
            <button type="button" class="op-close" (click)="dismissResult()" aria-label="Close">&times;</button>
          </div>
          <div class="op-dialog__body">
            <p style="margin-top:0"><b>{{ r.tenant.name }}</b> is ready. Its administrator is
              <b>{{ r.tenant.admin?.email || r.tenant.contactEmail }}</b>.</p>

            @if (r.generatedPassword) {
              <div class="op-alert op-alert--info" style="margin-bottom:0.75rem">
                <i class="bi bi-info-circle-fill"></i>
                <span>This password is shown once. Copy it and hand it over securely.</span>
              </div>
              <div class="op-code-box">
                <span>{{ r.generatedPassword }}</span>
                <button class="op-btn op-btn--sm" style="background:rgba(255,255,255,.15);color:#fff" (click)="copy(r.generatedPassword!)">
                  <i class="bi" [class.bi-clipboard]="!copied()" [class.bi-clipboard-check]="copied()"></i>
                </button>
              </div>
            } @else {
              <div class="op-alert op-alert--ok"><i class="bi bi-shield-lock"></i><span>The administrator will sign in with the password you set.</span></div>
            }
          </div>
          <div class="op-dialog__foot">
            <button class="op-btn op-btn--ghost" (click)="dismissResult()">Close</button>
            <button class="op-btn op-btn--primary" (click)="openResult(r)"><i class="bi bi-box-arrow-up-right"></i> Open organisation</button>
          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .op-pager {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0.85rem 1.25rem; border-top: 1px solid var(--op-border);
      font-size: 0.85rem; color: var(--op-ink-soft);
    }
  `],
})
export class TenantsComponent implements OnInit {
  private readonly ops = inject(OperatorService);
  private readonly router = inject(Router);

  search = '';
  status: TenantStatus | '' = '';

  readonly rows = signal<readonly TenantRow[]>([]);
  readonly meta = signal<PageMeta>({ total: 0, page: 1, perPage: 25, totalPages: 0 });
  readonly loading = signal(true);
  readonly error = signal(false);
  readonly showProvision = signal(false);
  readonly result = signal<ProvisionResult | null>(null);
  readonly copied = signal(false);

  readonly search$ = new Subject<string>();

  ngOnInit(): void {
    this.search$.pipe(debounceTime(300), distinctUntilChanged()).subscribe(() => this.load(1));
    this.load(1);
  }

  load(page: number): void {
    this.loading.set(true);
    this.error.set(false);
    this.ops.tenants({ search: this.search, status: this.status, page, perPage: 25 }).subscribe({
      next: (res) => {
        this.rows.set(res.items);
        this.meta.set(res.meta);
        this.loading.set(false);
      },
      error: () => {
        // A failure is not an empty estate — say so, and keep the page number
        // so Retry asks for the same page.
        this.rows.set([]);
        this.meta.update((m) => ({ ...m, page }));
        this.error.set(true);
        this.loading.set(false);
      },
    });
  }

  onFilter(): void {
    this.load(1);
  }

  go(page: number): void {
    this.load(page);
  }

  open(t: TenantRow): void {
    void this.router.navigate(['/tenants', t.id]);
  }

  onProvisioned(result: ProvisionResult): void {
    this.showProvision.set(false);
    this.result.set(result);
    this.copied.set(false);
    this.load(1);
  }

  dismissResult(): void {
    this.result.set(null);
  }

  openResult(r: ProvisionResult): void {
    this.result.set(null);
    void this.router.navigate(['/tenants', r.tenant.id]);
  }

  copy(text: string): void {
    navigator.clipboard?.writeText(text).then(
      () => this.copied.set(true),
      () => this.copied.set(false),
    );
  }

  badge(status: TenantStatus): string {
    return statusBadgeClass(status);
  }

  people(t: TenantRow): string {
    return ofLimit(t.userCount, t.usersLimit);
  }

  created(t: TenantRow): string {
    return t.createdAt ? new Date(t.createdAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  }
}
