import { Component, DestroyRef, Input, OnChanges, OnInit, SimpleChanges, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CommonModule, Location } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { BrandingService } from '../../../core/branding.service';
import {
  CompetencyService,
  type CompetencyReport,
  type AssessmentItem,
} from '../../competency.service';
import { CompetencyRadarComponent } from './competency-radar.component';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

/**
 * One person's competency report — the document the exercise produces for each
 * employee: every competency their job needs, the level they hold against it,
 * the gap, and the plan to close it.
 *
 * It reads an assessment and lays it out to print. What the assessment already
 * holds — required, self and assessed levels, the gap, the evidence — is shown
 * as it stands; the qualitative sections (the decision, the two gaps
 * paragraphs) are shown where an assessor has written them.
 */
@Component({
  selector: 'app-individual-report',
  standalone: true,
  imports: [CommonModule, CompetencyRadarComponent, TranslatePipe],
  templateUrl: './individual-report.component.html',
  styleUrl: './individual-report.component.scss',
})
export class IndividualReportComponent implements OnInit, OnChanges {
  private readonly competency = inject(CompetencyService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly location = inject(Location);
  private readonly toast = inject(HotToastService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(I18nService);
  readonly branding = inject(BrandingService);

  /** When embedded (e.g. the report viewer), the assessment to show is fed in. */
  @Input() assessmentId?: string;
  /** Hides the toolbar so the host can provide its own controls. */
  @Input() embedded = false;

  loading = true;
  report: CompetencyReport | null = null;

  // The radar's data, computed once when the report loads — not as getters,
  // which would hand the chart a fresh array reference on every change
  // detection and have it rebuild all its geometry each time.
  radarCategories: string[] = [];
  radarProficiency: number[] = [];
  radarIdeal: number[] = [];

  /** Back to wherever the report was opened from, or the assessments list. */
  back(): void {
    if (window.history.length > 1) {
      this.location.back();
    } else {
      void this.router.navigate(['/admin/competency/assessments']);
    }
  }

  ngOnInit(): void {
    if (this.embedded) {
      // Driven by the @Input; ngOnChanges loads it, so nothing to do until one
      // is chosen.
      if (!this.assessmentId) {
        this.loading = false;
      }
      return;
    }
    // Subscribe rather than read the snapshot once: Angular reuses this
    // component when only the :id changes (URL edit, back/forward between two
    // reports), and a snapshot read would leave the whole report — the radar
    // included — showing the previous assessment.
    this.route.paramMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => {
      const id = params.get('id');
      if (id) {
        this.load(id);
      } else {
        this.loading = false;
      }
    });
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['assessmentId'] && this.assessmentId) {
      this.load(this.assessmentId);
    }
  }

  private load(id: string): void {
    this.loading = true;
    this.report = null;
    this.radarCategories = [];
    this.radarProficiency = [];
    this.radarIdeal = [];
    this.competency.getReport(id).subscribe({
      next: (report) => {
        this.report = report;
        this.radarCategories = report.assessment.items.map((i) => i.competencyName);
        this.radarProficiency = report.assessment.items.map((i) => i.demonstratedLevel);
        this.radarIdeal = report.assessment.items.map((i) => i.requiredLevel);
        this.loading = false;
      },
      error: () => {
        this.toast.error(this.i18n.t('competency.report.load_error'));
        this.loading = false;
      },
    });
  }

  /** The proof-point text for the level the person is credited with. */
  proficiencyDescription(item: AssessmentItem): string {
    const d = this.report?.descriptors[item.competencyId];
    if (!d) {
      return '';
    }
    switch (item.demonstratedLevel) {
      case 3:
        return d.mastery ?? '';
      case 2:
        return d.skill ?? '';
      case 1:
        return d.knowledge ?? '';
      default:
        return '';
    }
  }

  /** "Prepared" when the gap is closed, otherwise the gap's own label. */
  readiness(item: AssessmentItem): string {
    if (item.gap === 0) {
      return this.i18n.t('competency.report.prepared');
    }
    const severity: Record<string, string> = {
      Minor: 'competency.gap.minor',
      Major: 'competency.gap.major',
      Significant: 'competency.gap.significant',
    };
    const label = severity[item.gapLabel] ? this.i18n.t(severity[item.gapLabel]) : item.gapLabel;
    return this.i18n.t('competency.report.gap_named', { label });
  }

  /** Whether a decision reads as a positive placement, for the green cell. */
  isPlaced(decision: string | null | undefined): boolean {
    return (decision ?? '').trim().toLowerCase() === 'placed';
  }

  /** Months rendered the way the exercise writes it: "1 years / 0 months". */
  timeToCompetency(months: number): string {
    const years = Math.floor(months / 12);
    const rem = months % 12;
    const yearWord = this.i18n.t(years === 1 ? 'competency.report.year' : 'competency.report.years');
    const monthWord = this.i18n.t(rem === 1 ? 'competency.report.month' : 'competency.report.months');
    return `${years} ${yearWord} / ${rem} ${monthWord}`;
  }

  print(): void {
    window.print();
  }
}
