import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { CompetencyService, type OutcomeReport } from '../../competency.service';

/**
 * The outcome the whole exercise is for: how ready the workforce is, where the
 * gaps sit, and what it would take to close them — read across every finalised
 * assessment in a cycle. Nothing here is a claim: an unconfirmed gap does not
 * reach this page.
 */
@Component({
  selector: 'app-outcome-report',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon],
  templateUrl: './outcome-report.component.html',
  styleUrl: './outcome-report.component.scss',
})
export class OutcomeReportComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);

  loading = true;
  report: OutcomeReport | null = null;
  cycle = '';

  ngOnInit(): void {
    this.load();
  }

  load(cycle?: string): void {
    this.loading = true;
    this.competency.getOutcome(cycle).subscribe({
      next: (report) => {
        this.report = report;
        this.cycle = report.cycle;
        this.loading = false;
      },
      error: () => {
        this.toast.error('The outcome report could not be loaded.');
        this.loading = false;
      },
    });
  }

  onCycleChange(cycle: string): void {
    this.load(cycle);
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
}
