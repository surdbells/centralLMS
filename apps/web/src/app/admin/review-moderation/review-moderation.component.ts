import { Component, OnInit, OnDestroy, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import { LearningPathReview } from '../../interfaces/learning-path.interfaces';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

/**
 * Every review waiting to be checked, across every path.
 *
 * The per-path screen answers "what has been said about this path"; this one
 * answers "what is waiting for me", which is the question a moderator actually
 * opens the system with. Worked oldest first, because a queue worked
 * newest-first leaves the first review anybody wrote at the bottom for ever.
 */
@Component({
  selector: 'app-review-moderation',
  standalone: true,
  imports: [CommonModule, RouterModule, AdmintopComponent],
  templateUrl: './review-moderation.component.html',
  styleUrls: ['./review-moderation.component.scss']
})
export class ReviewModerationComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  reviews = signal<LearningPathReview[]>([]);
  loading = signal(true);
  acting = signal<string | null>(null);
  currentPage = signal(1);
  totalPages = signal(1);
  total = signal(0);

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  constructor(private learningPathService: LearningPathService) {}

  load(): void {
    this.loading.set(true);
    this.learningPathService.getPendingReviews(this.currentPage())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.reviews.set(response.data);
          this.total.set(response.pagination.total);
          this.totalPages.set(response.pagination.total_pages);
          this.loading.set(false);
        },
        error: () => this.loading.set(false)
      });
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.totalPages()) {
      this.currentPage.set(page);
      this.load();
    }
  }

  approve(review: LearningPathReview): void {
    this.acting.set(review.review_id);
    this.learningPathService.approveReview(review.review_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.afterModerating(),
        error: () => this.acting.set(null)
      });
  }

  reject(review: LearningPathReview): void {
    // Required, not optional: "rejected, no reason given" is the message the
    // learner who wrote it would receive.
    const reason = prompt('Why is this review being rejected?')?.trim();

    if (!reason) {
      return;
    }

    this.acting.set(review.review_id);
    this.learningPathService.rejectReview(review.review_id, reason)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.afterModerating(),
        error: () => this.acting.set(null)
      });
  }

  /**
   * Reloads after a decision, stepping back a page if that emptied the last
   * one — otherwise clearing the queue leaves an empty page reading as
   * "nothing waiting" while earlier pages still have work on them.
   */
  private afterModerating(): void {
    this.acting.set(null);

    if (this.reviews().length === 1 && this.currentPage() > 1) {
      this.currentPage.set(this.currentPage() - 1);
    }

    this.load();
  }
}
