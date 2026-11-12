// reports/components/report-chart/report-chart.component.ts

import {
  AfterViewInit, ChangeDetectorRef, Component, ElementRef, Input, NgZone, OnChanges, OnDestroy,
  SimpleChanges, ViewChild, inject,
} from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { ChartData, PieSlice } from '../../models/report.models';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

interface StackSeg { bottom: number; top: number; value: number; }

@Component({
  selector: 'app-report-chart',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  providers: [DecimalPipe],
  template: `
    <div class="report-chart ui-section">
      <div>
        <!-- Header with type switcher -->
        <div class="ui-section__head" *ngIf="allowTypeChange || title">
          <div><h2 class="ui-section__title" *ngIf="title">{{ title }}</h2></div>
          <div class="ui-section__actions rch-types" *ngIf="allowTypeChange">
            <button type="button" *ngFor="let t of availableTypes" class="ui-filter"
                    [class.is-active]="kind === t.key" (click)="setType(t.key)"
                    [title]="t.label" [attr.aria-label]="t.label">
              <i [class]="t.icon"></i>
            </button>
          </div>
        </div>

        <!-- SVG Canvas -->
        <div class="chart-wrap" #wrapEl [style.height]="height">
          <svg *ngIf="hasData" [attr.viewBox]="viewBox" preserveAspectRatio="xMidYMid meet"
               width="100%" [attr.height]="sh" role="img" [attr.aria-label]="title || null">

            <!-- ═══ BAR CHART ═══ -->
            <ng-container *ngIf="kind === 'bar' || kind === 'grouped_bar'">
              <g [attr.transform]="'translate(' + ml + ',' + mt + ')'">
                <line *ngFor="let t of yTicks" x1="0" [attr.y1]="yScale(t)"
                      [attr.x2]="cw" [attr.y2]="yScale(t)" class="rch-grid"/>
                <text *ngFor="let t of yTicks" x="-10" [attr.y]="yScale(t) + 4"
                      text-anchor="end" class="rch-tick">{{ formatTick(t) }}</text>
                <ng-container *ngFor="let ds of cd!.datasets; let di = index">
                  <rect *ngFor="let v of ds.data; let i = index"
                        [attr.x]="barX(i, di)" [attr.y]="barTop(v)"
                        [attr.width]="barWidth" [attr.height]="barHeight(v)"
                        [style.fill]="getColor(di)" rx="3" class="chart-bar">
                    <title>{{ cd!.labels[i] }} · {{ ds.label }}: {{ dp.transform(v, '1.0-1') }}</title>
                  </rect>
                </ng-container>
                <line x1="0" [attr.y1]="yScale(0)" [attr.x2]="cw" [attr.y2]="yScale(0)" class="rch-axis"/>
                <text *ngFor="let l of cd!.labels; let i = index"
                      [attr.x]="barGroupCenter(i)" [attr.y]="ch + 18"
                      [attr.text-anchor]="rotateLabels ? 'end' : 'middle'" class="rch-tick"
                      [attr.transform]="rotateLabels ? 'rotate(-35,' + barGroupCenter(i) + ',' + (ch + 18) + ')' : null">{{ truncLabel(l) }}<title>{{ l }}</title></text>
              </g>
            </ng-container>

            <!-- ═══ LINE / AREA CHART ═══ -->
            <ng-container *ngIf="kind === 'line' || kind === 'area'">
              <g [attr.transform]="'translate(' + ml + ',' + mt + ')'">
                <line *ngFor="let t of yTicks" x1="0" [attr.y1]="yScale(t)"
                      [attr.x2]="cw" [attr.y2]="yScale(t)" class="rch-grid"/>
                <text *ngFor="let t of yTicks" x="-10" [attr.y]="yScale(t) + 4"
                      text-anchor="end" class="rch-tick">{{ formatTick(t) }}</text>
                <ng-container *ngIf="kind === 'area'">
                  <path *ngFor="let ds of cd!.datasets; let di = index"
                        [attr.d]="areaPath(ds.data)" [style.fill]="getColor(di)" fill-opacity="0.12"/>
                </ng-container>
                <line x1="0" [attr.y1]="yScale(0)" [attr.x2]="cw" [attr.y2]="yScale(0)" class="rch-axis"/>
                <path *ngFor="let ds of cd!.datasets; let di = index"
                      [attr.d]="linePath(ds.data)" fill="none"
                      [style.stroke]="getColor(di)" stroke-width="2.5"
                      stroke-linecap="round" stroke-linejoin="round"/>
                <ng-container *ngFor="let ds of cd!.datasets; let di = index">
                  <circle *ngFor="let v of ds.data; let i = index"
                          [attr.cx]="lineX(i)" [attr.cy]="yScale(v)" r="4"
                          [style.fill]="getColor(di)" stroke-width="2" class="chart-point">
                    <title>{{ cd!.labels[i] }} · {{ ds.label }}: {{ dp.transform(v, '1.0-1') }}</title>
                  </circle>
                </ng-container>
                <text *ngFor="let l of cd!.labels; let i = index"
                      [attr.x]="lineX(i)" [attr.y]="ch + 18"
                      [attr.text-anchor]="rotateLabels ? 'end' : 'middle'" class="rch-tick"
                      [attr.transform]="rotateLabels ? 'rotate(-35,' + lineX(i) + ',' + (ch + 18) + ')' : null">{{ truncLabel(l) }}<title>{{ l }}</title></text>
              </g>
            </ng-container>

            <!-- ═══ PIE / DOUGHNUT ═══ -->
            <ng-container *ngIf="kind === 'pie' || kind === 'doughnut'">
              <g [attr.transform]="'translate(' + (sw / 2) + ',' + (sh / 2) + ')'">
                <path *ngFor="let s of pieSlices"
                      [attr.d]="s.path" [style.fill]="s.color" fill-rule="evenodd"
                      stroke-width="2" class="chart-bar rch-slice">
                  <title>{{ s.label }}: {{ dp.transform(s.value, '1.0-1') }} ({{ dp.transform(s.percent, '1.0-1') }}%)</title>
                </path>
                <text *ngIf="kind === 'doughnut'" text-anchor="middle"
                      dominant-baseline="central" class="rch-total">{{ dp.transform(pieTotal, '1.0-1') }}</text>
              </g>
            </ng-container>

            <!-- ═══ HORIZONTAL BAR ═══ -->
            <ng-container *ngIf="kind === 'horizontal_bar'">
              <g [attr.transform]="'translate(' + hLabelW + ',' + mt + ')'">
                <line *ngFor="let t of xTicks" [attr.x1]="hXScale(t)" y1="0"
                      [attr.x2]="hXScale(t)" [attr.y2]="ch" class="rch-grid"/>
                <text *ngFor="let t of xTicks" [attr.x]="hXScale(t)"
                      [attr.y]="ch + 16" text-anchor="middle" class="rch-tick">{{ formatTick(t) }}</text>
                <rect *ngFor="let v of primaryData; let i = index"
                      [attr.x]="hBarLeft(v)" [attr.y]="hBarY(i)"
                      [attr.width]="hBarLength(v)"
                      [attr.height]="hBarHeight" [style.fill]="getColor(i)"
                      rx="3" class="chart-bar">
                  <title>{{ cd!.labels[i] }}: {{ dp.transform(v, '1.0-1') }}</title>
                </rect>
                <line [attr.x1]="hXScale(0)" y1="0" [attr.x2]="hXScale(0)" [attr.y2]="ch" class="rch-axis"/>
                <text *ngFor="let l of cd!.labels; let i = index"
                      x="-8" [attr.y]="hBarY(i) + hBarHeight / 2 + 4"
                      text-anchor="end" class="rch-label">{{ truncLabel(l, 16) }}<title>{{ l }}</title></text>
              </g>
            </ng-container>

            <!-- ═══ STACKED BAR ═══ -->
            <ng-container *ngIf="kind === 'stacked_bar'">
              <g [attr.transform]="'translate(' + ml + ',' + mt + ')'">
                <line *ngFor="let t of yTicks" x1="0" [attr.y1]="yScale(t)"
                      [attr.x2]="cw" [attr.y2]="yScale(t)" class="rch-grid"/>
                <text *ngFor="let t of yTicks" x="-10" [attr.y]="yScale(t) + 4"
                      text-anchor="end" class="rch-tick">{{ formatTick(t) }}</text>
                <ng-container *ngFor="let l of cd!.labels; let i = index">
                  <rect *ngFor="let seg of stackSegs[i]; let si = index"
                        [attr.x]="stackBarX(i)" [attr.y]="yScale(seg.top)"
                        [attr.width]="stackBarW"
                        [attr.height]="Math.max(0, yScale(seg.bottom) - yScale(seg.top))"
                        [style.fill]="getColor(si)" rx="2">
                    <title>{{ l }} · {{ cd!.datasets[si]?.label }}: {{ dp.transform(seg.value, '1.0-1') }}</title>
                  </rect>
                </ng-container>
                <line x1="0" [attr.y1]="yScale(0)" [attr.x2]="cw" [attr.y2]="yScale(0)" class="rch-axis"/>
                <text *ngFor="let l of cd!.labels; let i = index"
                      [attr.x]="stackBarX(i) + stackBarW / 2" [attr.y]="ch + 18"
                      [attr.text-anchor]="rotateLabels ? 'end' : 'middle'" class="rch-tick"
                      [attr.transform]="rotateLabels ? 'rotate(-35,' + (stackBarX(i) + stackBarW / 2) + ',' + (ch + 18) + ')' : null">{{ truncLabel(l) }}<title>{{ l }}</title></text>
              </g>
            </ng-container>
          </svg>

          <!-- Empty state -->
          <div *ngIf="!hasData" class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-bar-chart-line"></i></span>
            <p>{{ 'reports.no_chart_data' | translate }}</p>
          </div>
        </div>

        <!-- Legend -->
        <div class="rch-legend" *ngIf="hasData && cd!.datasets.length > 1 && kind !== 'pie' && kind !== 'doughnut' && kind !== 'horizontal_bar'">
          <div *ngFor="let ds of cd!.datasets; let i = index" class="rch-legend__item">
            <span class="legend-dot" [style.backgroundColor]="getColor(i)"></span>
            <span class="small ui-muted">{{ ds.label }}</span>
          </div>
        </div>
        <!-- Single series: say what the marks measure -->
        <div class="rch-legend" *ngIf="hasData && (cd!.datasets.length === 1 || kind === 'horizontal_bar') && kind !== 'pie' && kind !== 'doughnut'">
          <span class="small ui-muted">{{ cd!.datasets[0].label }}</span>
        </div>
        <!-- Pie/Doughnut Legend -->
        <div class="rch-legend" *ngIf="hasData && (kind === 'pie' || kind === 'doughnut')">
          <div *ngFor="let s of pieSlices" class="rch-legend__item">
            <span class="legend-dot" [style.backgroundColor]="s.color"></span>
            <span class="small ui-muted">{{ s.label }} ({{ dp.transform(s.percent, '1.0-1') }}%)</span>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .chart-wrap { position: relative; width: 100%; overflow: hidden; }
    .chart-wrap > svg { display: block; overflow: visible; }
    .chart-bar { transition: opacity 0.2s; cursor: pointer; }
    .chart-bar:hover { opacity: 0.75; }
    .chart-point { transition: r 0.2s; cursor: pointer; stroke: var(--ui-surface, #fff); }
    .chart-point:hover { r: 6; }
    .rch-slice { stroke: var(--ui-surface, #fff); }
    .rch-grid { stroke: var(--ui-border, #eef0f3); stroke-width: 1; }
    .rch-axis { stroke: var(--ui-border-strong, #d1d5db); stroke-width: 1; }
    .rch-tick { fill: var(--ui-text-muted, #6b7280); font-size: 11px; }
    .rch-label { fill: var(--ui-text, #374151); font-size: 11px; }
    .rch-total { fill: var(--ui-text, #374151); font-size: 18px; font-weight: 600; }
    .rch-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px 16px; margin-top: 12px; }
    .rch-legend__item { display: inline-flex; align-items: center; gap: 8px; }
    .legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
    .rch-types { gap: 4px; flex-wrap: wrap; }
    .rch-types .ui-filter { padding-left: 10px; padding-right: 10px; }
    .chart-wrap > .ui-state { height: 100%; justify-content: center; }
    /* Already inside a page section: drop the second card frame. */
    :host-context(.ui-section) .report-chart { border: 0; padding: 0; margin: 0; border-radius: 0; }
  `]
})
export class ReportChartComponent implements OnChanges, AfterViewInit, OnDestroy {
  @Input() data: any;
  @Input() reportKey = '';
  /**
   * What to draw. A drawing type (`bar`, `line`, `pie`, …) is used as given;
   * a report shape from the API (`series`, `breakdown`, `table`, `summary`,
   * `detail`) is turned into the drawing that suits it — see {@link resolveKind}.
   */
  @Input() chartType: string = 'bar';
  @Input() height = '380px';
  @Input() allowTypeChange = true;
  @Input() title = '';
  @ViewChild('wrapEl', { static: true }) wrapEl?: ElementRef<HTMLElement>;

  private readonly i18n = inject(I18nService);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly zone = inject(NgZone);
  readonly dp = inject(DecimalPipe);
  private resizeObserver?: ResizeObserver;

  Math = Math;
  cd: ChartData | null = null;
  /** The drawing actually in use (chartType resolved against the data). */
  kind = 'bar';
  /** The user picked a type with the switcher; keep it across data refreshes. */
  private pickedType: string | null = null;

  // SVG dimensions — the width follows the container, so text stays 11px.
  sw = 800; sh = 380; ml = 48; mt = 16; mr = 16; mb = 40;
  get cw() { return Math.max(10, this.sw - this.ml - this.mr); }
  get ch() { return Math.max(10, this.sh - this.mt - this.mb); }
  get viewBox() { return `0 0 ${this.sw} ${this.sh}`; }

  yTicks: number[] = [];
  xTicks: number[] = [];
  minVal = 0;
  maxVal = 1;
  rotateLabels = false;
  pieSlices: PieSlice[] = [];
  pieTotal = 0;
  stackSegs: StackSeg[][] = [];

  availableTypes = [
    { key: 'bar', icon: 'bi bi-bar-chart', label: this.i18n.t('reports.chart_bar') },
    { key: 'line', icon: 'bi bi-graph-up', label: this.i18n.t('reports.chart_line') },
    { key: 'area', icon: 'bi bi-graph-up', label: this.i18n.t('reports.chart_area') },
    { key: 'pie', icon: 'bi bi-pie-chart', label: this.i18n.t('reports.chart_pie') },
    { key: 'doughnut', icon: 'bi bi-circle', label: this.i18n.t('reports.chart_doughnut') },
    { key: 'horizontal_bar', icon: 'bi bi-bar-chart-steps', label: this.i18n.t('reports.chart_horizontal') },
    { key: 'stacked_bar', icon: 'bi bi-stack', label: this.i18n.t('reports.chart_stacked') },
  ];

  /**
   * Series colours. Theme tokens first (the tenant brand, then the status
   * hues), so the chart follows the brand and the dark theme; fixed hues only
   * once those run out.
   */
  colors = [
    'var(--brand-primary, #4f46e5)', 'var(--ui-success, #10b981)', 'var(--ui-warning, #f59e0b)',
    'var(--ui-danger, #ef4444)', 'var(--ui-info, #3b82f6)',
    '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#06b6d4', '#84cc16',
  ];

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['chartType'] && !changes['chartType'].firstChange) this.pickedType = null;
    if (changes['data'] || changes['chartType']) this.buildChart();
  }

  ngAfterViewInit(): void {
    const el = this.wrapEl?.nativeElement;
    if (!el || typeof ResizeObserver === 'undefined') return;
    // The observer reports the first size itself, after this view is checked.
    this.resizeObserver = new ResizeObserver(() => {
      if (this.measure(el)) this.zone.run(() => { this.layout(); this.cdr.markForCheck(); });
    });
    this.resizeObserver.observe(el);
  }

  ngOnDestroy(): void {
    this.resizeObserver?.disconnect();
  }

  setType(type: string): void {
    this.pickedType = type;
    this.buildChart();
  }

  getColor(i: number): string { return this.colors[((i % this.colors.length) + this.colors.length) % this.colors.length]; }

  /** True when there is something to draw — the empty state shows otherwise. */
  get hasData(): boolean {
    if (!this.cd || !this.cd.labels.length || !this.cd.datasets.length) return false;
    if (this.kind === 'pie' || this.kind === 'doughnut') return this.pieSlices.length > 0;
    return true;
  }

  /* ═══════ BUILD ═══════ */

  /** Reads the container size; true when it changed enough to redraw. */
  private measure(el: HTMLElement): boolean {
    const w = Math.round(el.clientWidth);
    const h = Math.round(el.clientHeight) || parseInt(this.height, 10) || 380;
    const width = Math.max(280, w || 800);
    const height = Math.max(200, h);
    if (Math.abs(width - this.sw) < 2 && Math.abs(height - this.sh) < 2) return false;
    this.sw = width;
    this.sh = height;
    return true;
  }

  private buildChart(): void {
    this.cd = null;
    this.pieSlices = [];
    this.stackSegs = [];
    if (this.data) this.cd = this.extractChartData();
    this.kind = this.resolveKind(this.pickedType ?? this.chartType);
    this.layout();
  }

  /** Scales, ticks and margins for the current data, type and size. */
  private layout(): void {
    const cd = this.cd;
    if (!cd || !cd.labels.length || !cd.datasets.length) return;

    if (this.kind === 'pie' || this.kind === 'doughnut') { this.buildPie(); return; }

    let values = cd.datasets.flatMap(d => d.data);
    if (this.kind === 'horizontal_bar') values = this.primaryData;
    if (this.kind === 'stacked_bar') values = this.buildStacked();
    this.setDomain(values);

    // Margins: room for the longest tick label, and for slanted category
    // labels when they would otherwise collide.
    const tickChars = Math.max(...this.yTicks.map(t => this.formatTick(t).length), 1);
    this.ml = Math.max(36, 14 + tickChars * 7);
    const slot = this.cw / Math.max(cd.labels.length, 1);
    const longest = Math.max(...cd.labels.map(l => Math.min(l.length, 12)), 1);
    this.rotateLabels = this.kind !== 'horizontal_bar' && longest * 6.5 > slot;
    this.mb = this.kind === 'horizontal_bar' ? 28 : this.rotateLabels ? 74 : 36;
  }

  /** The value axis: always includes zero, never zero-width, ends on a tick. */
  private setDomain(values: number[]): void {
    const finite = values.filter(v => Number.isFinite(v));
    let lo = Math.min(0, ...finite);
    let hi = Math.max(0, ...finite);
    if (hi === lo) hi = lo + 1;
    const ticks = this.generateTicks(lo, hi, 5);
    lo = ticks[0];
    hi = ticks[ticks.length - 1];
    this.minVal = lo;
    this.maxVal = hi > lo ? hi : lo + 1;
    this.yTicks = ticks;
    this.xTicks = ticks;
  }

  /**
   * The drawing to use for a requested type.
   *
   * The report viewer passes the API's report shape, which is not a drawing:
   * a `series` (one value per period) reads as a line, a short `breakdown`
   * (parts of a whole) as a doughnut, and anything else as bars.
   */
  private resolveKind(requested: string | null | undefined): string {
    const t = (requested || '').toLowerCase();
    const n = this.cd?.labels.length ?? 0;
    const sets = this.cd?.datasets.length ?? 0;
    const drawable = ['bar', 'grouped_bar', 'line', 'area', 'pie', 'doughnut', 'horizontal_bar', 'stacked_bar'];

    if (drawable.includes(t)) {
      // A line through one point is a dot; a stack of one series is a bar.
      if ((t === 'line' || t === 'area') && n < 2) return 'bar';
      if (t === 'stacked_bar' && sets < 2) return 'bar';
      return t;
    }
    if (t === 'series') return n >= 2 ? 'line' : 'bar';
    if (t === 'breakdown') return n <= 8 && sets === 1 ? 'doughnut' : sets === 1 && n > 12 ? 'horizontal_bar' : 'bar';
    return sets === 1 && n > 12 ? 'horizontal_bar' : 'bar';
  }

  /* ═══════ DATA EXTRACTION ═══════ */

  private extractChartData(): ChartData | null {
    const d = this.data;
    if (Array.isArray(d)) return this.extractGeneric(d);
    if (typeof d !== 'object') return null;

    // A ready-made {labels, datasets}.
    if (Array.isArray(d.labels) && Array.isArray(d.datasets)) {
      return this.clean({
        labels: d.labels.map((l: unknown) => String(l ?? '')),
        datasets: d.datasets.map((ds: any) => ({ label: String(ds?.label ?? ''), data: (ds?.data ?? []).map(toNum) })),
      });
    }

    // Named sub-objects (legacy report payloads).
    if (d.trends) return this.extractArray(d.trends, ['month', 'period', 'quarter'], ['enrollments', 'completions', 'total', 'count', 'value']);
    if (d.monthly_trend) return this.extractArray(d.monthly_trend, ['month', 'month_key', 'label'], ['completions', 'enrollments', 'total', 'count']);
    if (d.by_department) return this.extractArray(d.by_department, ['department_name', 'department'], ['compliance_rate', 'completion_rate', 'total', 'count', 'learners']);
    if (d.by_branch) return this.extractArray(d.by_branch, ['branch_name', 'branch'], ['compliance_rate', 'completion_rate']);
    if (d.by_team) return this.extractArray(d.by_team, ['team_name', 'team'], ['compliance_rate', 'completion_rate']);
    if (d.by_hour) return this.extractArray(d.by_hour, ['time_label', 'hour'], ['sessions', 'total']);
    if (d.by_day) return this.extractArray(d.by_day, ['day_name', 'day'], ['sessions', 'total']);
    if (d.by_activity) return this.extractArray(d.by_activity, ['activity_type', 'type'], ['total_points', 'total', 'count']);

    if (d.rating_distribution) {
      const rd = d.rating_distribution;
      return this.clean({
        labels: ['5★', '4★', '3★', '2★', '1★'],
        datasets: [{ label: this.i18n.t('reports.chart_value'), data: [rd['5_star'], rd['4_star'], rd['3_star'], rd['2_star'], rd['1_star']].map(toNum) }],
      });
    }
    if (d.score_distribution) return this.extractObject(d.score_distribution);
    if (d.grade_distribution) return this.extractObject(d.grade_distribution);

    const rows: any[] = Array.isArray(d.data) ? d.data : [];
    if (!rows.length) return null;

    // The caller says which column is the label and which are the values.
    if (d.chart_config) return this.extractFromConfig(rows, d.chart_config);

    // The API describes its columns: use them rather than guess from names.
    if (Array.isArray(d.columns) && d.columns.length) {
      const fromColumns = this.extractFromColumns(rows, d.columns);
      if (fromColumns) return fromColumns;
    }

    return this.extractGeneric(rows, d.report_name);
  }

  private extractArray(arr: any[], labelKeys: string[], valueKeys: string[]): ChartData | null {
    if (!Array.isArray(arr) || !arr.length) return null;
    const labelKey = labelKeys.find(k => arr[0]?.[k] !== undefined);
    if (!labelKey) return this.extractGeneric(arr);
    const foundKeys = valueKeys.filter(k => arr[0]?.[k] !== undefined);
    if (!foundKeys.length) return this.extractGeneric(arr);
    const sliced = arr.slice(0, MAX_POINTS);
    return this.clean({
      labels: sliced.map(r => String(r?.[labelKey] ?? '')),
      datasets: foundKeys.map(k => ({ label: this.formatKey(k), data: sliced.map(r => toNum(r?.[k])) })),
    });
  }

  private extractObject(obj: any): ChartData | null {
    if (!obj || typeof obj !== 'object') return null;
    const keys = Object.keys(obj);
    return this.clean({ labels: keys, datasets: [{ label: this.i18n.t('reports.chart_value'), data: keys.map(k => toNum(obj[k])) }] });
  }

  private extractFromConfig(data: any[], config: any): ChartData | null {
    const xKey = config?.x_axis;
    const yKeys: string[] = config?.y_axes || (config?.y_axis ? [config.y_axis] : []);
    if (!xKey || !yKeys.length) return this.extractGeneric(data);

    const sliced = data.slice(0, MAX_POINTS);
    return this.clean({
      labels: sliced.map(r => this.labelText(r?.[xKey])),
      datasets: yKeys.map((k: string) => ({ label: this.formatKey(k), data: sliced.map(r => toNum(r?.[k])) })),
    });
  }

  /**
   * Rows described by the API's columns ({key, label, type}).
   *
   * The label is the first text column (a name, a course, a period), the
   * values are the numeric columns of the same unit as the first one — a
   * percentage and a count of seconds on one axis would flatten one of them.
   * Durations are drawn in hours.
   */
  private extractFromColumns(rows: any[], columns: Array<{ key: string; label: string; type: string }>): ChartData | null {
    const sample = rows[0] ?? {};
    const present = columns.filter(c => c && c.key in sample);
    const label = present.find(c => (c.type === 'text' || c.type === 'status') && !/email/i.test(c.key))
      ?? present.find(c => c.type === 'date');
    const numeric = present.filter(c => NUMERIC_COLUMN_TYPES.has(c.type) && c !== label
      && rows.some(r => Number.isFinite(toNumOrNaN(r?.[c.key]))));
    if (!label || !numeric.length) return null;

    const unit = numeric[0].type;
    const values = numeric.filter(c => c.type === unit).slice(0, 3);
    const sliced = rows.slice(0, MAX_POINTS);
    const scale = unit === 'duration' ? 1 / 3600 : 1;

    return this.clean({
      labels: sliced.map(r => this.labelText(r?.[label.key])),
      datasets: values.map(c => ({
        label: unit === 'duration' ? `${c.label} (h)` : c.label,
        data: sliced.map(r => round1(toNum(r?.[c.key]) * scale)),
      })),
    });
  }

  /**
   * Rows with no description: a label key by name, and the numeric keys.
   *
   * API series rows are `{label, value, …}` with camelCase extras; legacy rows
   * are snake_case. Keys of a different unit from the first (a rate beside a
   * count, seconds beside either) are left off the axis.
   */
  private extractGeneric(arr: any[], valueLabel?: string): ChartData | null {
    const rows = (arr ?? []).filter(r => r && typeof r === 'object');
    if (!rows.length) return null;
    const sample = rows[0];

    const labelKey = LABEL_CANDIDATES.find(k => sample[k] !== undefined && sample[k] !== null && typeof sample[k] !== 'object')
      ?? Object.keys(sample).find(k => typeof sample[k] === 'string' && !isIdKey(k) && !Number.isFinite(toNumOrNaN(sample[k])));
    if (!labelKey) return null;

    const numericKeys = Object.keys(sample).filter(k =>
      k !== labelKey && !isIdKey(k) && rows.some(r => Number.isFinite(toNumOrNaN(r[k])) && typeof r[k] !== 'boolean'));
    // `value` leads; `percent` is a restatement of it.
    const ordered = numericKeys.includes('value')
      ? ['value', ...numericKeys.filter(k => k !== 'value' && k !== 'percent')]
      : numericKeys;
    if (!ordered.length) return null;

    const family = unitFamily(ordered[0]);
    const keys = ordered.filter(k => unitFamily(k) === family).slice(0, 3);
    const sliced = rows.slice(0, MAX_POINTS);

    return this.clean({
      labels: sliced.map(r => this.labelText(r[labelKey])),
      datasets: keys.map(k => ({
        label: k === 'value' ? (valueLabel || this.i18n.t('reports.chart_value')) : this.formatKey(k),
        data: sliced.map(r => toNum(r[k])),
      })),
    });
  }

  /** Drops nothing, but guarantees every number is finite and every array aligned. */
  private clean(cd: ChartData): ChartData | null {
    const n = cd.labels.length;
    if (!n) return null;
    const datasets = cd.datasets
      .map(ds => ({ label: ds.label, data: Array.from({ length: n }, (_, i) => toNum(ds.data[i])) }));
    return datasets.length ? { labels: cd.labels, datasets } : null;
  }

  private labelText(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    return String(v);
  }

  /* ═══════ SCALE FUNCTIONS ═══════ */

  yScale(v: number): number {
    const range = this.maxVal - this.minVal || 1;
    return this.ch - ((toNum(v) - this.minVal) / range) * this.ch;
  }
  barTop(v: number): number { return Math.min(this.yScale(v), this.yScale(0)); }
  barHeight(v: number): number { return Math.abs(this.yScale(0) - this.yScale(v)); }

  // Horizontal bar: category labels on the left, values along x.
  get hLabelW(): number { return Math.min(130, Math.max(70, this.sw * 0.22)); }
  get hPlotW(): number { return Math.max(10, this.sw - this.hLabelW - this.mr - 8); }
  hXScale(v: number): number {
    const range = this.maxVal - this.minVal || 1;
    return ((toNum(v) - this.minVal) / range) * this.hPlotW;
  }
  hBarLeft(v: number): number { return Math.min(this.hXScale(v), this.hXScale(0)); }
  hBarLength(v: number): number { return Math.abs(this.hXScale(v) - this.hXScale(0)); }

  // Bar positioning
  get barGroupWidth(): number { return this.cw / Math.max(this.cd?.labels.length ?? 1, 1); }
  get barWidth(): number {
    const n = this.cd?.datasets.length || 1;
    return Math.max(2, Math.min(40, (this.barGroupWidth * 0.7) / n));
  }
  barX(i: number, di: number): number {
    const n = this.cd?.datasets.length || 1;
    return i * this.barGroupWidth + (this.barGroupWidth - this.barWidth * n) / 2 + di * this.barWidth;
  }
  barGroupCenter(i: number): number { return i * this.barGroupWidth + this.barGroupWidth / 2; }

  // Line positioning: one point sits in the middle rather than on the axis.
  lineX(i: number): number {
    const n = this.cd?.labels.length ?? 0;
    if (n <= 1) return this.cw / 2;
    return (i / (n - 1)) * this.cw;
  }
  linePath(data: number[]): string {
    return (data ?? []).map((v, i) => `${i === 0 ? 'M' : 'L'}${fx(this.lineX(i))},${fx(this.yScale(v))}`).join(' ');
  }
  areaPath(data: number[]): string {
    if (!data?.length) return '';
    const base = fx(this.yScale(Math.max(this.minVal, Math.min(0, this.maxVal))));
    return `${this.linePath(data)} L${fx(this.lineX(data.length - 1))},${base} L${fx(this.lineX(0))},${base} Z`;
  }

  get hBarHeight(): number { return Math.max(4, Math.min(28, (this.ch / Math.max(this.cd?.labels.length ?? 1, 1)) * 0.7)); }
  hBarY(i: number): number {
    const gap = this.ch / Math.max(this.cd?.labels.length ?? 1, 1);
    return i * gap + (gap - this.hBarHeight) / 2;
  }
  get primaryData(): number[] { return this.cd?.datasets[0]?.data || []; }

  // Stacked bar
  get stackBarW(): number { return Math.max(4, Math.min(50, (this.cw / Math.max(this.cd?.labels.length ?? 1, 1)) * 0.6)); }
  stackBarX(i: number): number {
    const gap = this.cw / Math.max(this.cd?.labels.length ?? 1, 1);
    return i * gap + (gap - this.stackBarW) / 2;
  }

  /** Stacks the positive parts of each column; returns the column totals. */
  private buildStacked(): number[] {
    const cd = this.cd!;
    this.stackSegs = cd.labels.map((_, i) => {
      let base = 0;
      return cd.datasets.map(ds => {
        const v = Math.max(0, toNum(ds.data[i]));
        const seg = { bottom: base, top: base + v, value: v };
        base += v;
        return seg;
      });
    });
    return this.stackSegs.map(s => s[s.length - 1]?.top ?? 0);
  }

  /* ═══════ PIE ═══════ */

  private buildPie(): void {
    const cd = this.cd!;
    const raw = cd.datasets[0]?.data || [];
    // A share of a whole cannot be negative or nothing; those are left out.
    const parts = raw
      .map((v, i) => ({ v: toNum(v), label: cd.labels[i] || '', color: this.getColor(i) }))
      .filter(p => p.v > 0);
    const total = parts.reduce((a, p) => a + p.v, 0);
    this.pieTotal = total;
    if (total <= 0) { this.pieSlices = []; return; }

    const radius = Math.max(20, Math.min(this.sw, this.sh) / 2 - 16);
    const inner = this.kind === 'doughnut' ? radius * 0.58 : 0;
    let angle = -Math.PI / 2;

    this.pieSlices = parts.map(p => {
      const sweep = (p.v / total) * Math.PI * 2;
      const path = sweep >= Math.PI * 2 - 1e-6
        ? ringPath(radius, inner)
        : arcPath(radius, inner, angle, angle + sweep);
      angle += sweep;
      return { path, label: p.label, value: p.v, percent: (p.v / total) * 100, color: p.color };
    });
  }

  /* ═══════ UTILITIES ═══════ */

  private generateTicks(min: number, max: number, count: number): number[] {
    const step = this.niceNum((max - min) / count);
    const nMin = Math.floor(min / step) * step;
    const nMax = Math.ceil(max / step) * step;
    const ticks: number[] = [];
    for (let i = 0, v = nMin; v <= nMax + step / 2 && i < 50; i++, v = nMin + i * step) {
      ticks.push(Math.round(v * 1000) / 1000);
    }
    return ticks.length >= 2 ? ticks : [nMin, nMin + step];
  }

  private niceNum(range: number): number {
    const r = Number.isFinite(range) && range > 0 ? range : 1;
    const exp = Math.floor(Math.log10(r));
    const frac = r / Math.pow(10, exp);
    return (frac <= 1.5 ? 1 : frac <= 3 ? 2 : frac <= 7 ? 5 : 10) * Math.pow(10, exp);
  }

  formatTick(v: number): string {
    const a = Math.abs(v);
    const sign = v < 0 ? '-' : '';
    if (a >= 1e6) return sign + (a / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (a >= 1000) return sign + (a / 1000).toFixed(a >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
    return String(Math.round(v * 100) / 100);
  }

  truncLabel(label: string, max = 12): string {
    const s = String(label ?? '');
    return s.length > max ? s.substring(0, max - 1) + '…' : s;
  }

  private formatKey(key: string): string {
    return key
      .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
      .replace(/_/g, ' ')
      .replace(/\b\w/g, c => c.toUpperCase());
  }
}

/** At most this many categories are drawn; past that a chart is unreadable. */
const MAX_POINTS = 20;

const NUMERIC_COLUMN_TYPES = new Set(['number', 'percent', 'duration']);

const LABEL_CANDIDATES = [
  'label', 'name', 'title', 'period', 'month', 'course', 'course_title', 'course_name', 'learner', 'learner_name',
  'department', 'department_name', 'branch', 'branch_name', 'team', 'team_name', 'group_name', 'cohort',
  'path', 'path_name', 'badge', 'badge_name', 'assessment', 'training_title', 'date', 'skill_area', 'topic',
  'venue', 'instructor', 'instructor_name', 'resource_name', 'media_type', 'course_type', 'metric',
  'manager', 'manager_name', 'activity_date', 'day_name', 'period_date', 'full_name', 'source',
  'enrollment_source', 'category', 'status', 'range', 'score_range', 'grade', 'week', 'year_month',
  'quarter', 'assigned_by', 'risk_level', 'type',
];

function isIdKey(k: string): boolean {
  return k === 'id' || /_id$/.test(k) || /[a-z]Id$/.test(k);
}

/** Rates, durations and counts do not share an axis. */
function unitFamily(k: string): 'rate' | 'time' | 'count' {
  if (/rate|percent|score|utili[sz]ation|progress|avg/i.test(k)) return 'rate';
  if (/seconds|minutes|hours|time/i.test(k)) return 'time';
  return 'count';
}

/** A finite number, or NaN — numeric strings (Postgres decimals) count. */
function toNumOrNaN(v: unknown): number {
  if (typeof v === 'number') return v;
  if (typeof v === 'string' && v.trim() !== '') return Number(v);
  return NaN;
}

/** A finite number, with anything else (null, NaN, Infinity, text) as 0. */
function toNum(v: unknown): number {
  const n = toNumOrNaN(v);
  return Number.isFinite(n) ? n : 0;
}

function round1(v: number): number { return Math.round(v * 10) / 10; }

/** A coordinate for a path string: finite, and short. */
function fx(v: number): number { return Number.isFinite(v) ? Math.round(v * 100) / 100 : 0; }

/** One slice (or ring segment) from angle a0 to a1. */
function arcPath(r: number, ir: number, a0: number, a1: number): string {
  const large = a1 - a0 > Math.PI ? 1 : 0;
  const [x0, y0, x1, y1] = [Math.cos(a0) * r, Math.sin(a0) * r, Math.cos(a1) * r, Math.sin(a1) * r].map(fx);
  if (ir <= 0) return `M0,0 L${x0},${y0} A${fx(r)},${fx(r)} 0 ${large},1 ${x1},${y1} Z`;
  const [ix0, iy0, ix1, iy1] = [Math.cos(a0) * ir, Math.sin(a0) * ir, Math.cos(a1) * ir, Math.sin(a1) * ir].map(fx);
  return `M${ix0},${iy0} L${x0},${y0} A${fx(r)},${fx(r)} 0 ${large},1 ${x1},${y1} L${ix1},${iy1} A${fx(ir)},${fx(ir)} 0 ${large},0 ${ix0},${iy0} Z`;
}

/**
 * The whole circle (or ring) for a single slice of 100%.
 *
 * An arc whose start and end points coincide draws nothing, which is why a
 * one-slice pie used to come out blank; two half-circles do not.
 */
function ringPath(r: number, ir: number): string {
  const R = fx(r);
  const outer = `M${R},0 A${R},${R} 0 1,1 ${-R},0 A${R},${R} 0 1,1 ${R},0 Z`;
  if (ir <= 0) return outer;
  const I = fx(ir);
  return `${outer} M${I},0 A${I},${I} 0 1,0 ${-I},0 A${I},${I} 0 1,0 ${I},0 Z`;
}
