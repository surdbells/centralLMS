import { Component, OnInit, OnDestroy, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import { LearningPathEnrollment, SKILL_LEVELS } from '../../interfaces/learning-path.interfaces';
import { TopComponent } from '../../partials/top/top.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';

@Component({
  selector: 'app-my-learning-paths',
  standalone: true,
  imports: [CommonModule, RouterModule, TopComponent, SkeletonComponent],
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page my-learning-paths">
      <!-- Header -->
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-signpost-split"></i></div>
        <div class="ui-page-header__titles">
          <h1>My Learning Paths</h1>
          <p>Track your progress across all assigned and enrolled learning paths</p>
        </div>
        <div class="ui-page-header__actions">
          <a routerLink="/learner/paths" class="btn btn-outline-secondary">
            <i class="bi bi-search me-2"></i>Browse Catalog
          </a>
        </div>
      </header>

      <!-- Tabs -->
      <nav class="ui-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          class="ui-tab"
          [class.is-active]="activeTab() === 'in-progress'"
          [attr.aria-selected]="activeTab() === 'in-progress'"
          (click)="setActiveTab('in-progress')"
        >
          <i class="bi bi-play-circle"></i> In Progress
          @if (inProgressCount() > 0) {
            <span class="ui-tab__count">{{ inProgressCount() }}</span>
          }
        </button>
        <button
          type="button"
          role="tab"
          class="ui-tab"
          [class.is-active]="activeTab() === 'completed'"
          [attr.aria-selected]="activeTab() === 'completed'"
          (click)="setActiveTab('completed')"
        >
          <i class="bi bi-check-circle"></i> Completed
          @if (completedCount() > 0) {
            <span class="ui-tab__count">{{ completedCount() }}</span>
          }
        </button>
        <button
          type="button"
          role="tab"
          class="ui-tab"
          [class.is-active]="activeTab() === 'all'"
          [attr.aria-selected]="activeTab() === 'all'"
          (click)="setActiveTab('all')"
        >
          <i class="bi bi-grid"></i> All
        </button>
      </nav>

      @if (loading()) {
        <app-skeleton variant="cards" [count]="6"></app-skeleton>
      } @else if (filteredEnrollments().length === 0) {
        <div class="ui-state empty-state">
          <span class="ui-state__icon"><i class="bi bi-signpost-split"></i></span>
          <h3>No Learning Paths</h3>
          <p>
            @if (activeTab() === 'in-progress') {
              You don't have any learning paths in progress.
            } @else if (activeTab() === 'completed') {
              You haven't completed any learning paths yet.
            } @else {
              You're not enrolled in any learning paths.
            }
          </p>
          <a routerLink="/learner/paths" class="btn btn-primary">
            <i class="bi bi-search me-2"></i>Browse Catalog
          </a>
        </div>
      } @else {
        <div class="ui-grid">
          @for (enrollment of filteredEnrollments(); track enrollment.enrollment_id) {
              <div class="ui-card path-card">
                <div class="path-thumbnail">
                  @if (enrollment.path_thumbnail) {
                    <img [src]="enrollment.path_thumbnail" [alt]="enrollment.path_title">
                  } @else {
                    <div class="thumbnail-placeholder">
                      <i class="bi bi-signpost-split"></i>
                    </div>
                  }
                  @if (enrollment.status === 'completed') {
                    <div class="completion-badge">
                      <i class="bi bi-check-circle-fill"></i> Completed
                    </div>
                  }
                  @if (enrollment.is_overdue) {
                    <div class="overdue-badge">
                      <i class="bi bi-exclamation-circle-fill"></i> Overdue
                    </div>
                  }
                </div>

                <div class="ui-card__body">
                  <div class="d-flex justify-content-between align-items-start">
                    <span class="badge bg-{{ getSkillBadgeColor(enrollment.skill_level) }}">
                      {{ getSkillLabel(enrollment.skill_level) }}
                    </span>
                    @if (enrollment.due_date && enrollment.status !== 'completed') {
                      <small class="ui-muted" [class.text-danger]="enrollment.is_overdue">
                        Due {{ enrollment.due_date | date:'MMM d' }}
                      </small>
                    }
                  </div>

                  <h5 class="ui-card__title card-title">
                    <a [routerLink]="['/learner/paths', enrollment.path_id]">{{ enrollment.path_title }}</a>
                  </h5>

                  <!-- Progress -->
                  <div class="progress-section">
                    <div class="d-flex justify-content-between mb-1">
                      <small class="ui-muted">{{ enrollment.items_completed }}/{{ enrollment.items_total }} items</small>
                    </div>
                    <div class="ui-progress">
                      <div class="ui-bar" [class.is-complete]="enrollment.status === 'completed'">
                        <span [style.width.%]="enrollment.progress_percentage"></span>
                      </div>
                      <span class="ui-pct">{{ enrollment.progress_percentage }}%</span>
                    </div>
                  </div>

                  <!-- Stats -->
                  <div class="path-stats">
                    <div class="stat">
                      <i class="bi bi-clock"></i>
                      <span>{{ formatMinutes(enrollment.total_time_spent_minutes) }}</span>
                    </div>
                    <div class="stat">
                      <i class="bi bi-trophy"></i>
                      <span>{{ enrollment.total_points_earned }} pts</span>
                    </div>
                  </div>
                </div>

                <div class="ui-card__footer">
                  @if (enrollment.status === 'completed') {
                    <div class="d-flex gap-2 w-100">
                      @if (enrollment.certificate_id) {
                        <a [routerLink]="['/learner/assessments/certificates', enrollment.certificate_id]" class="btn btn-success btn-sm flex-fill">
                          <i class="bi bi-award me-1"></i>Certificate
                        </a>
                      }
                      <a [routerLink]="['/learner/paths', enrollment.path_id]" class="btn btn-outline-secondary btn-sm flex-fill">
                        <i class="bi bi-eye me-1"></i>Review
                      </a>
                    </div>
                  } @else if (!enrollment.started_at) {
                    <a [routerLink]="['/learner/paths', enrollment.path_id]" class="btn btn-primary btn-sm w-100">
                      <i class="bi bi-play-fill me-1"></i>Start Learning
                    </a>
                  } @else {
                    <a [routerLink]="['/learner/paths', enrollment.path_id]" class="btn btn-primary btn-sm w-100">
                      <i class="bi bi-play-fill me-1"></i>Continue Learning
                    </a>
                  }
                </div>
              </div>
          }
        </div>
      }
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome (header, tabs, cards, states, progress) comes from the
       global UI kit in styles.scss; only the path-card specifics live here. */
    .path-card .path-thumbnail {
      position: relative;
      height: 140px;
      background: linear-gradient(135deg, rgba(var(--brand-primary-rgb), .16), rgba(var(--brand-primary-rgb), .04));
    }

    .path-card .path-thumbnail img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .path-card .thumbnail-placeholder {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .path-card .thumbnail-placeholder i {
      font-size: 2.25rem;
      color: rgba(var(--brand-primary-rgb), .55);
    }

    /* Overlay chips on the thumbnail */
    .path-card .completion-badge,
    .path-card .overdue-badge {
      position: absolute;
      top: 12px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 0 10px;
      height: 24px;
      border-radius: 999px;
      color: #fff;
      font-size: .75rem;
      font-weight: 600;
    }

    .path-card .completion-badge {
      right: 12px;
      background: var(--ui-success, #2e7d4f);
    }

    .path-card .overdue-badge {
      left: 12px;
      background: var(--ui-danger, #c0352b);
    }

    .path-card .card-title a {
      color: inherit;
      text-decoration: none;
    }

    .path-card .card-title a:hover {
      color: var(--brand-primary);
    }

    .path-card .ui-bar.is-complete > span {
      background: var(--ui-success, #2e7d4f);
    }

    .path-card .path-stats {
      display: flex;
      gap: 1rem;
      margin-top: auto;
      padding-top: 10px;
      border-top: 1px solid var(--ui-border);
    }

    .path-card .path-stats .stat {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: .75rem;
      color: var(--ui-text-muted);
    }
  `]
})
export class MyLearningPathsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  enrollments = signal<LearningPathEnrollment[]>([]);
  loading = signal(true);
  activeTab = signal<'in-progress' | 'completed' | 'all'>('in-progress');

  skillLevels = SKILL_LEVELS;

  // Computed
  filteredEnrollments = computed(() => {
    const all = this.enrollments();
    switch (this.activeTab()) {
      case 'in-progress':
        return all.filter(e => e.status === 'active' || e.status === 'pending');
      case 'completed':
        return all.filter(e => e.status === 'completed');
      default:
        return all;
    }
  });

  inProgressCount = computed(() =>
    this.enrollments().filter(e => e.status === 'active' || e.status === 'pending').length
  );

  completedCount = computed(() =>
    this.enrollments().filter(e => e.status === 'completed').length
  );

  constructor(private learningPathService: LearningPathService) {}

  ngOnInit(): void {
    this.loadEnrollments();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadEnrollments(): void {
    this.learningPathService.getMyEnrollments().pipe(takeUntil(this.destroy$)).subscribe({
      next: (enrollments) => {
        this.enrollments.set(enrollments);
        this.loading.set(false);
      },
      error: () => this.loading.set(false)
    });
  }

  setActiveTab(tab: 'in-progress' | 'completed' | 'all'): void {
    this.activeTab.set(tab);
  }

  getSkillLabel(level: string | undefined): string {
    if (!level) return 'All Levels';
    return this.skillLevels.find(s => s.value === level)?.label || level;
  }

  getSkillBadgeColor(level: string | undefined): string {
    if (!level) return 'secondary';
    return this.skillLevels.find(s => s.value === level)?.color || 'secondary';
  }

  formatMinutes(minutes: number | null): string {
    if (!minutes) return '0m';
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }
}
