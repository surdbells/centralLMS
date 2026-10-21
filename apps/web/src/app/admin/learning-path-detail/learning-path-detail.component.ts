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
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';

@Component({
  selector: 'app-learning-path-detail',
  standalone: true,
  imports: [CommonModule, RouterModule, AdmintopComponent, TranslatePipe],
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
    private learningPathService: LearningPathService,
    private i18n: I18nService,
    private dialog: ConfirmDialogService,
    private toast: HotToastService
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
  async publishPath(): Promise<void> {
    const p = this.path();
    if (!p) return;

    if (p.total_items === 0) {
      this.toast.error(this.i18n.t('paths_admin.cannot_publish_content'));
      return;
    }

    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_publish_path'), { type: 'warning' })) {
      this.publishing.set(true);
      this.learningPathService.publish(p.path_id).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.loadData();
          this.publishing.set(false);
        },
        error: (err) => {
          this.toast.error(err.error?.errors?.join('\n') || this.i18n.t('paths_admin.publish_failed'));
          this.publishing.set(false);
        }
      });
    }
  }

  async archivePath(): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_archive_path'), { type: 'warning' })) {
      this.learningPathService.archive(this.pathId).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => this.loadData()
      });
    }
  }

  duplicatePath(): void {
    const p = this.path();
    if (!p) return;

    const newTitle = prompt(this.i18n.t('paths_admin.prompt_copy_title'), this.i18n.t('paths_admin.copy_suffix', { title: p.title }));
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

  async deletePath(): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_delete_path'), { type: 'danger' })) {
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
    if (hours < 1) return this.i18n.t('paths.minutes', { count: Math.round(hours * 60) });
    if (hours === 1) return this.i18n.t('paths.one_hour');
    return this.i18n.t('paths.hours', { count: hours });
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
