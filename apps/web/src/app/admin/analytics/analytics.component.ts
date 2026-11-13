import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { StarRatingComponent } from '../../shared/star-rating/star-rating.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../shared/select/select.component';
import {
  AnalyticsService,
  type EngagementAnalytics,
  type AnalyticsSummary,
  type ActivePoint,
  type DeviceUsage,
  type FieldSlice,
} from '../../services/analytics.service';

type Part = 'completed' | 'inProgress' | 'yetToAttempt';

/**
 * The admin Learner Analytics page: the A–H learner-analytics the organisation
 * asked for, led by the newly instrumented engagement, device, satisfaction and
 * compliance figures. The deeper per-unit breakdowns link out to the existing
 * Reports and Competency modules rather than being duplicated here.
 */
@Component({
  selector: 'app-analytics',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe, StarRatingComponent, SkeletonComponent, SelectComponent],
  templateUrl: './analytics.component.html',
  styleUrls: ['./analytics.component.scss'],
})
export class AnalyticsComponent implements OnInit {
  private readonly analytics = inject(AnalyticsService);

  loading = true;
  failed = false;
  days = 30;

  engagement: EngagementAnalytics | null = null;
  summary: AnalyticsSummary | null = null;

  /** Completion sliced by a custom field (Division / Subsidiary / Entity). */
  fields: { key: string; label: string }[] = [];
  selectedField = '';
  fieldRows: FieldSlice[] = [];

  readonly stars = [5, 4, 3, 2, 1];
  readonly deviceColors: Record<string, string> = {
    // Shades of the tenant brand colour, resolved by the browser.
    desktop: 'var(--brand-primary)',
    mobile: 'rgba(var(--brand-primary-rgb), .55)',
    tablet: 'rgba(var(--brand-primary-rgb), .25)',
  };

  ngOnInit(): void {
    this.load();
    this.loadByField();
  }

  private loadByField(field?: string): void {
    this.analytics.byField(field).subscribe({
      next: (res) => {
        this.fields = res.fields;
        this.selectedField = res.field ?? '';
        this.fieldRows = res.rows;
      },
      error: () => undefined,
    });
  }

  onFieldChange(key: string): void {
    this.selectedField = key;
    this.loadByField(key);
  }

  get maxFieldEnrolled(): number {
    return Math.max(1, ...this.fieldRows.map((r) => r.enrolled));
  }

  setDays(days: number): void {
    if (this.days !== days) {
      this.days = days;
      this.load();
    }
  }

  private load(): void {
    this.loading = true;
    this.failed = false;

    forkJoin({
      engagement: this.analytics.engagement(this.days),
      summary: this.analytics.summary(this.days),
    }).subscribe({
      next: ({ engagement, summary }) => {
        this.engagement = engagement;
        this.summary = summary;
        this.loading = false;
      },
      error: () => {
        this.failed = true;
        this.loading = false;
      },
    });
  }

  // --- active-learners series (bucketed to the range) ----------------------

  /** Day buckets up to a month, then weeks, then months, so the bars stay readable. */
  get chartSeries(): ActivePoint[] {
    if (!this.engagement) {
      return [];
    }
    if (this.days <= 31) {
      return this.engagement.daily;
    }
    return this.days <= 120 ? this.engagement.weekly : this.engagement.monthly;
  }

  get chartBucketKey(): string {
    return this.days <= 31 ? 'analytics.by_day' : this.days <= 120 ? 'analytics.by_week' : 'analytics.by_month';
  }

  private get maxActive(): number {
    return Math.max(1, ...this.chartSeries.map((p) => p.learners));
  }

  barHeight(value: number): number {
    return Math.round((value / this.maxActive) * 100);
  }

  // --- device split ---------------------------------------------------------

  get devices(): DeviceUsage[] {
    return this.engagement?.devices ?? [];
  }

  private get deviceTotal(): number {
    return this.devices.reduce((sum, d) => sum + d.sessions, 0);
  }

  devicePct(d: DeviceUsage): number {
    const total = this.deviceTotal;
    return total > 0 ? Math.round((d.sessions / total) * 100) : 0;
  }

  get deviceGradient(): string {
    const total = this.deviceTotal;

    if (total === 0) {
      return 'conic-gradient(#e9ecef 0deg 360deg)';
    }

    let acc = 0;
    const stops = this.devices.map((d) => {
      const start = (acc / total) * 360;
      acc += d.sessions;
      const end = (acc / total) * 360;
      return `${this.deviceColors[d.device] ?? '#c9ccd1'} ${start}deg ${end}deg`;
    });

    return `conic-gradient(${stops.join(', ')})`;
  }

  // --- compliance three-way -------------------------------------------------

  /**
   * Whole-number shares that add up exactly (largest-remainder rounding), so
   * the stacked bar never totals 99% or 101%.
   */
  compliancePct(part: Part): number {
    const c = this.summary?.compliance;
    if (!c || !(c.total > 0)) {
      return 0;
    }
    const parts: Part[] = ['completed', 'inProgress', 'yetToAttempt'];
    const raw = parts.map((p) => ((c[p] ?? 0) / c.total) * 100);
    const floors = raw.map(Math.floor);
    const target = Math.round(raw.reduce((a, b) => a + b, 0));
    let left = target - floors.reduce((a, b) => a + b, 0);
    parts
      .map((_, i) => i)
      .sort((a, b) => (raw[b] - floors[b]) - (raw[a] - floors[a]))
      .forEach((i) => {
        if (left > 0) {
          floors[i]++;
          left--;
        }
      });
    return floors[parts.indexOf(part)];
  }

  // --- satisfaction ---------------------------------------------------------

  satCount(star: number): number {
    return this.summary?.satisfaction?.distribution?.[star] ?? 0;
  }

  private get satMax(): number {
    const d = this.summary?.satisfaction?.distribution;
    return d ? Math.max(1, ...this.stars.map((s) => d[s] ?? 0)) : 1;
  }

  satBar(star: number): number {
    return Math.round((this.satCount(star) / this.satMax) * 100);
  }

  // --- behaviour change (L3) ------------------------------------------------

  behCount(star: number): number {
    return this.summary?.behaviour?.distribution?.[star] ?? 0;
  }

  private get behMax(): number {
    const d = this.summary?.behaviour?.distribution;
    return d ? Math.max(1, ...this.stars.map((s) => d[s] ?? 0)) : 1;
  }

  behBar(star: number): number {
    return Math.round((this.behCount(star) / this.behMax) * 100);
  }

  // --- competency gap-closure trend ----------------------------------------

  /** The change in readiness from the previous cycle to the latest, or null. */
  get competencyClosure(): number | null {
    const t = this.summary?.competencyTrend ?? [];
    if (t.length < 2) {
      return null;
    }
    return Math.round((t[t.length - 1].rate - t[t.length - 2].rate) * 10) / 10;
  }
}
