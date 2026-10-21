import { Component, OnInit, OnDestroy, signal, computed, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathEnrollment,
  ProgressBySection,
  LearningPathItemProgress,
  RatingSummary,
  LearningPathReview,
  SKILL_LEVELS,
  ITEM_TYPES,
  PROGRESS_STATUSES,
  ItemProgressStatus
} from '../../interfaces/learning-path.interfaces';
import { FormsModule } from '@angular/forms';
import {TopComponent} from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';

@Component({
  selector: 'app-learning-path-viewer',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, TopComponent, TranslatePipe],
  templateUrl: './learning-path-viewer.component.html',
  styleUrls: ['./learning-path-viewer.component.scss']
})
export class LearningPathViewerComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Data
  path = signal<LearningPath | null>(null);
  enrollment = signal<LearningPathEnrollment | null>(null);
  progress = signal<ProgressBySection | null>(null);
  ratingSummary = signal<RatingSummary | null>(null);
  reviews = signal<LearningPathReview[]>([]);
  myReview = signal<LearningPathReview | null>(null);

  // UI State
  loading = signal(true);
  enrolling = signal(false);
  startingItem = signal<string | null>(null);
  activeSection = signal<string | null>(null);
  showAllObjectives = signal(false);

  // Reviews
  showReviewForm = signal(false);
  savingReview = signal(false);
  reviewSubmitted = signal(false);
  reviewError = signal<string | null>(null);
  reviewRating = signal(0);
  reviewTitle = signal('');
  reviewText = signal('');

  // Computed
  isEnrolled = computed(() => !!this.enrollment());
  isCompleted = computed(() => this.enrollment()?.status === 'completed');
  canEnroll = computed(() => {
    const p = this.path();
    if (!p || p.status !== 'published') return false;
    if (this.isEnrolled()) return false;
    return true;
  });

  currentItem = computed(() => {
    const prog = this.progress();
    if (!prog) return null;

    // Find first available or in-progress item
    for (const section of prog.sections) {
      for (const item of section.items) {
        if (item.status === 'available' || item.status === 'in_progress') {
          return item;
        }
      }
    }
    for (const item of prog.unsectioned_items) {
      if (item.status === 'available' || item.status === 'in_progress') {
        return item;
      }
    }
    return null;
  });

  completedCount = computed(() => {
    const prog = this.progress();
    if (!prog) return 0;
    let count = 0;
    prog.sections.forEach(s => {
      count += s.items.filter(i => i.status === 'completed').length;
    });
    count += prog.unsectioned_items.filter(i => i.status === 'completed').length;
    return count;
  });

  totalCount = computed(() => this.path()?.total_items || 0);

  // Reference data
  skillLevels = SKILL_LEVELS;
  itemTypes = ITEM_TYPES;
  progressStatuses = PROGRESS_STATUSES;

  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);
  private readonly toast = inject(HotToastService);

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private learningPathService: LearningPathService
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      const pathId = params['id'] || params['slug'];
      if (pathId) {
        this.loadPath(pathId);
      }
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  /**
   * A path by whichever of its two names is in the address.
   *
   * It used to tell them apart by asking whether the segment was numeric,
   * which was true of every id when ids were integers and is true of none
   * now that they are UUIDs — so every link went down the slug branch. A
   * UUID has a shape, and that is what this looks for.
   */
  loadPath(pathIdOrSlug: string | number): void {
    this.loading.set(true);

    const value = String(pathIdOrSlug);
    const isId = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
    const observable = isId
      ? this.learningPathService.getById(value)
      : this.learningPathService.getBySlug(value);

    observable.pipe(takeUntil(this.destroy$)).subscribe({
      next: (path) => {
        this.path.set(path);
        this.loadEnrollment(path.path_id);
        this.loadRatingSummary(path.path_id);
        this.loadReviews(path.path_id);
      },
      error: (error) => {
        console.error('Error loading path:', error);
        this.loading.set(false);
      }
    });
  }

  loadEnrollment(pathId: string): void {
    this.learningPathService.getMyEnrollment(pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (enrollment) => {
        this.enrollment.set(enrollment);
        if (enrollment) {
          this.loadProgress(pathId);
        }
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
      }
    });
  }

  loadProgress(pathId: string): void {
    this.learningPathService.getMyProgress(pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (progress) => {
        this.progress.set(progress);
        // Expand first incomplete section
        if (progress.sections.length > 0) {
          const firstIncomplete = progress.sections.find(s => s.section.status !== 'completed');
          if (firstIncomplete) {
            this.activeSection.set(firstIncomplete.section.section_id);
          }
        }
      }
    });
  }

  loadRatingSummary(pathId: string): void {
    this.learningPathService.getRatingSummary(pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (summary) => this.ratingSummary.set(summary)
    });
  }

  loadReviews(pathId: string): void {
    this.learningPathService.getReviews(pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (response) => this.reviews.set(response.data)
    });

    // Fails softly: somebody who is not on the path has no review of their
    // own, and that is not an error worth showing on a page they can read.
    this.learningPathService.getMyReview(pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (review) => this.myReview.set(review),
      error: () => this.myReview.set(null)
    });
  }

  // Reviews

  /**
   * Opens the review form, filled in with whatever this learner said before.
   *
   * One enrolment leaves one review, so writing a second one edits the first
   * rather than adding to it.
   */
  openReviewForm(): void {
    const mine = this.myReview();

    this.reviewRating.set(mine?.rating ?? 0);
    this.reviewTitle.set(mine?.title ?? '');
    this.reviewText.set(mine?.review_text ?? '');
    this.reviewError.set(null);
    this.showReviewForm.set(true);
  }

  closeReviewForm(): void {
    this.showReviewForm.set(false);
  }

  submitReview(): void {
    const p = this.path();
    if (!p || this.reviewRating() < 1) {
      this.reviewError.set(this.i18n.t('paths.choose_rating'));
      return;
    }

    this.savingReview.set(true);
    this.reviewError.set(null);

    this.learningPathService
      .createReview({
        path_id: p.path_id,
        rating: this.reviewRating(),
        title: this.reviewTitle().trim() || undefined,
        review_text: this.reviewText().trim() || undefined,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.savingReview.set(false);
          this.showReviewForm.set(false);
          // A new review waits to be checked, so it will not appear on the
          // page yet — the banner below says so rather than leaving the
          // learner wondering where it went.
          this.reviewSubmitted.set(true);
          this.loadReviews(p.path_id);
          this.loadRatingSummary(p.path_id);
        },
        error: (error) => {
          this.savingReview.set(false);
          this.reviewError.set(error?.message || this.i18n.t('paths.review_save_error'));
        }
      });
  }

  markHelpful(review: LearningPathReview): void {
    this.learningPathService.markReviewHelpful(review.review_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadReviews(review.path_id) });
  }

  async reportReview(review: LearningPathReview): Promise<void> {
    const reason = (await this.dialog.prompt(this.i18n.t('paths.report_prompt')))?.trim();

    if (!reason) {
      return;
    }

    this.learningPathService.reportReview(review.review_id, reason)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadReviews(review.path_id) });
  }

  // Actions
  enroll(): void {
    const p = this.path();
    if (!p) return;

    this.enrolling.set(true);
    this.learningPathService.enroll(p.path_id).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.loadEnrollment(p.path_id);
        this.enrolling.set(false);
      },
      error: (error) => {
        console.error('Enrollment failed:', error);
        this.toast.error(error.error?.error || this.i18n.t('paths.enroll_failed'));
        this.enrolling.set(false);
      }
    });
  }

  startPath(): void {
    const p = this.path();
    if (!p) return;

    this.learningPathService.startPath(p.path_id).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.loadEnrollment(p.path_id);
        // Navigate to first item
        const first = this.currentItem();
        if (first) {
          this.navigateToItem(first);
        }
      }
    });
  }

  continuePath(): void {
    const current = this.currentItem();
    if (current) {
      this.navigateToItem(current);
    }
  }

  startItem(item: LearningPathItemProgress): void {
    if (item.status === 'locked') return;

    const p = this.path();
    if (!p) return;

    this.startingItem.set(item.item_id);

    this.learningPathService.startItem(p.path_id, item.item_id).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.navigateToItem(item);
        this.startingItem.set(null);
      },
      error: () => {
        this.startingItem.set(null);
      }
    });
  }

  navigateToItem(item: LearningPathItemProgress): void {
    const p = this.path();
    if (!p) return;

    // Navigate based on item type
    switch (item.item_type) {
      case 'course':
        this.router.navigate(['/learner/courses', item.course_id], {
          queryParams: { learning_path: p.path_id, item: item.item_id }
        });
        break;
      case 'assessment':
        this.router.navigate(['/learner/assessments', item.assessment_id], {
          queryParams: { learning_path: p.path_id, item: item.item_id }
        });
        break;
      case 'external_link':
        // Open in new tab and mark as viewed
        window.open(item.external_url, '_blank');
        this.completeItem(item);
        break;
      default:
        // For videos, documents, etc.
        this.router.navigate(['/learner/paths', p.path_id, 'items', item.item_id]);
    }
  }

  completeItem(item: LearningPathItemProgress): void {
    const p = this.path();
    if (!p) return;

    this.learningPathService.completeItem(p.path_id, item.item_id).pipe(takeUntil(this.destroy$)).subscribe({
      next: (result) => {
        this.loadProgress(p.path_id);
        if (result.path_completed) {
          // Show completion modal or redirect
          this.toast.success(this.i18n.t('paths.path_completed_alert'));
        }
      }
    });
  }

  async withdraw(): Promise<void> {
    const p = this.path();
    if (!p) return;

    if (await this.dialog.confirm(this.i18n.t('paths.withdraw_confirm'), { type: 'danger' })) {
      this.learningPathService.withdraw(p.path_id).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.enrollment.set(null);
          this.progress.set(null);
        }
      });
    }
  }

  // Section accordion
  toggleSection(sectionId: string): void {
    if (this.activeSection() === sectionId) {
      this.activeSection.set(null);
    } else {
      this.activeSection.set(sectionId);
    }
  }

  isSectionExpanded(sectionId: string): boolean {
    return this.activeSection() === sectionId;
  }

  // Helpers
  getSkillLevel(level: string): { label: string; color: string } {
    return this.skillLevels.find(s => s.value === level) || { label: level, color: 'secondary' };
  }

  getItemType(type: string): { label: string; icon: string } {
    return this.itemTypes.find(t => t.value === type) || { label: type, icon: 'bi-file' };
  }

  getProgressStatus(status: ItemProgressStatus): { label: string; color: string; icon: string } {
    return this.progressStatuses.find(s => s.value === status) || { label: status, color: 'secondary', icon: 'bi-circle' };
  }

  formatDuration(hours: number | null): string {
    if (!hours) return '';
    if (hours < 1) return this.i18n.t('paths.minutes', { count: Math.round(hours * 60) });
    if (hours === 1) return this.i18n.t('paths.one_hour');
    return this.i18n.t('paths.hours', { count: hours });
  }

  formatMinutes(minutes: number | null): string {
    if (!minutes) return '';
    if (minutes < 60) return this.i18n.t('paths.minutes', { count: minutes });
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }

  getStarArray(rating: number): number[] {
    const stars: number[] = [];
    for (let i = 1; i <= 5; i++) {
      if (i <= Math.floor(rating)) {
        stars.push(1); // Full star
      } else if (i - rating < 1 && i - rating > 0) {
        stars.push(0.5); // Half star
      } else {
        stars.push(0); // Empty star
      }
    }
    return stars;
  }
}
