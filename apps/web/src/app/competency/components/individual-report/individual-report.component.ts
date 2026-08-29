import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { BrandingService } from '../../../core/branding.service';
import {
  CompetencyService,
  type CompetencyReport,
  type AssessmentItem,
} from '../../competency.service';

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
  imports: [CommonModule],
  templateUrl: './individual-report.component.html',
  styleUrl: './individual-report.component.scss',
})
export class IndividualReportComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(HotToastService);
  readonly branding = inject(BrandingService);

  loading = true;
  report: CompetencyReport | null = null;

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (!id) {
      this.loading = false;
      return;
    }
    this.competency.getReport(id).subscribe({
      next: (report) => {
        this.report = report;
        this.loading = false;
      },
      error: () => {
        this.toast.error('The report could not be loaded.');
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
    return item.gap === 0 ? 'Prepared' : `${item.gapLabel} Gap`;
  }

  /** Whether a decision reads as a positive placement, for the green cell. */
  isPlaced(decision: string | null | undefined): boolean {
    return (decision ?? '').trim().toLowerCase() === 'placed';
  }

  /** Months rendered the way the exercise writes it: "1 years / 0 months". */
  timeToCompetency(months: number): string {
    const years = Math.floor(months / 12);
    const rem = months % 12;
    return `${years} year${years === 1 ? '' : 's'} / ${rem} month${rem === 1 ? '' : 's'}`;
  }

  print(): void {
    window.print();
  }
}
