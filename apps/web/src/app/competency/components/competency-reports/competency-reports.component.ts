import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { ReportViewerComponent } from '../report-viewer/report-viewer.component';
import { CycleSelectComponent } from '../cycle-select/cycle-select.component';
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
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, ReportViewerComponent, CycleSelectComponent],
  templateUrl: './competency-reports.component.html',
  styleUrl: './competency-reports.component.scss',
})
export class CompetencyReportsComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);

  readonly tabs: ReportTab[] = [
    { key: 'readiness', label: 'Readiness', icon: '@tui.gauge', blurb: 'How ready each group is' },
    { key: 'gaps', label: 'Gap Analysis', icon: '@tui.trending-down', blurb: 'The competencies furthest behind' },
    { key: 'critical', label: 'Critical Gaps', icon: '@tui.triangle-alert', blurb: 'People short on what matters most' },
    { key: 'distribution', label: 'Proficiency', icon: '@tui.chart-column', blurb: 'How levels are spread' },
    { key: 'completion', label: 'Completion', icon: '@tui.clipboard-check', blurb: 'How far the exercise has run' },
    { key: 'individual', label: 'Individual', icon: '@tui.file-text', blurb: 'One person’s full report' },
  ];

  /** The org and competency axes readiness can break down by. */
  readonly dimensions: Array<{ value: ReadinessDimension; label: string }> = [
    { value: 'department', label: 'Department' },
    { value: 'branch', label: 'Branch' },
    { value: 'team', label: 'Team' },
    { value: 'level', label: 'Level' },
    { value: 'job_title', label: 'Job title' },
    { value: 'function', label: 'Function' },
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
      this.toast.error('That report could not be loaded.');
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
      return 'None';
    }
    const years = Math.floor(months / 12);
    const rest = months % 12;
    const parts: string[] = [];
    if (years) {
      parts.push(`${years} yr`);
    }
    if (rest) {
      parts.push(`${rest} mo`);
    }
    return parts.join(' ');
  }

  /** A part of a whole as a percentage, guarding a zero total. */
  pct(part: number, total: number): number {
    return total > 0 ? (part / total) * 100 : 0;
  }

  dimensionLabel(value: ReadinessDimension): string {
    return this.dimensions.find((d) => d.value === value)?.label ?? value;
  }

  /** The proficiency ladder, for the level column headers. */
  readonly levelName = ['Not demonstrated', 'Knowledge', 'Skill', 'Mastery'];
}
