import { Component, OnInit, OnDestroy, signal, computed } from '@angular/core';
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
  SKILL_LEVELS,
  ITEM_TYPES,
  PROGRESS_STATUSES,
  ItemProgressStatus
} from '../../interfaces/learning-path.interfaces';
import {TopComponent} from '../../partials/top/top.component';

@Component({
  selector: 'app-learning-path-viewer',
  standalone: true,
  imports: [CommonModule, RouterModule, TopComponent],
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

  // UI State
  loading = signal(true);
  enrolling = signal(false);
  startingItem = signal<number | null>(null);
  activeSection = signal<number | null>(null);
  showAllObjectives = signal(false);

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

  loadPath(pathIdOrSlug: string | number): void {
    this.loading.set(true);

    const isNumeric = !isNaN(Number(pathIdOrSlug));
    const observable = isNumeric
      ? this.learningPathService.getById(Number(pathIdOrSlug))
      : this.learningPathService.getBySlug(String(pathIdOrSlug));

    observable.pipe(takeUntil(this.destroy$)).subscribe({
      next: (path) => {
        this.path.set(path);
        this.loadEnrollment(path.path_id);
        this.loadRatingSummary(path.path_id);
      },
      error: (error) => {
        console.error('Error loading path:', error);
        this.loading.set(false);
      }
    });
  }

  loadEnrollment(pathId: number): void {
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

  loadProgress(pathId: number): void {
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

  loadRatingSummary(pathId: number): void {
    this.learningPathService.getRatingSummary(pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (summary) => this.ratingSummary.set(summary)
    });
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
        alert(error.error?.error || 'Failed to enroll');
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
          alert('Congratulations! You have completed this learning path!');
        }
      }
    });
  }

  withdraw(): void {
    const p = this.path();
    if (!p) return;

    if (confirm('Are you sure you want to withdraw from this learning path? Your progress will be saved.')) {
      this.learningPathService.withdraw(p.path_id).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.enrollment.set(null);
          this.progress.set(null);
        }
      });
    }
  }

  // Section accordion
  toggleSection(sectionId: number): void {
    if (this.activeSection() === sectionId) {
      this.activeSection.set(null);
    } else {
      this.activeSection.set(sectionId);
    }
  }

  isSectionExpanded(sectionId: number): boolean {
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
    if (hours < 1) return `${Math.round(hours * 60)} min`;
    if (hours === 1) return '1 hour';
    return `${hours} hours`;
  }

  formatMinutes(minutes: number | null): string {
    if (!minutes) return '';
    if (minutes < 60) return `${minutes} min`;
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
