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

@Component({
  selector: 'app-learning-path-item-viewer',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="item-viewer">
      @if (loading()) {
        <div class="loading-container">
          <div class="spinner-border text-primary" role="status"></div>
        </div>
      } @else if (item()) {
        <!-- Header -->
        <div class="item-header">
          <div class="container">
            <nav aria-label="breadcrumb" class="mb-3">
              <ol class="breadcrumb breadcrumb-light">
                <li class="breadcrumb-item"><a routerLink="/learner/paths">Learning Paths</a></li>
                @if (path()) {
                  <li class="breadcrumb-item"><a [routerLink]="['/learner/paths', path()!.path_id]">{{ path()!.title }}</a></li>
                }
                <li class="breadcrumb-item active">{{ item()!.display_title }}</li>
              </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="item-type-badge mb-2">
                  <i class="bi {{ getItemTypeIcon(item()!.item_type) }} me-1"></i>
                  {{ getItemTypeLabel(item()!.item_type) }}
                </div>
                <h1 class="item-title">{{ item()!.display_title }}</h1>
              </div>
              <div class="d-flex gap-2">
                @if (prevItem()) {
                  <button class="btn btn-outline-light" (click)="navigateToPrev()">
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
            </div>
          </div>
        </div>

        <!-- Content -->
        <div class="container item-content">
          <div class="row">
            <div class="col-lg-8">
              <!-- Content Player -->
              <div class="content-player mb-4">
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
                      <i class="bi bi-box-arrow-up-right display-4"></i>
                      <h4 class="mt-3">External Resource</h4>
                      <p class="text-muted">{{ item()!.external_description }}</p>
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
                <div class="card mb-4">
                  <div class="card-body">
                    <h5 class="card-title">About this content</h5>
                    <p>{{ item()!.external_description }}</p>
                  </div>
                </div>
              }
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
              <!-- Progress Card -->
              <div class="card progress-card mb-4">
                <div class="card-body">
                  <h6 class="mb-3">Your Progress</h6>

                  @if (progress()?.status === 'completed') {
                    <div class="completion-badge mb-3">
                      <i class="bi bi-check-circle-fill text-success"></i>
                      <span>Completed</span>
                    </div>
                  } @else {
                    <div class="progress mb-3" style="height: 8px;">
                      <div
                        class="progress-bar"
                        [style.width.%]="progress()?.progress_percentage || 0"
                      ></div>
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
                </div>
              </div>

              <!-- Path Progress -->
              @if (path()) {
                <div class="card">
                  <div class="card-body">
                    <h6 class="mb-3">Path Progress</h6>
                    <div class="d-flex justify-content-between mb-2">
                      <span>Overall</span>
                      <span class="fw-medium">{{ pathProgress() }}%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                      <div class="progress-bar" [style.width.%]="pathProgress()"></div>
                    </div>
                    <a [routerLink]="['/learner/paths', path()!.path_id]" class="btn btn-primary btn-sm w-100 mt-3">
                      View Full Path
                    </a>
                  </div>
                </div>
              }
            </div>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .item-viewer {
      min-height: 100vh;
      background: #f8fafc;
    }

    .item-header {
      background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
      padding: 2rem 0;

      .breadcrumb-light {
        .breadcrumb-item {
          color: rgba(255, 255, 255, 0.7);

          a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;

            &:hover { color: white; }
          }

          &.active { color: rgba(255, 255, 255, 0.5); }
          &::before { color: rgba(255, 255, 255, 0.4); }
        }
      }

      .item-type-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 20px;
        color: rgba(255, 255, 255, 0.8);
        font-size: 0.875rem;
      }

      .item-title {
        color: white;
        font-size: 1.75rem;
        font-weight: 600;
        margin: 0;
      }
    }

    .item-content {
      padding: 2rem 0;
    }

    .content-player {
      background: #1e293b;
      border-radius: 12px;
      overflow: hidden;
      min-height: 400px;

      video {
        display: block;
      }

      .document-viewer {
        width: 100%;
        height: 600px;
        border: none;
      }

      .external-link-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        color: white;
        text-align: center;
        padding: 2rem;

        h4 { color: white; }

        .text-muted { color: rgba(255, 255, 255, 0.6) !important; }
      }
    }

    .progress-card {
      border: none;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      border-radius: 12px;

      .completion-badge {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        color: #22c55e;

        i { font-size: 1.25rem; }
      }

      .progress-stats {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;

        .stat {
          display: flex;
          align-items: center;
          gap: 0.5rem;
          font-size: 0.875rem;
          color: #64748b;
        }
      }
    }

    .loading-container {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 400px;
    }
  `]
})
export class LearningPathItemViewerComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  pathId!: number;
  itemId!: number;

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
    private learningPathService: LearningPathService
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.pathId = Number(params['id']);
      this.itemId = Number(params['itemId']);
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
          alert('Congratulations! You have completed the learning path!');
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
