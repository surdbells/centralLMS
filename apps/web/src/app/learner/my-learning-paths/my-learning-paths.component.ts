import { Component, OnInit, OnDestroy, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import { LearningPathEnrollment, SKILL_LEVELS } from '../../interfaces/learning-path.interfaces';

@Component({
  selector: 'app-my-learning-paths',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="my-learning-paths">
      <!-- Header -->
      <div class="page-header mb-4">
        <h1 class="h3 mb-1">My Learning Paths</h1>
        <p class="text-muted mb-0">Track your progress across all assigned and enrolled learning paths</p>
      </div>

      <!-- Tabs -->
      <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
          <a
            class="nav-link"
            [class.active]="activeTab() === 'in-progress'"
            (click)="setActiveTab('in-progress')"
          >
            In Progress
            @if (inProgressCount() > 0) {
              <span class="badge bg-primary ms-2">{{ inProgressCount() }}</span>
            }
          </a>
        </li>
        <li class="nav-item">
          <a
            class="nav-link"
            [class.active]="activeTab() === 'completed'"
            (click)="setActiveTab('completed')"
          >
            Completed
            @if (completedCount() > 0) {
              <span class="badge bg-success ms-2">{{ completedCount() }}</span>
            }
          </a>
        </li>
        <li class="nav-item">
          <a
            class="nav-link"
            [class.active]="activeTab() === 'all'"
            (click)="setActiveTab('all')"
          >
            All
          </a>
        </li>
      </ul>

      @if (loading()) {
        <div class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
        </div>
      } @else if (filteredEnrollments().length === 0) {
        <div class="empty-state text-center py-5">
          <i class="bi bi-signpost-split display-3 text-muted"></i>
          <h4 class="mt-3">No Learning Paths</h4>
          <p class="text-muted">
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
        <div class="row g-4">
          @for (enrollment of filteredEnrollments(); track enrollment.enrollment_id) {
            <div class="col-md-6 col-lg-4">
              <div class="card path-card h-100">
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

                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge bg-{{ getSkillBadgeColor(enrollment.skill_level) }}">
                      {{ getSkillLabel(enrollment.skill_level) }}
                    </span>
                    @if (enrollment.due_date && enrollment.status !== 'completed') {
                      <small class="text-muted" [class.text-danger]="enrollment.is_overdue">
                        Due {{ enrollment.due_date | date:'MMM d' }}
                      </small>
                    }
                  </div>

                  <h5 class="card-title">
                    <a [routerLink]="['/learner/paths', enrollment.path_id]">{{ enrollment.path_title }}</a>
                  </h5>

                  <!-- Progress -->
                  <div class="progress-section mt-3">
                    <div class="d-flex justify-content-between mb-1">
                      <small class="text-muted">{{ enrollment.items_completed }}/{{ enrollment.items_total }} items</small>
                      <small class="fw-medium">{{ enrollment.progress_percentage }}%</small>
                    </div>
                    <div class="progress" style="height: 6px;">
                      <div
                        class="progress-bar"
                        [class.bg-success]="enrollment.status === 'completed'"
                        [style.width.%]="enrollment.progress_percentage"
                      ></div>
                    </div>
                  </div>

                  <!-- Stats -->
                  <div class="path-stats mt-3">
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

                <div class="card-footer bg-transparent">
                  @if (enrollment.status === 'completed') {
                    <div class="d-flex gap-2">
                      @if (enrollment.certificate_id) {
                        <a [routerLink]="['/certificates', enrollment.certificate_id]" class="btn btn-success btn-sm flex-fill">
                          <i class="bi bi-award me-1"></i>Certificate
                        </a>
                      }
                      <a [routerLink]="['/learner/paths', enrollment.path_id]" class="btn btn-secondary btn-sm flex-fill">
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
            </div>
          }
        </div>
      }
    </div>
  `,
  styles: [`
    .my-learning-paths {
      padding-bottom: 3rem;
    }

    .nav-tabs {
      border-bottom: 2px solid #e2e8f0;

      .nav-link {
        border: none;
        color: #64748b;
        font-weight: 500;
        padding: 0.75rem 1.25rem;
        cursor: pointer;

        &:hover {
          color: var(--bs-primary);
        }

        &.active {
          color: var(--bs-primary);
          border-bottom: 2px solid var(--bs-primary);
          margin-bottom: -2px;
        }
      }
    }

    .path-card {
      border: none;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      border-radius: 12px;
      overflow: hidden;
      transition: transform 0.2s, box-shadow 0.2s;

      &:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
      }

      .path-thumbnail {
        position: relative;
        height: 140px;
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);

        img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .thumbnail-placeholder {
          width: 100%;
          height: 100%;
          display: flex;
          align-items: center;
          justify-content: center;

          i {
            font-size: 2.5rem;
            color: #94a3b8;
          }
        }

        .completion-badge {
          position: absolute;
          top: 12px;
          right: 12px;
          background: #22c55e;
          color: white;
          padding: 0.25rem 0.75rem;
          border-radius: 20px;
          font-size: 0.75rem;
          font-weight: 600;
        }

        .overdue-badge {
          position: absolute;
          top: 12px;
          left: 12px;
          background: #ef4444;
          color: white;
          padding: 0.25rem 0.75rem;
          border-radius: 20px;
          font-size: 0.75rem;
          font-weight: 600;
        }
      }

      .card-title {
        font-size: 1rem;
        margin-bottom: 0;

        a {
          color: inherit;
          text-decoration: none;

          &:hover {
            color: var(--bs-primary);
          }
        }
      }

      .path-stats {
        display: flex;
        gap: 1rem;

        .stat {
          display: flex;
          align-items: center;
          gap: 0.375rem;
          font-size: 0.8125rem;
          color: #64748b;
        }
      }

      .card-footer {
        border-top: 1px solid #f1f5f9;
        padding: 0.75rem 1rem;
      }
    }

    .empty-state i {
      opacity: 0.3;
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
