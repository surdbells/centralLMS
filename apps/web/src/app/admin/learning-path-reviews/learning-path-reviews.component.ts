import { Component, OnInit, OnDestroy, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathReview,
  RatingSummary
} from '../../interfaces/learning-path.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';

@Component({
  selector: 'app-learning-path-reviews',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, AdmintopComponent, TranslatePipe],
  templateUrl: `learning-path-reviews.component.html`,
  styleUrls: [`learning-path-reviews.component.scss`]
})
export class LearningPathReviewsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  pathId!: string;
  path = signal<LearningPath | null>(null);
  reviews = signal<LearningPathReview[]>([]);
  ratingSummary = signal<RatingSummary | null>(null);

  loading = signal(true);
  saving = signal(false);
  currentPage = signal(1);
  totalPages = signal(1);

  statusFilter = '';

  // Response modal
  showResponseModal = signal(false);
  selectedReview = signal<LearningPathReview | null>(null);
  responseText = '';

  constructor(
    private route: ActivatedRoute,
    private learningPathService: LearningPathService,
    private i18n: I18nService,
    private dialog: ConfirmDialogService
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.pathId = String(params['id']);
      this.loadPath();
      this.loadReviews();
      this.loadRatingSummary();
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadPath(): void {
    this.learningPathService.getById(this.pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (path) => this.path.set(path)
    });
  }

  loadReviews(): void {
    this.loading.set(true);
    this.learningPathService
      .getReviews(this.pathId, this.currentPage(), this.statusFilter)
      .pipe(takeUntil(this.destroy$)).subscribe({
      next: (response) => {
        this.reviews.set(response.data);
        this.totalPages.set(response.pagination.total_pages);
        this.loading.set(false);
      },
      error: () => this.loading.set(false)
    });
  }

  loadRatingSummary(): void {
    this.learningPathService.getRatingSummary(this.pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (summary) => this.ratingSummary.set(summary)
    });
  }

  /**
   * Narrows the list, from the first page.
   *
   * Filtering while on page three of the old list shows an empty one, which
   * reads as "no reviews in this state" rather than "you are past the end".
   */
  filterChanged(): void {
    this.currentPage.set(1);
    this.loadReviews();
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.totalPages()) {
      this.currentPage.set(page);
      this.loadReviews();
    }
  }

  getDistributionPercent(rating: number): number {
    const summary = this.ratingSummary();
    if (!summary || summary.total_reviews === 0) return 0;
    return ((summary.distribution[rating] || 0) / summary.total_reviews) * 100;
  }

  getStatusBadge(status: string): string {
    const badges: Record<string, string> = {
      pending: 'bg-warning text-dark',
      approved: 'bg-success',
      rejected: 'bg-danger',
      flagged: 'bg-dark'
    };
    return badges[status] || 'bg-secondary';
  }

  approveReview(review: LearningPathReview): void {
    this.learningPathService.approveReview(review.review_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadReviews() });
  }

  async rejectReview(review: LearningPathReview): Promise<void> {
    // Required, not optional: "rejected, no reason given" is the message the
    // learner who wrote it would receive.
    const reason = (await this.dialog.prompt(this.i18n.t('paths_admin.reviews_reject_prompt')))?.trim();

    if (!reason) {
      return;
    }

    this.learningPathService.rejectReview(review.review_id, reason)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadReviews() });
  }

  openResponseModal(review: LearningPathReview): void {
    this.selectedReview.set(review);
    this.responseText = '';
    this.showResponseModal.set(true);
  }

  closeResponseModal(): void {
    this.showResponseModal.set(false);
    this.selectedReview.set(null);
  }

  submitResponse(): void {
    const review = this.selectedReview();
    if (!review || !this.responseText) return;

    this.saving.set(true);
    this.learningPathService.respondToReview(review.review_id, this.responseText)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.closeResponseModal();
          this.loadReviews();
        },
        error: () => this.saving.set(false)
      });
  }
}
