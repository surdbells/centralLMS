import { Component, OnInit, inject, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { OperatorService } from '../core/operator.service';
import type { PlatformMetrics } from '../core/models';
import { money, storage } from '../core/format';

/**
 * The platform at a glance.
 *
 * The headline counts — organisations by state, people, storage, monthly
 * recurring revenue — and how the estate splits across plans. Every figure
 * comes from `/operator/dashboard`; nothing is totalled in the browser.
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [RouterLink, DecimalPipe],
  template: `
    <header class="op-topbar">
      <div>
        <h1 class="op-topbar__title">Dashboard</h1>
        <p class="op-topbar__sub">The Central LMS platform at a glance.</p>
      </div>
      <a routerLink="/tenants" class="op-btn op-btn--primary">
        <i class="bi bi-building"></i> Organisations
      </a>
    </header>

    <div class="op-content">
      @if (loading()) {
        <div class="op-loading"><span class="op-spinner"></span> Loading platform metrics…</div>
      } @else if (error()) {
        <div class="op-alert op-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span>Could not load the dashboard. <button class="op-btn op-btn--sm" (click)="load()">Retry</button></span>
        </div>
      } @else if (m()) {
        @if (m(); as data) {
        <div class="op-tiles" style="margin-bottom:1.25rem">
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-building"></i> Organisations</div>
            <div class="op-tile__value">{{ data.tenants.total | number }}</div>
            <div class="op-tile__hint">{{ data.tenants.active | number }} active · {{ data.tenants.trial | number }} on trial</div>
          </div>
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-people"></i> People</div>
            <div class="op-tile__value">{{ data.users | number }}</div>
            <div class="op-tile__hint">across every organisation</div>
          </div>
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-cash-stack"></i> MRR</div>
            <div class="op-tile__value">{{ money(data.mrr) }}</div>
            <div class="op-tile__hint">from organisations on a paid plan</div>
          </div>
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-hdd"></i> Storage</div>
            <div class="op-tile__value">{{ storage(data.storageMb) }}</div>
            <div class="op-tile__hint">media held platform-wide</div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.25rem">
          <div class="op-card">
            <div class="op-card__head"><h2 class="op-card__title">By status</h2></div>
            <div class="op-card__body">
              <div class="op-statrow"><span class="op-badge op-badge--active">Active</span>
                <b>{{ data.tenants.active | number }}</b></div>
              <div class="op-statrow"><span class="op-badge op-badge--trial">Trial</span>
                <b>{{ data.tenants.trial | number }}</b></div>
              <div class="op-statrow"><span class="op-badge op-badge--suspended">Suspended</span>
                <b>{{ data.tenants.suspended | number }}</b></div>
              <div class="op-statrow"><span class="op-badge op-badge--cancelled">Cancelled</span>
                <b>{{ data.tenants.cancelled | number }}</b></div>
            </div>
          </div>

          <div class="op-card">
            <div class="op-card__head"><h2 class="op-card__title">By plan</h2></div>
            <div class="op-card__body">
              @for (row of data.byPlan; track row.plan) {
                <div class="op-statrow">
                  <span class="op-pill">{{ row.plan }}</span>
                  <b>{{ row.tenants | number }}</b>
                </div>
              } @empty {
                <div class="op-empty" style="padding:1.5rem"><i class="bi bi-tags"></i> No plans assigned yet.</div>
              }
            </div>
          </div>
        </div>
        }
      }
    </div>
  `,
  styles: [`
    .op-statrow {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0.6rem 0; border-bottom: 1px solid var(--op-border);
    }
    .op-statrow:last-child { border-bottom: none; }
    .op-statrow b { font-size: 1.05rem; }
  `],
})
export class DashboardComponent implements OnInit {
  private readonly ops = inject(OperatorService);

  readonly m = signal<PlatformMetrics | null>(null);
  readonly loading = signal(true);
  readonly error = signal(false);

  readonly money = money;
  readonly storage = storage;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);

    this.ops.metrics().subscribe({
      next: (data) => {
        this.m.set(data);
        this.loading.set(false);
      },
      error: () => {
        this.error.set(true);
        this.loading.set(false);
      },
    });
  }
}
