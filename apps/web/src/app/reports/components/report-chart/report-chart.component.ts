// reports/components/report-chart/report-chart.component.ts

import { Component, Input, OnChanges, SimpleChanges, ElementRef, ViewChild } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { ChartData, ChartDataset, ChartType, PieSlice } from '../../models/report.models';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

interface StackSeg { bottom: number; top: number; value: number; }

@Component({
  selector: 'app-report-chart',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  providers: [DecimalPipe],
  template: `
    <div class="report-chart card border-0 shadow-sm">
      <div class="card-body">
        <!-- Header with type switcher -->
        <div class="d-flex justify-content-between align-items-center mb-3" *ngIf="allowTypeChange || title">
          <h6 class="mb-0 text-dark fw-semibold" *ngIf="title">{{ title }}</h6>
          <div class="btn-group btn-group-sm" *ngIf="allowTypeChange">
            <button *ngFor="let t of availableTypes" class="btn btn-success"
                    [class.active]="chartType === t.key" (click)="setType(t.key)"
                    [title]="t.label">
              <i [class]="t.icon"></i>
            </button>
          </div>
        </div>

        <!-- SVG Canvas -->
        <div class="chart-wrap" [style.height]="height">
          <svg #svgEl [attr.viewBox]="viewBox" preserveAspectRatio="xMidYMid meet"
               width="100%" [attr.height]="height"
               *ngIf="cd && cd.labels.length">

            <!-- ═══ BAR CHART ═══ -->
            <ng-container *ngIf="chartType === 'bar' || chartType === 'grouped_bar'">
              <g [attr.transform]="'translate(' + ml + ',' + mt + ')'">
                <!-- Grid lines -->
                <line *ngFor="let t of yTicks" x1="0" [attr.y1]="yScale(t)"
                      [attr.x2]="cw" [attr.y2]="yScale(t)" stroke="#f0f0f0" stroke-width="1"/>
                <!-- Y labels -->
                <text *ngFor="let t of yTicks" x="-10" [attr.y]="yScale(t) + 4"
                      text-anchor="end" fill="#6b7280" font-size="11">{{ formatTick(t) }}</text>
                <!-- Bars -->
                <ng-container *ngFor="let ds of cd.datasets; let di = index">
                  <rect *ngFor="let v of ds.data; let i = index"
                        [attr.x]="barX(i, di)" [attr.y]="yScale(v)"
                        [attr.width]="barWidth"
                        [attr.height]="Math.max(0, ch - yScale(v))"
                        [attr.fill]="getColor(di)" rx="3" class="chart-bar">
                    <title>{{ cd.labels[i] }}: {{ dp.transform(v, '1.0-1') }}</title>
                  </rect>
                </ng-container>
                <!-- X labels -->
                <text *ngFor="let l of cd.labels; let i = index"
                      [attr.x]="barGroupCenter(i)" [attr.y]="ch + 20"
                      text-anchor="middle" fill="#6b7280" font-size="11"
                      [attr.transform]="cd.labels.length > 8 ? 'rotate(-35,' + barGroupCenter(i) + ',' + (ch + 20) + ')' : ''">
                  {{ truncLabel(l) }}
                </text>
              </g>
            </ng-container>

            <!-- ═══ LINE / AREA CHART ═══ -->
            <ng-container *ngIf="chartType === 'line' || chartType === 'area'">
              <g [attr.transform]="'translate(' + ml + ',' + mt + ')'">
                <line *ngFor="let t of yTicks" x1="0" [attr.y1]="yScale(t)"
                      [attr.x2]="cw" [attr.y2]="yScale(t)" stroke="#f0f0f0"/>
                <text *ngFor="let t of yTicks" x="-10" [attr.y]="yScale(t) + 4"
                      text-anchor="end" fill="#6b7280" font-size="11">{{ formatTick(t) }}</text>
                <!-- Area fill -->
                <ng-container *ngIf="chartType === 'area'">
                  <path *ngFor="let ds of cd.datasets; let di = index"
                        [attr.d]="areaPath(ds.data)" [attr.fill]="getColor(di)"
                        fill-opacity="0.1"/>
                </ng-container>
                <!-- Lines -->
                <path *ngFor="let ds of cd.datasets; let di = index"
                      [attr.d]="linePath(ds.data)" fill="none"
                      [attr.stroke]="getColor(di)" stroke-width="2.5"
                      stroke-linecap="round" stroke-linejoin="round"/>
                <!-- Points -->
                <ng-container *ngFor="let ds of cd.datasets; let di = index">
                  <circle *ngFor="let v of ds.data; let i = index"
                          [attr.cx]="lineX(i)" [attr.cy]="yScale(v)" r="4"
                          [attr.fill]="getColor(di)" stroke="white" stroke-width="2"
                          class="chart-point">
                    <title>{{ cd.labels[i] }}: {{ dp.transform(v, '1.0-1') }}</title>
                  </circle>
                </ng-container>
                <!-- X labels -->
                <text *ngFor="let l of cd.labels; let i = index"
                      [attr.x]="lineX(i)" [attr.y]="ch + 20"
                      text-anchor="middle" fill="#6b7280" font-size="11">{{ truncLabel(l) }}</text>
              </g>
            </ng-container>

            <!-- ═══ PIE / DOUGHNUT ═══ -->
            <ng-container *ngIf="chartType === 'pie' || chartType === 'doughnut'">
              <g [attr.transform]="'translate(' + (sw / 2) + ',' + (sh / 2) + ')'">
                <path *ngFor="let s of pieSlices; let i = index"
                      [attr.d]="s.path" [attr.fill]="s.color"
                      stroke="white" stroke-width="2" class="chart-bar">
                  <title>{{ s.label }}: {{ dp.transform(s.value, '1.0-1') }} ({{ dp.transform(s.percent, '1.0-1') }}%)</title>
                </path>
                <circle *ngIf="chartType === 'doughnut'" cx="0" cy="0"
                        [attr.r]="pieInnerRadius" fill="white"/>
                <text *ngIf="chartType === 'doughnut'" text-anchor="middle"
                      dominant-baseline="central" fill="#374151"
                      font-size="18" font-weight="600">
                  {{ dp.transform(pieTotal, '1.0-0') }}
                </text>
              </g>
            </ng-container>

            <!-- ═══ HORIZONTAL BAR ═══ -->
            <ng-container *ngIf="chartType === 'horizontal_bar'">
              <g [attr.transform]="'translate(' + (ml + 90) + ',' + mt + ')'">
                <line *ngFor="let t of xTicks" [attr.x1]="hXScale(t)" y1="0"
                      [attr.x2]="hXScale(t)" [attr.y2]="ch" stroke="#f0f0f0"/>
                <text *ngFor="let t of xTicks" [attr.x]="hXScale(t)"
                      [attr.y]="ch + 16" text-anchor="middle" fill="#6b7280" font-size="11">
                  {{ formatTick(t) }}
                </text>
                <rect *ngFor="let v of primaryData; let i = index"
                      x="0" [attr.y]="hBarY(i)"
                      [attr.width]="Math.max(0, hXScale(v))"
                      [attr.height]="hBarHeight" [attr.fill]="getColor(i % colors.length)"
                      rx="3" class="chart-bar">
                  <title>{{ cd.labels[i] }}: {{ dp.transform(v, '1.0-1') }}</title>
                </rect>
                <text *ngFor="let l of cd.labels; let i = index"
                      x="-8" [attr.y]="hBarY(i) + hBarHeight / 2 + 4"
                      text-anchor="end" fill="#374151" font-size="11">{{ truncLabel(l, 16) }}</text>
              </g>
            </ng-container>

            <!-- ═══ STACKED BAR ═══ -->
            <ng-container *ngIf="chartType === 'stacked_bar'">
              <g [attr.transform]="'translate(' + ml + ',' + mt + ')'">
                <line *ngFor="let t of yTicks" x1="0" [attr.y1]="yScale(t)"
                      [attr.x2]="cw" [attr.y2]="yScale(t)" stroke="#f0f0f0"/>
                <text *ngFor="let t of yTicks" x="-10" [attr.y]="yScale(t) + 4"
                      text-anchor="end" fill="#6b7280" font-size="11">{{ formatTick(t) }}</text>
                <ng-container *ngFor="let l of cd.labels; let i = index">
                  <rect *ngFor="let seg of stackSegs[i]; let si = index"
                        [attr.x]="stackBarX(i)" [attr.y]="yScale(seg.top)"
                        [attr.width]="stackBarW"
                        [attr.height]="Math.max(0, yScale(seg.bottom) - yScale(seg.top))"
                        [attr.fill]="getColor(si)" rx="2">
                    <title>{{ cd.datasets[si]?.label }}: {{ dp.transform(seg.value, '1.0-1') }}</title>
                  </rect>
                </ng-container>
                <text *ngFor="let l of cd.labels; let i = index"
                      [attr.x]="stackBarX(i) + stackBarW / 2" [attr.y]="ch + 20"
                      text-anchor="middle" fill="#6b7280" font-size="11">{{ truncLabel(l) }}</text>
              </g>
            </ng-container>
          </svg>

          <!-- Empty state -->
          <div *ngIf="!cd || !cd.labels.length" class="text-center py-5 text-muted">
            <i class="bi bi-bar-chart-line fs-1 d-block mb-2"></i>
            <p class="mb-0">{{ 'reports.no_chart_data' | translate }}</p>
          </div>
        </div>

        <!-- Legend -->
        <div class="d-flex flex-wrap justify-content-center gap-3 mt-3"
             *ngIf="cd && cd.datasets.length > 0 && chartType !== 'pie' && chartType !== 'doughnut'">
          <div *ngFor="let ds of cd.datasets; let i = index" class="d-flex align-items-center gap-2">
            <span class="legend-dot" [style.backgroundColor]="getColor(i)"></span>
            <span class="small text-muted">{{ ds.label }}</span>
          </div>
        </div>
        <!-- Pie/Doughnut Legend -->
        <div class="d-flex flex-wrap justify-content-center gap-3 mt-3"
             *ngIf="cd && (chartType === 'pie' || chartType === 'doughnut') && pieSlices.length">
          <div *ngFor="let s of pieSlices" class="d-flex align-items-center gap-2">
            <span class="legend-dot" [style.backgroundColor]="s.color"></span>
            <span class="small text-muted">{{ s.label }} ({{ dp.transform(s.percent, '1.0-1') }}%)</span>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .chart-wrap { position: relative; width: 100%; overflow: hidden; }
    .chart-bar { transition: opacity 0.2s; cursor: pointer; }
    .chart-bar:hover { opacity: 0.75; }
    .chart-point { transition: r 0.2s; cursor: pointer; }
    .chart-point:hover { r: 6; }
    .legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
  `]
})
export class ReportChartComponent implements OnChanges {
  @Input() data: any;
  @Input() reportKey = '';
  @Input() chartType: string = 'bar';
  @Input() height = '380px';
  @Input() allowTypeChange = true;
  @Input() title = '';
  @ViewChild('svgEl') svgEl!: ElementRef;

  Math = Math;
  cd: ChartData | null = null;

  // SVG dimensions
  sw = 800; sh = 400; ml = 60; mt = 20; mr = 20; mb = 45;
  get cw() { return this.sw - this.ml - this.mr; }
  get ch() { return this.sh - this.mt - this.mb; }
  get viewBox() { return `0 0 ${this.sw} ${this.sh}`; }

  yTicks: number[] = [];
  xTicks: number[] = [];
  maxVal = 100;
  pieSlices: PieSlice[] = [];
  pieTotal = 0;
  pieInnerRadius = 0;
  stackSegs: StackSeg[][] = [];

  availableTypes = [
    { key: 'bar', icon: 'bi bi-bar-chart', label: 'Bar' },
    { key: 'line', icon: 'bi bi-graph-up', label: 'Line' },
    { key: 'area', icon: 'bi bi-graph-up', label: 'Area' },
    { key: 'pie', icon: 'bi bi-pie-chart', label: 'Pie' },
    { key: 'doughnut', icon: 'bi bi-circle', label: 'Doughnut' },
    { key: 'horizontal_bar', icon: 'bi bi-bar-chart-steps', label: 'Horizontal' },
    { key: 'stacked_bar', icon: 'bi bi-stack', label: 'Stacked' }
  ];

  colors = [
    '#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#3b82f6',
    '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1',
    '#06b6d4', '#84cc16'
  ];

  constructor(public dp: DecimalPipe, private i18n: I18nService) {
    this.availableTypes = [
      { key: 'bar', icon: 'bi bi-bar-chart', label: this.i18n.t('reports.chart_bar') },
      { key: 'line', icon: 'bi bi-graph-up', label: this.i18n.t('reports.chart_line') },
      { key: 'area', icon: 'bi bi-graph-up', label: this.i18n.t('reports.chart_area') },
      { key: 'pie', icon: 'bi bi-pie-chart', label: this.i18n.t('reports.chart_pie') },
      { key: 'doughnut', icon: 'bi bi-circle', label: this.i18n.t('reports.chart_doughnut') },
      { key: 'horizontal_bar', icon: 'bi bi-bar-chart-steps', label: this.i18n.t('reports.chart_horizontal') },
      { key: 'stacked_bar', icon: 'bi bi-stack', label: this.i18n.t('reports.chart_stacked') }
    ];
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['data'] || changes['chartType']) this.buildChart();
  }

  setType(type: string): void {
    this.chartType = type;
    this.buildChart();
  }

  getColor(i: number): string { return this.colors[i % this.colors.length]; }

  /* ═══════ BUILD ═══════ */

  private buildChart(): void {
    if (!this.data) { this.cd = null; return; }
    this.cd = this.extractChartData();
    if (!this.cd?.labels.length) return;

    const allValues = this.cd.datasets.flatMap(d => d.data);
    this.maxVal = Math.max(...allValues, 1);
    this.yTicks = this.generateTicks(0, this.maxVal, 5);
    this.xTicks = this.generateTicks(0, this.maxVal, 5);

    if (this.chartType === 'pie' || this.chartType === 'doughnut') this.buildPie();
    if (this.chartType === 'stacked_bar') this.buildStacked();
  }

  /* ═══════ DATA EXTRACTION - matches all 195 backend report structures ═══════ */

  private extractChartData(): ChartData | null {
    const d = this.data;

    // Named sub-objects (phase 1-10 patterns)
    if (d.trends) return this.extractArray(d.trends, ['month', 'period', 'quarter'], ['enrollments', 'completions', 'total', 'count', 'value']);
    if (d.monthly_trend) return this.extractArray(d.monthly_trend, ['month', 'month_key', 'label'], ['completions', 'enrollments', 'total', 'count']);
    if (d.by_department) return this.extractArray(d.by_department, ['department_name', 'department'], ['compliance_rate', 'completion_rate', 'total', 'count', 'learners']);
    if (d.by_branch) return this.extractArray(d.by_branch, ['branch_name', 'branch'], ['compliance_rate', 'completion_rate']);
    if (d.by_team) return this.extractArray(d.by_team, ['team_name', 'team'], ['compliance_rate', 'completion_rate']);
    if (d.by_hour) return this.extractArray(d.by_hour, ['time_label', 'hour'], ['sessions', 'total']);
    if (d.by_day) return this.extractArray(d.by_day, ['day_name', 'day'], ['sessions', 'total']);
    if (d.by_activity) return this.extractArray(d.by_activity, ['activity_type', 'type'], ['total_points', 'total', 'count']);

    // Distribution objects
    if (d.rating_distribution) {
      const rd = d.rating_distribution;
      return {
        labels: ['5★', '4★', '3★', '2★', '1★'],
        datasets: [{ label: 'Ratings', data: [rd['5_star'] || 0, rd['4_star'] || 0, rd['3_star'] || 0, rd['2_star'] || 0, rd['1_star'] || 0] }]
      };
    }
    if (d.score_distribution) return this.extractObject(d.score_distribution, 'Count');
    if (d.grade_distribution) return this.extractObject(d.grade_distribution, 'Count');

    // Chart config from backend (phases 11-15)
    if (d.chart_config && d.data && Array.isArray(d.data)) {
      return this.extractFromConfig(d.data, d.chart_config);
    }

    // Generic data array
    if (d.data && Array.isArray(d.data)) return this.extractGeneric(d.data);
    if (Array.isArray(d)) return this.extractGeneric(d);

    return null;
  }

  private extractArray(arr: any[], labelKeys: string[], valueKeys: string[]): ChartData | null {
    if (!Array.isArray(arr) || !arr.length) return null;
    const labelKey = labelKeys.find(k => arr[0][k] !== undefined);
    if (!labelKey) return null;
    const foundKeys = valueKeys.filter(k => arr[0][k] !== undefined);
    if (!foundKeys.length) return null;
    const sliced = arr.slice(0, 20);
    return {
      labels: sliced.map(r => String(r[labelKey] || '')),
      datasets: foundKeys.map(k => ({
        label: this.formatKey(k),
        data: sliced.map(r => Number(r[k]) || 0)
      }))
    };
  }

  private extractObject(obj: any, label: string): ChartData {
    const keys = Object.keys(obj);
    return { labels: keys, datasets: [{ label, data: keys.map(k => Number(obj[k]) || 0) }] };
  }

  private extractFromConfig(data: any[], config: any): ChartData | null {
    if (!data.length) return null;
    const xKey = config.x_axis;
    const yKeys = config.y_axes || (config.y_axis ? [config.y_axis] : []);
    if (!xKey || !yKeys.length) return this.extractGeneric(data);

    const sliced = data.slice(0, 20);
    return {
      labels: sliced.map(r => String(r[xKey] || '')),
      datasets: yKeys.map((k: string) => ({
        label: this.formatKey(k),
        data: sliced.map(r => Number(r[k]) || 0)
      }))
    };
  }

  private extractGeneric(arr: any[]): ChartData | null {
    if (!arr.length) return null;
    const sample = arr[0];

    // Find label key
    const labelCandidates = [
      'title', 'name', 'course_title', 'course_name', 'learner_name', 'department_name',
      'group_name', 'path_name', 'badge_name', 'training_title', 'month', 'cohort',
      'label', 'date', 'skill_area', 'topic', 'venue', 'instructor_name', 'resource_name',
      'media_type', 'course_type', 'metric', 'manager_name', 'activity_date', 'day_name',
      'period_date', 'period', 'full_name', 'source', 'enrollment_source', 'branch_name',
      'team_name', 'category', 'status', 'range', 'score_range', 'grade', 'email',
      'week', 'year_month', 'quarter', 'assigned_by', 'risk_level', 'type'
    ];
    const labelKey = labelCandidates.find(k => sample[k] !== undefined);
    if (!labelKey) return null;

    // Find numeric keys
    const numericKeys: string[] = [];
    const valueHints = [
      'completed', 'in_progress', 'total', 'count', 'score', 'rate', 'hours', 'points',
      'enrolled', 'learners', 'sessions', 'attended', 'minutes', 'avg', 'fill', 'time',
      'completions', 'enrollments', 'compliance', 'access_count', 'unique_users', 'cost',
      'spend', 'individual', 'peer_avg', 'difference', 'members', 'overdue',
      'assigned', 'pending', 'progress', 'not_started', 'mandatory', 'compliant',
      'non_compliant', 'users', 'new_enrollments', 'days_pending', 'risk'
    ];
    for (const [k, v] of Object.entries(sample)) {
      if (typeof v === 'number' && !k.endsWith('_id') && k !== 'id' &&
        valueHints.some(hint => k.includes(hint))) {
        numericKeys.push(k);
      }
    }
    if (!numericKeys.length) return null;

    const sliced = arr.slice(0, 15);
    return {
      labels: sliced.map(r => String(r[labelKey] || '')),
      datasets: numericKeys.slice(0, 3).map(k => ({
        label: this.formatKey(k),
        data: sliced.map(r => Number(r[k]) || 0)
      }))
    };
  }

  /* ═══════ SCALE FUNCTIONS ═══════ */

  yScale(v: number): number { return this.ch - (v / this.maxVal) * this.ch; }
  hXScale(v: number): number { return (v / this.maxVal) * (this.cw - 100); }

  // Bar positioning
  get barGroupWidth(): number { return this.cw / Math.max(this.cd!.labels.length, 1); }
  get barWidth(): number {
    const n = this.cd?.datasets.length || 1;
    return Math.max(8, Math.min(40, (this.barGroupWidth * 0.7) / n));
  }
  barX(i: number, di: number): number {
    const n = this.cd?.datasets.length || 1;
    return i * this.barGroupWidth + (this.barGroupWidth - this.barWidth * n) / 2 + di * this.barWidth;
  }
  barGroupCenter(i: number): number { return i * this.barGroupWidth + this.barGroupWidth / 2; }

  // Line positioning
  lineX(i: number): number { return (i / Math.max(this.cd!.labels.length - 1, 1)) * this.cw; }
  linePath(data: number[]): string {
    return data.map((v, i) => `${i === 0 ? 'M' : 'L'}${this.lineX(i)},${this.yScale(v)}`).join(' ');
  }
  areaPath(data: number[]): string {
    return `${this.linePath(data)} L${this.lineX(data.length - 1)},${this.ch} L${this.lineX(0)},${this.ch} Z`;
  }

  // Horizontal bar
  get hBarHeight(): number { return Math.max(14, Math.min(28, (this.ch / Math.max(this.cd!.labels.length, 1)) * 0.7)); }
  hBarY(i: number): number {
    const gap = this.ch / Math.max(this.cd!.labels.length, 1);
    return i * gap + (gap - this.hBarHeight) / 2;
  }
  get primaryData(): number[] { return this.cd?.datasets[0]?.data || []; }

  // Stacked bar
  get stackBarW(): number { return Math.max(14, Math.min(50, (this.cw / Math.max(this.cd!.labels.length, 1)) * 0.6)); }
  stackBarX(i: number): number {
    const gap = this.cw / Math.max(this.cd!.labels.length, 1);
    return i * gap + (gap - this.stackBarW) / 2;
  }

  private buildStacked(): void {
    if (!this.cd) return;
    this.stackSegs = [];
    for (let i = 0; i < this.cd.labels.length; i++) {
      let base = 0;
      const segs: StackSeg[] = [];
      for (const ds of this.cd.datasets) {
        const v = ds.data[i] || 0;
        segs.push({ bottom: base, top: base + v, value: v });
        base += v;
      }
      this.stackSegs.push(segs);
    }
    const stackMax = Math.max(...this.stackSegs.map(s => s[s.length - 1]?.top ?? 0), 1);
    this.maxVal = stackMax;
    this.yTicks = this.generateTicks(0, stackMax, 5);
  }

  /* ═══════ PIE ═══════ */

  private buildPie(): void {
    if (!this.cd) return;
    const data = this.cd.datasets[0]?.data || [];
    const labels = this.cd.labels;
    this.pieTotal = data.reduce((a, b) => a + b, 0) || 1;
    const radius = Math.min(this.sw, this.sh) / 2 - 30;
    this.pieInnerRadius = this.chartType === 'doughnut' ? radius * 0.55 : 0;

    let angle = -Math.PI / 2;
    this.pieSlices = data.map((v, i) => {
      const sweep = (v / this.pieTotal) * Math.PI * 2;
      const startAngle = angle;
      const endAngle = angle + sweep;
      angle = endAngle;

      const largeArc = sweep > Math.PI ? 1 : 0;
      const [x1, y1] = [Math.cos(startAngle) * radius, Math.sin(startAngle) * radius];
      const [x2, y2] = [Math.cos(endAngle) * radius, Math.sin(endAngle) * radius];

      let path: string;
      if (this.pieInnerRadius > 0) {
        const ir = this.pieInnerRadius;
        const [ix1, iy1] = [Math.cos(startAngle) * ir, Math.sin(startAngle) * ir];
        const [ix2, iy2] = [Math.cos(endAngle) * ir, Math.sin(endAngle) * ir];
        path = `M${ix1},${iy1} L${x1},${y1} A${radius},${radius} 0 ${largeArc},1 ${x2},${y2} L${ix2},${iy2} A${ir},${ir} 0 ${largeArc},0 ${ix1},${iy1} Z`;
      } else {
        path = `M0,0 L${x1},${y1} A${radius},${radius} 0 ${largeArc},1 ${x2},${y2} Z`;
      }

      return { path, label: labels[i] || '', value: v, percent: (v / this.pieTotal) * 100, color: this.getColor(i) };
    });
  }

  /* ═══════ UTILITIES ═══════ */

  private generateTicks(min: number, max: number, count: number): number[] {
    const step = this.niceNum((max - min) / count);
    const nMin = Math.floor(min / step) * step;
    const nMax = Math.ceil(max / step) * step;
    const ticks: number[] = [];
    for (let v = nMin; v <= nMax; v += step) ticks.push(Math.round(v * 100) / 100);
    return ticks;
  }

  private niceNum(range: number): number {
    const exp = Math.floor(Math.log10(Math.max(range, 0.001)));
    const frac = range / Math.pow(10, exp);
    return (frac <= 1.5 ? 1 : frac <= 3 ? 2 : frac <= 7 ? 5 : 10) * Math.pow(10, exp);
  }

  formatTick(v: number): string {
    if (v >= 1e6) return (v / 1e6).toFixed(1) + 'M';
    if (v >= 1000) return (v / 1000).toFixed(v >= 1e4 ? 0 : 1) + 'K';
    return String(v);
  }

  truncLabel(label: string, max = 12): string {
    return label.length > max ? label.substring(0, max - 1) + '…' : label;
  }

  private formatKey(key: string): string {
    return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }
}
