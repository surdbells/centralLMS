import { Component, OnInit, OnDestroy, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { Subject, takeUntil, forkJoin } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathSection,
  LearningPathStatistics,
  RatingSummary,
  SKILL_LEVELS,
  PATH_STATUSES,
  ITEM_TYPES
} from '../../interfaces/learning-path.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-learning-path-detail',
  standalone: true,
  imports: [CommonModule, RouterModule, AdmintopComponent],
  templateUrl: './learning-path-detail.component.html',
  styleUrls: ['./learning-path-detail.component.scss']
})
export class LearningPathDetailComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  pathId!: string;
  path = signal<LearningPath | null>(null);
  sections = signal<LearningPathSection[]>([]);
  statistics = signal<LearningPathStatistics | null>(null);
  ratingSummary = signal<RatingSummary | null>(null);

  loading = signal(true);
  publishing = signal(false);

  // Reference data
  skillLevels = SKILL_LEVELS;
  pathStatuses = PATH_STATUSES;
  itemTypes = ITEM_TYPES;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private learningPathService: LearningPathService
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.pathId = String(params['id']);
      this.loadData();
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadData(): void {
    this.loading.set(true);

    forkJoin({
      path: this.learningPathService.getById(this.pathId),
      sections: this.learningPathService.getSectionsWithItems(this.pathId),
      statistics: this.learningPathService.getStatistics(this.pathId),
      ratings: this.learningPathService.getRatingSummary(this.pathId)
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ path, sections, statistics, ratings }) => {
        this.path.set(path);
        this.sections.set(sections);
        this.statistics.set(statistics);
        this.ratingSummary.set(ratings);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.router.navigate(['/admin/learning-paths']);
      }
    });
  }

  // Actions
  publishPath(): void {
    const p = this.path();
    if (!p) return;

    if (p.total_items === 0) {
      alert('Cannot publish an empty learning path. Please add content first.');
      return;
    }

    if (confirm('Publish this learning path? It will become available to learners.')) {
      this.publishing.set(true);
      this.learningPathService.publish(p.path_id).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.loadData();
          this.publishing.set(false);
        },
        error: (err) => {
          alert(err.error?.errors?.join('\n') || 'Failed to publish');
          this.publishing.set(false);
        }
      });
    }
  }

  archivePath(): void {
    if (confirm('Archive this learning path? It will no longer be visible to learners.')) {
      this.learningPathService.archive(this.pathId).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => this.loadData()
      });
    }
  }

  duplicatePath(): void {
    const p = this.path();
    if (!p) return;

    const newTitle = prompt('Enter title for the copy:', `${p.title} (Copy)`);
    if (newTitle) {
      this.learningPathService.duplicate(p.path_id, newTitle).pipe(takeUntil(this.destroy$)).subscribe({
        next: (response) => {
          if (response?.path_id) {
            this.router.navigate(['/admin/learning-paths', response?.path_id]);
          }
        }
      });
    }
  }

  deletePath(): void {
    if (confirm('Delete this learning path? This action cannot be undone.')) {
      this.learningPathService.delete(this.pathId).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => this.router.navigate(['/admin/learning-paths'])
      });
    }
  }

  // Helpers
  getStatusBadge(status: string): { label: string; color: string } {
    return this.pathStatuses.find(s => s.value === status) || { label: status, color: 'secondary' };
  }

  getSkillLevel(level: string): { label: string; color: string } {
    return this.skillLevels.find(s => s.value === level) || { label: level, color: 'secondary' };
  }

  getItemTypeIcon(type: string): string {
    return this.itemTypes.find(t => t.value === type)?.icon || 'bi-file';
  }

  formatDuration(hours: number | null): string {
    if (!hours) return '-';
    if (hours < 1) return `${Math.round(hours * 60)} min`;
    if (hours === 1) return '1 hour';
    return `${hours} hours`;
  }

  formatDate(date: string | null): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    });
  }

  getCompletionRate(): number {
    const stats = this.statistics();
    if (!stats || stats.total_enrollments === 0) return 0;
    return Math.round((stats.completed_enrollments / stats.total_enrollments) * 100);
  }
}
