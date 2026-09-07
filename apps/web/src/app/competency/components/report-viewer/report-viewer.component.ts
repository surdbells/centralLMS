import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { IndividualReportComponent } from '../individual-report/individual-report.component';
import { CycleSelectComponent } from '../cycle-select/cycle-select.component';
import { CompetencyService, type CompetencyAssessment } from '../../competency.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

/** One person the viewer can pull a report for. */
interface ReportSubjectRow {
  assessmentId: string;
  userName: string;
  jobTitle: string;
  functionName: string;
  cycle: string;
  status: string;
}

/**
 * Pick an employee, read their competency report.
 *
 * The individual report is addressed by assessment, but the person producing a
 * report thinks in people: choose the employee, the report loads. This wraps
 * the same report component in a searchable picker over everyone who has an
 * assessment, and hands it whichever assessment the chosen person's belongs to.
 */
@Component({
  selector: 'app-report-viewer',
  standalone: true,
  imports: [CommonModule, FormsModule, IndividualReportComponent, CycleSelectComponent, TranslatePipe],
  templateUrl: './report-viewer.component.html',
  styleUrl: './report-viewer.component.scss',
})
export class ReportViewerComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  loading = true;
  subjects: ReportSubjectRow[] = [];

  search = '';
  open = false;
  selected: ReportSubjectRow | null = null;

  /** The cycle the picker is scoped to; '' means every cycle. */
  cycle = '';

  ngOnInit(): void {
    this.loadSubjects();
  }

  /** A cycle change re-scopes the picker and drops whoever was chosen. */
  onCycleChange(cycle: string): void {
    this.cycle = cycle;
    this.selected = null;
    this.search = '';
    this.loadSubjects();
  }

  private loadSubjects(): void {
    this.loading = true;
    // The cycle this request is for. A quick second switch can leave two
    // requests in flight; ignore whichever is no longer the chosen cycle so a
    // slow earlier response cannot overwrite a faster later one.
    const requested = this.cycle;
    // Only a finalized assessment yields a report — the report endpoint refuses
    // anything still in draft/self-assessed/reviewed — so the picker offers only
    // those, narrowed to the chosen cycle. Filtered server-side so paging counts
    // finalized assessments in the cycle alone.
    this.competency
      .getAssessments({ status: 'finalized', cycle: requested || undefined, perPage: 200 })
      .subscribe({
        next: (paged) => {
          if (requested !== this.cycle) {
            return;
          }
          this.subjects = paged.items
            .map((a: CompetencyAssessment) => ({
              assessmentId: a.id,
              userName: a.userName ?? 'Unknown',
              jobTitle: a.jobTitle ?? '',
              functionName: a.functionName ?? '',
              cycle: a.cycle,
              status: a.statusLabel,
            }))
            .sort((x, y) => x.userName.localeCompare(y.userName));
          this.loading = false;
        },
        error: () => {
          if (requested !== this.cycle) {
            return;
          }
          this.toast.error(this.i18n.t('competency.viewer.load_error'));
          this.loading = false;
        },
      });
  }

  get filtered(): ReportSubjectRow[] {
    const q = this.search.trim().toLowerCase();
    if (!q) {
      return this.subjects;
    }
    return this.subjects.filter(
      (s) =>
        s.userName.toLowerCase().includes(q) ||
        s.jobTitle.toLowerCase().includes(q) ||
        s.functionName.toLowerCase().includes(q),
    );
  }

  openMenu(): void {
    this.open = true;
  }

  closeMenu(): void {
    // A tick behind the click, so a selection registers before the list closes.
    setTimeout(() => (this.open = false), 150);
  }

  choose(subject: ReportSubjectRow): void {
    this.selected = subject;
    this.search = subject.userName;
    this.open = false;
  }

  print(): void {
    window.print();
  }
}
