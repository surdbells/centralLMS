import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TranslatePipe } from '../translate.pipe';
import { StarRatingComponent } from '../shared/star-rating/star-rating.component';
import { BehaviourFeedbackService, type TeamFeedbackRow } from '../services/behaviour-feedback.service';

interface ReportGroup {
  userId: string;
  name: string;
  rows: TeamFeedbackRow[];
}

/**
 * A line manager's behaviour-change feedback (Kirkpatrick L3): their direct
 * reports' completed courses, each rated 1–5 for how much the training changed
 * the person's work, with an optional note. Feeds the admin Analytics "business
 * impact" figure.
 */
@Component({
  selector: 'app-team-feedback',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe, StarRatingComponent],
  templateUrl: './team-feedback.component.html',
  styleUrls: ['./team-feedback.component.scss'],
})
export class TeamFeedbackComponent implements OnInit {
  private readonly svc = inject(BehaviourFeedbackService);

  loading = true;
  failed = false;
  reports: ReportGroup[] = [];
  saved: string | null = null;

  ngOnInit(): void {
    this.load();
  }

  private load(): void {
    this.loading = true;
    this.failed = false;

    this.svc.pending().subscribe({
      next: (rows) => {
        this.reports = this.group(rows);
        this.loading = false;
      },
      error: () => {
        this.failed = true;
        this.loading = false;
      },
    });
  }

  private group(rows: TeamFeedbackRow[]): ReportGroup[] {
    const byUser = new Map<string, ReportGroup>();

    for (const row of rows) {
      let group = byUser.get(row.user_id);
      if (!group) {
        group = { userId: row.user_id, name: row.name, rows: [] };
        byUser.set(row.user_id, group);
      }
      group.rows.push(row);
    }

    return [...byUser.values()];
  }

  key(row: TeamFeedbackRow): string {
    return row.user_id + row.course_id;
  }

  rate(row: TeamFeedbackRow, rating: number): void {
    row.rating = rating;
    this.persist(row);
  }

  saveComment(row: TeamFeedbackRow): void {
    if (row.rating) {
      this.persist(row);
    }
  }

  private persist(row: TeamFeedbackRow): void {
    if (!row.rating) {
      return;
    }
    this.svc.submit(row.user_id, row.course_id, row.rating, row.comments).subscribe({
      next: () => {
        const k = this.key(row);
        this.saved = k;
        setTimeout(() => {
          if (this.saved === k) {
            this.saved = null;
          }
        }, 1500);
      },
      error: () => undefined,
    });
  }
}
