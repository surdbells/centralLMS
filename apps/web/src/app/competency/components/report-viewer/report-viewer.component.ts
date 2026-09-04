import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { IndividualReportComponent } from '../individual-report/individual-report.component';
import { CompetencyService, type CompetencyAssessment } from '../../competency.service';

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
  imports: [CommonModule, FormsModule, IndividualReportComponent],
  templateUrl: './report-viewer.component.html',
  styleUrl: './report-viewer.component.scss',
})
export class ReportViewerComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);

  loading = true;
  subjects: ReportSubjectRow[] = [];

  search = '';
  open = false;
  selected: ReportSubjectRow | null = null;

  ngOnInit(): void {
    // Only a finalized assessment yields a report — the report endpoint refuses
    // anything still in draft/self-assessed/reviewed — so the picker offers only
    // those. Filtered server-side so paging counts finalized assessments alone.
    this.competency.getAssessments({ status: 'finalized', perPage: 200 }).subscribe({
      next: (paged) => {
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
        this.toast.error('The list of people could not be loaded.');
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
