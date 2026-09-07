import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { ReportViewerComponent } from '../report-viewer/report-viewer.component';
import { CycleSelectComponent } from '../cycle-select/cycle-select.component';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import {
  CompetencyService,
  type ReadinessReport,
  type ReadinessDimension,
  type GapAnalysisReport,
  type CriticalGapsReport,
  type DistributionReport,
  type CompletionReport,
} from '../../competency.service';

/** Which report the section is showing. */
type ReportKey = 'readiness' | 'gaps' | 'critical' | 'distribution' | 'completion' | 'individual';

interface ReportTab {
  key: ReportKey;
  label: string;
  icon: string;
  blurb: string;
}

/**
 * The competency reports section — every analytical view over a cycle in one
 * place: readiness by group, the gap-analysis table, the critical-gap list,
 * the proficiency spread, how far the exercise has run, and the individual
 * report for one person.
 *
 * Nothing here is computed in the browser. Each view asks the API for a report
 * over a chosen cycle and lays out what comes back, so no screen can show a
 * readiness the finalised assessments do not support. Only finalised work is
 * counted for gaps, the same rule the outcome headline follows.
 */
@Component({
  selector: 'app-competency-reports',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, ReportViewerComponent, CycleSelectComponent, TranslatePipe],
  templateUrl: './competency-reports.component.html',
  styleUrl: './competency-reports.component.scss',
})
export class CompetencyReportsComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  readonly tabs: ReportTab[] = [
    { key: 'readiness', label: 'competency.reports.tab_readiness', icon: '@tui.gauge', blurb: 'competency.reports.tab_readiness_blurb' },
    { key: 'gaps', label: 'competency.reports.tab_gaps', icon: '@tui.trending-down', blurb: 'competency.reports.tab_gaps_blurb' },
    { key: 'critical', label: 'competency.reports.tab_critical', icon: '@tui.triangle-alert', blurb: 'competency.reports.tab_critical_blurb' },
    { key: 'distribution', label: 'competency.reports.tab_distribution', icon: '@tui.chart-column', blurb: 'competency.reports.tab_distribution_blurb' },
    { key: 'completion', label: 'competency.reports.tab_completion', icon: '@tui.clipboard-check', blurb: 'competency.reports.tab_completion_blurb' },
    { key: 'individual', label: 'competency.reports.tab_individual', icon: '@tui.file-text', blurb: 'competency.reports.tab_individual_blurb' },
  ];

  /** The org and competency axes readiness can break down by. */
  readonly dimensions: Array<{ value: ReadinessDimension; label: string }> = [
    { value: 'department', label: 'competency.reports.dim_department' },
    { value: 'branch', label: 'competency.reports.dim_branch' },
    { value: 'team', label: 'competency.reports.dim_team' },
    { value: 'level', label: 'competency.reports.dim_level' },
    { value: 'job_title', label: 'competency.reports.dim_job_title' },
    { value: 'function', label: 'competency.reports.dim_function' },
  ];

  readonly active = signal<ReportKey>('readiness');
  readonly loading = signal(false);
  readonly cycle = signal('');
  readonly cycles = signal<string[]>([]);
  readonly dimension = signal<ReadinessDimension>('department');

  readonly readiness = signal<ReadinessReport | null>(null);
  readonly gaps = signal<GapAnalysisReport | null>(null);
  readonly critical = signal<CriticalGapsReport | null>(null);
  readonly distribution = signal<DistributionReport | null>(null);
  readonly completion = signal<CompletionReport | null>(null);

  ngOnInit(): void {
    this.fetch('readiness');
  }

  select(key: ReportKey): void {
    this.active.set(key);
    if (key !== 'individual') {
      this.fetch(key);
    }
  }

  onCycleChange(cycle: string): void {
    this.cycle.set(cycle);
    // A cycle change invalidates every view; clear the caches so a later tab
    // does not show the previous cycle's figures.
    this.readiness.set(null);
    this.gaps.set(null);
    this.critical.set(null);
    this.distribution.set(null);
    this.completion.set(null);
    const view = this.active();
    if (view !== 'individual') {
      this.fetch(view);
    }
  }

  onDimensionChange(dimension: ReadinessDimension): void {
    this.dimension.set(dimension);
    this.fetch('readiness');
  }

  /** Loads one report over the chosen cycle, absorbing the cycle list it returns. */
  private fetch(key: Exclude<ReportKey, 'individual'>): void {
    const cycle = this.cycle() || undefined;
    this.loading.set(true);

    const absorb = (resolvedCycle: string, cycles: string[]): void => {
      if (!this.cycle()) {
        this.cycle.set(resolvedCycle);
      }
      if (cycles.length) {
        this.cycles.set(cycles);
      }
      this.loading.set(false);
    };
    const fail = (): void => {
      this.toast.error(this.i18n.t('competency.reports.load_error'));
      this.loading.set(false);
    };

    switch (key) {
      case 'readiness':
        this.competency.getReadinessReport(this.dimension(), cycle).subscribe({
          next: (r) => { this.readiness.set(r); absorb(r.cycle, r.cycles); },
          error: fail,
        });
        break;
      case 'gaps':
        this.competency.getGapAnalysis(cycle).subscribe({
          next: (r) => { this.gaps.set(r); absorb(r.cycle, r.cycles); },
          error: fail,
        });
        break;
      case 'critical':
        this.competency.getCriticalGaps(cycle).subscribe({
          next: (r) => { this.critical.set(r); absorb(r.cycle, r.cycles); },
          error: fail,
        });
        break;
      case 'distribution':
        this.competency.getDistribution(cycle).subscribe({
          next: (r) => { this.distribution.set(r); absorb(r.cycle, r.cycles); },
          error: fail,
        });
        break;
      case 'completion':
        this.competency.getCompletion(cycle).subscribe({
          next: (r) => { this.completion.set(r); absorb(r.cycle, r.cycles); },
          error: fail,
        });
        break;
    }
  }

  print(): void {
    window.print();
  }

  // --- formatting ---------------------------------------------------------

  readinessClass(readiness: number | null): string {
    if (readiness === null) {
      return 'text-muted';
    }
    if (readiness >= 80) {
      return 'text-success';
    }
    if (readiness >= 50) {
      return 'text-warning';
    }
    return 'text-danger';
  }

  /** A bar's colour by the same thresholds, for the readiness meters. */
  readinessBar(readiness: number | null): string {
    if (readiness === null) {
      return 'bg-secondary';
    }
    if (readiness >= 80) {
      return 'bg-success';
    }
    if (readiness >= 50) {
      return 'bg-warning';
    }
    return 'bg-danger';
  }

  /** Whole years and residual months, so "15" reads as "1 yr 3 mo". */
  monthsLabel(months: number): string {
    if (months <= 0) {
      return this.i18n.t('competency.reports.months_none');
    }
    const years = Math.floor(months / 12);
    const rest = months % 12;
    const parts: string[] = [];
    if (years) {
      parts.push(`${years} ${this.i18n.t('competency.common.yr')}`);
    }
    if (rest) {
      parts.push(`${rest} ${this.i18n.t('competency.common.mo')}`);
    }
    return parts.join(' ');
  }

  /** A part of a whole as a percentage, guarding a zero total. */
  pct(part: number, total: number): number {
    return total > 0 ? (part / total) * 100 : 0;
  }

  dimensionLabel(value: ReadinessDimension): string {
    const key = this.dimensions.find((d) => d.value === value)?.label;
    return key ? this.i18n.t(key) : value;
  }

  /** The proficiency ladder, for the level column headers. */
  private readonly levelNameKeys = [
    'competency.reports.level_not_demonstrated',
    'self_assessment.level_knowledge',
    'self_assessment.level_skill',
    'self_assessment.level_mastery',
  ];

  /** The name of a demonstrated/required level, translated. */
  levelLabel(level: number): string {
    const key = this.levelNameKeys[level];
    return key ? this.i18n.t(key) : '';
  }

  /** The severity label for a critical-gap row, translated from its English label. */
  severityLabel(gapLabel: string): string {
    const key: Record<string, string> = {
      Minor: 'competency.gap.minor',
      Major: 'competency.gap.major',
      Significant: 'competency.gap.significant',
    };
    return key[gapLabel] ? this.i18n.t(key[gapLabel]) : gapLabel;
  }
}
