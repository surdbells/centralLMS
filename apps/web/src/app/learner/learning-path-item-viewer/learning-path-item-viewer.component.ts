import { Component, OnInit, OnDestroy, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathItem,
  LearningPathItemProgress,
  ITEM_TYPES
} from '../../interfaces/learning-path.interfaces';
import { TopComponent } from '../../partials/top/top.component';
import { HotToastService } from '@ngneat/hot-toast';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';

@Component({
  selector: 'app-learning-path-item-viewer',
  standalone: true,
  imports: [CommonModule, RouterModule, TopComponent, SkeletonComponent],
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page item-viewer">
      @if (loading()) {
        <app-skeleton variant="detail" height="320px"></app-skeleton>
      } @else if (item()) {
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb mb-2 small">
            <li class="breadcrumb-item"><a routerLink="/learner/paths">Learning Paths</a></li>
            @if (path()) {
              <li class="breadcrumb-item"><a [routerLink]="['/learner/paths', path()!.path_id]">{{ path()!.title }}</a></li>
            }
            <li class="breadcrumb-item active">{{ item()!.display_title }}</li>
          </ol>
        </nav>

        <!-- Header -->
        <header class="ui-page-header item-header">
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi {{ getItemTypeIcon(item()!.item_type) }}"></i></div>
          <div class="ui-page-header__titles">
            <h1 class="item-title">{{ item()!.display_title }}</h1>
            <p class="item-type-badge">{{ getItemTypeLabel(item()!.item_type) }}</p>
          </div>
          <div class="ui-page-header__actions">
            @if (prevItem()) {
              <button class="btn btn-outline-secondary" (click)="navigateToPrev()">
                <i class="bi bi-chevron-left me-1"></i>Previous
              </button>
            }
            @if (nextItem()) {
              <button class="btn btn-primary" (click)="navigateToNext()">
                Next<i class="bi bi-chevron-right ms-1"></i>
              </button>
            } @else {
              <a [routerLink]="['/learner/paths', path()!.path_id]" class="btn btn-success">
                Back to Path<i class="bi bi-check-lg ms-1"></i>
              </a>
            }
          </div>
        </header>

        <!-- Content -->
        <div class="item-content">
          <div class="row g-4">
            <div class="col-lg-8">
              <!-- Content Player -->
              <div class="ui-section ui-section--flush content-player" [class.is-video]="item()!.item_type === 'video'">
                @switch (item()!.item_type) {
                  @case ('video') {
                    <video
                      controls
                      class="w-100"
                      [src]="item()!.external_url"
                      (ended)="onContentComplete()"
                    ></video>
                  }
                  @case ('document') {
                    <iframe
                      [src]="item()!.external_url"
                      class="document-viewer"
                      (load)="onContentLoaded()"
                    ></iframe>
                  }
                  @case ('external_link') {
                    <div class="external-link-card">
                      <span class="ui-state__icon"><i class="bi bi-box-arrow-up-right"></i></span>
                      <h4 class="mt-3">External Resource</h4>
                      <p class="ui-muted">{{ item()!.external_description }}</p>
                      <a [href]="item()!.external_url" target="_blank" class="btn btn-primary" (click)="onExternalLinkClick()">
                        Open Resource<i class="bi bi-box-arrow-up-right ms-2"></i>
                      </a>
                    </div>
                  }
                  @default {
                    <div class="generic-content">
                      <p>Content type: {{ item()!.item_type }}</p>
                    </div>
                  }
                }
              </div>

              <!-- Description -->
              @if (item()!.external_description) {
                <section class="ui-section">
                  <div class="ui-section__head">
                    <h2 class="ui-section__title">About this content</h2>
                  </div>
                  <p class="mb-0 item-description">{{ item()!.external_description }}</p>
                </section>
              }
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
              <!-- Progress Card -->
              <section class="ui-section progress-card">
                <div class="ui-section__head">
                  <h2 class="ui-section__title">Your Progress</h2>
                </div>

                @if (progress()?.status === 'completed') {
                  <div class="completion-badge mb-3">
                    <span class="ui-pill ui-pill--success"><i class="bi bi-check-circle-fill"></i> Completed</span>
                  </div>
                } @else {
                  <div class="ui-progress mb-3">
                    <div class="ui-bar">
                      <span [style.width.%]="progress()?.progress_percentage || 0"></span>
                    </div>
                  </div>
                }

                <div class="progress-stats">
                  @if (item()!.points_value) {
                    <div class="stat">
                      <i class="bi bi-trophy"></i>
                      <span>{{ item()!.points_value }} points</span>
                    </div>
                  }
                  @if (item()!.estimated_duration_minutes) {
                    <div class="stat">
                      <i class="bi bi-clock"></i>
                      <span>{{ item()!.estimated_duration_minutes }} min</span>
                    </div>
                  }
                  @if (progress()?.time_spent_minutes) {
                    <div class="stat">
                      <i class="bi bi-stopwatch"></i>
                      <span>{{ progress()!.time_spent_minutes }} min spent</span>
                    </div>
                  }
                </div>

                @if (progress()?.status !== 'completed') {
                  <button class="btn btn-success w-100 mt-3" (click)="markComplete()" [disabled]="completing()">
                    @if (completing()) {
                      <span class="spinner-border spinner-border-sm me-2"></span>
                    }
                    <i class="bi bi-check-lg me-2"></i>Mark as Complete
                  </button>
                }
              </section>

              <!-- Path Progress -->
              @if (path()) {
                <section class="ui-section">
                  <div class="ui-section__head">
                    <h2 class="ui-section__title">Path Progress</h2>
                  </div>
                  <div class="d-flex justify-content-between mb-2 small">
                    <span class="ui-muted">Overall</span>
                  </div>
                  <div class="ui-progress">
                    <div class="ui-bar"><span [style.width.%]="pathProgress()"></span></div>
                    <span class="ui-pct">{{ pathProgress() }}%</span>
                  </div>
                  <a [routerLink]="['/learner/paths', path()!.path_id]" class="btn btn-outline-secondary btn-sm w-100 mt-3">
                    View Full Path
                  </a>
                </section>
              }
            </div>
          </div>
        </div>
      }
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome (header, sections, pills, progress) comes from the global
       UI kit in styles.scss; only the player sizing lives here. */
    .item-type-badge {
      text-transform: none;
    }

    .content-player {
      min-height: 400px;
    }

    .content-player.is-video {
      background: #000;
    }

    .content-player video {
      display: block;
    }

    .content-player .document-viewer {
      display: block;
      width: 100%;
      height: 600px;
      border: none;
    }

    .content-player .external-link-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 400px;
      text-align: center;
      padding: 2rem;
    }

    .content-player .external-link-card h4 {
      font-size: 1.0625rem;
      font-weight: 650;
      color: var(--ui-text);
    }

    .content-player .external-link-card p {
      max-width: 420px;
      font-size: .875rem;
    }

    .content-player .generic-content {
      padding: 24px;
      font-size: .875rem;
      color: var(--ui-text-muted);
    }

    .item-description {
      font-size: .875rem;
      color: var(--ui-text-muted);
    }

    .progress-card .progress-stats {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .progress-card .progress-stats .stat {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.8125rem;
      color: var(--ui-text-muted);
    }
  `]
})
export class LearningPathItemViewerComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  pathId!: string;
  itemId!: string;

  path = signal<LearningPath | null>(null);
  item = signal<LearningPathItem | null>(null);
  progress = signal<LearningPathItemProgress | null>(null);

  prevItem = signal<LearningPathItem | null>(null);
  nextItem = signal<LearningPathItem | null>(null);
  pathProgress = signal(0);

  loading = signal(true);
  completing = signal(false);

  itemTypes = ITEM_TYPES;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private learningPathService: LearningPathService,
    private toast: HotToastService
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.pathId = String(params['id']);
      this.itemId = String(params['itemId']);
      this.loadData();
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadData(): void {
    this.loading.set(true);

    // Load path details with progress
    this.learningPathService.getPathDetails(this.pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (details) => {
        this.path.set(details.path);

        // Find current item and progress
        let allItems: LearningPathItem[] = [];
        details.sections?.forEach((s: any) => {
          if (s.items) allItems.push(...s.items);
        });

        const currentIndex = allItems.findIndex(i => i.item_id === this.itemId);
        if (currentIndex >= 0) {
          this.item.set(allItems[currentIndex]);
          this.prevItem.set(currentIndex > 0 ? allItems[currentIndex - 1] : null);
          this.nextItem.set(currentIndex < allItems.length - 1 ? allItems[currentIndex + 1] : null);
        }

        // Find progress for this item
        if (details.progress) {
          let itemProgress: LearningPathItemProgress | null = null;
          details.progress.sections?.forEach((s: any) => {
            const found = s.items?.find((i: any) => i.item_id === this.itemId);
            if (found) itemProgress = found;
          });
          this.progress.set(itemProgress);
        }

        // Calculate path progress
        if (details.enrollment) {
          this.pathProgress.set(details.enrollment.progress_percentage || 0);
        }

        this.loading.set(false);

        // Start item if not already started
        if (!this.progress()?.started_at) {
          this.learningPathService.startItem(this.pathId, this.itemId).subscribe();
        }
      },
      error: () => this.loading.set(false)
    });
  }

  onContentComplete(): void {
    // Auto-complete for videos when finished
    if (this.progress()?.status !== 'completed') {
      this.markComplete();
    }
  }

  onContentLoaded(): void {
    // Track document views
  }

  onExternalLinkClick(): void {
    // Could auto-complete external links after click
  }

  markComplete(): void {
    this.completing.set(true);
    this.learningPathService.completeItem(this.pathId, this.itemId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (result) => {
        this.completing.set(false);
        this.loadData();

        if (result.path_completed) {
          this.toast.success('Congratulations! You have completed the learning path!');
          this.router.navigate(['/learner/paths', this.pathId]);
        }
      },
      error: () => this.completing.set(false)
    });
  }

  navigateToPrev(): void {
    const prev = this.prevItem();
    if (prev) {
      this.router.navigate(['/learner/paths', this.pathId, 'items', prev.item_id]);
    }
  }

  navigateToNext(): void {
    const next = this.nextItem();
    if (next) {
      this.router.navigate(['/learner/paths', this.pathId, 'items', next.item_id]);
    }
  }

  getItemTypeIcon(type: string): string {
    return this.itemTypes.find(t => t.value === type)?.icon || 'bi-file';
  }

  getItemTypeLabel(type: string): string {
    return this.itemTypes.find(t => t.value === type)?.label || type;
  }
}
