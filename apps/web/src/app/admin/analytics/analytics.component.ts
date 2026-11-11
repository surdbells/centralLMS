import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { StarRatingComponent } from '../../shared/star-rating/star-rating.component';
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
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe, StarRatingComponent],
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
    desktop: '#2f4b7c',
    mobile: '#2149e8',
    tablet: '#f0a500',
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

  compliancePct(part: Part): number {
    const c = this.summary?.compliance;
    return c && c.total > 0 ? Math.round((c[part] / c.total) * 100) : 0;
  }

  // --- satisfaction ---------------------------------------------------------

  satCount(star: number): number {
    return this.summary?.satisfaction.distribution[star] ?? 0;
  }

  private get satMax(): number {
    const d = this.summary?.satisfaction.distribution;
    return d ? Math.max(1, ...this.stars.map((s) => d[s] ?? 0)) : 1;
  }

  satBar(star: number): number {
    return Math.round((this.satCount(star) / this.satMax) * 100);
  }
}
