import { Component, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathListParams,
  PathStatus,
  SkillLevel,
  SKILL_LEVELS,
  PATH_STATUSES
} from '../../interfaces/learning-path.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';

@Component({
  selector: 'app-learning-paths-list',
  standalone: true,
  imports: [CommonModule, RouterModule, FormsModule, AdmintopComponent, TranslatePipe],
  templateUrl: './learning-paths-list.component.html',
  styleUrls: ['./learning-paths-list.component.scss']
})
export class LearningPathsListComponent implements OnInit {
  // Data
  paths = signal<LearningPath[]>([]);
  loading = signal(true);

  // Pagination
  currentPage = signal(1);
  totalPages = signal(1);
  totalItems = signal(0);
  perPage = signal(12);

  // Filters
  searchQuery = signal('');
  statusFilter = signal<PathStatus | ''>('');
  skillFilter = signal<SkillLevel | ''>('');
  categoryFilter = signal<string | null>(null);

  // View mode
  viewMode = signal<'grid' | 'list'>('grid');

  // Sort
  sortBy = signal('created_at');
  sortDir = signal<'asc' | 'desc'>('desc');

  // Reference data
  skillLevels = SKILL_LEVELS;
  pathStatuses = PATH_STATUSES;
  categories = signal<any[]>([]);

  // Selection for bulk actions
  selectedIds = signal<Set<string>>(new Set());
  selectAll = signal(false);

  // ========================================
  // COMPUTED SIGNALS (replacing template arrow functions)
  // ========================================

  /** Count of paths with 'published' status */
  publishedCount = computed(() =>
    this.paths().filter(p => p.status === 'published').length
  );

  /** Count of paths with 'draft' status */
  draftCount = computed(() =>
    this.paths().filter(p => p.status === 'draft').length
  );

  /** Count of paths with 'archived' status */
  archivedCount = computed(() =>
    this.paths().filter(p => p.status === 'archived').length
  );

  /** Total enrollments across all paths */
  totalEnrollments = computed(() =>
    this.paths().reduce((sum, p) => sum + (p.total_enrollments || 0), 0)
  );

  /** Whether any items are selected */
  hasSelection = computed(() => this.selectedIds().size > 0);

  /** Selected count for display */
  selectedCount = computed(() => this.selectedIds().size);

  constructor(
    private learningPathService: LearningPathService,
    private i18n: I18nService,
    private dialog: ConfirmDialogService,
    private toast: HotToastService
  ) {}

  ngOnInit(): void {
    this.loadPaths();
    this.loadCategories();
  }

  loadPaths(): void {
    this.loading.set(true);

    const params: LearningPathListParams = {
      page: this.currentPage(),
      per_page: this.perPage(),
      sort_by: this.sortBy(),
      sort_dir: this.sortDir()
    };

    if (this.searchQuery()) {
      params.search = this.searchQuery();
    }
    if (this.statusFilter()) {
      params.status = this.statusFilter() as PathStatus;
    }
    if (this.skillFilter()) {
      params.skill_level = this.skillFilter() as SkillLevel;
    }
    if (this.categoryFilter()) {
      params.category_id = this.categoryFilter()!;
    }

    this.learningPathService.list(params).subscribe({
      next: (response) => {
        this.paths.set(response.data);
        this.totalPages.set(response.pagination.total_pages);
        this.totalItems.set(response.pagination.total);
        this.loading.set(false);
      },
      error: (error) => {
        console.error('Error loading paths:', error);
        this.loading.set(false);
      }
    });
  }

  loadCategories(): void {
    // Load categories from category service
    // this.categoryService.list().subscribe(cats => this.categories.set(cats));
  }

  // Search & Filter
  onSearch(): void {
    this.currentPage.set(1);
    this.loadPaths();
  }

  onFilterChange(): void {
    this.currentPage.set(1);
    this.loadPaths();
  }

  clearFilters(): void {
    this.searchQuery.set('');
    this.statusFilter.set('');
    this.skillFilter.set('');
    this.categoryFilter.set(null);
    this.currentPage.set(1);
    this.loadPaths();
  }

  // Sorting
  onSort(field: string): void {
    if (this.sortBy() === field) {
      this.sortDir.set(this.sortDir() === 'asc' ? 'desc' : 'asc');
    } else {
      this.sortBy.set(field);
      this.sortDir.set('asc');
    }
    this.loadPaths();
  }

  // Pagination
  goToPage(page: number): void {
    if (page >= 1 && page <= this.totalPages()) {
      this.currentPage.set(page);
      this.loadPaths();
    }
  }

  // View mode
  setViewMode(mode: 'grid' | 'list'): void {
    this.viewMode.set(mode);
  }

  // Selection
  toggleSelect(pathId: string): void {
    const selected = new Set(this.selectedIds());
    if (selected.has(pathId)) {
      selected.delete(pathId);
    } else {
      selected.add(pathId);
    }
    this.selectedIds.set(selected);
  }

  toggleSelectAll(): void {
    if (this.selectAll()) {
      this.selectedIds.set(new Set());
      this.selectAll.set(false);
    } else {
      const allIds = new Set(this.paths().map(p => p.path_id));
      this.selectedIds.set(allIds);
      this.selectAll.set(true);
    }
  }

  isSelected(pathId: string): boolean {
    return this.selectedIds().has(pathId);
  }

  // Actions
  async publishPath(path: LearningPath): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_publish', { title: path.title }), { type: 'warning' })) {
      this.learningPathService.publish(path.path_id).subscribe({
        next: () => {
          this.loadPaths();
        },
        error: (error) => {
          this.toast.error(error.error?.errors?.join('\n') || this.i18n.t('paths_admin.publish_failed'));
        }
      });
    }
  }

  async archivePath(path: LearningPath): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_archive', { title: path.title }), { type: 'warning' })) {
      this.learningPathService.archive(path.path_id).subscribe({
        next: () => this.loadPaths()
      });
    }
  }

  async deletePath(path: LearningPath): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_delete', { title: path.title }), { type: 'danger' })) {
      this.learningPathService.delete(path.path_id).subscribe({
        next: () => this.loadPaths()
      });
    }
  }

  duplicatePath(path: LearningPath): void {
    const newTitle = prompt(this.i18n.t('paths_admin.prompt_copy_title'), this.i18n.t('paths_admin.copy_suffix', { title: path.title }));
    if (newTitle) {
      this.learningPathService.duplicate(path.path_id, newTitle).subscribe({
        next: () => this.loadPaths()
      });
    }
  }

  // Bulk actions
  async bulkPublish(): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_bulk_publish', { count: this.selectedIds().size }), { type: 'warning' })) {
      const ids = Array.from(this.selectedIds());
      // Sequential publish
      ids.forEach(id => {
        this.learningPathService.publish(id).subscribe();
      });
      setTimeout(() => this.loadPaths(), 500);
    }
  }

  async bulkArchive(): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_bulk_archive', { count: this.selectedIds().size }), { type: 'warning' })) {
      const ids = Array.from(this.selectedIds());
      ids.forEach(id => {
        this.learningPathService.archive(id).subscribe();
      });
      setTimeout(() => this.loadPaths(), 500);
    }
  }

  async bulkDelete(): Promise<void> {
    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_bulk_delete', { count: this.selectedIds().size }), { type: 'danger' })) {
      const ids = Array.from(this.selectedIds());
      ids.forEach(id => {
        this.learningPathService.delete(id).subscribe();
      });
      setTimeout(() => this.loadPaths(), 500);
    }
  }

  // Helpers
  getStatusBadgeClass(status: PathStatus): string {
    const statusConfig = this.pathStatuses.find(s => s.value === status);
    return `badge bg-${statusConfig?.color || 'secondary'}`;
  }

  getSkillBadgeClass(level: SkillLevel): string {
    const skillConfig = this.skillLevels.find(s => s.value === level);
    return `badge bg-${skillConfig?.color || 'secondary'}`;
  }

  getSkillLabel(level: SkillLevel): string {
    return this.skillLevels.find(s => s.value === level)?.label || level;
  }

  formatDuration(hours: number | null): string {
    if (!hours) return '-';
    if (hours < 1) return this.i18n.t('paths.minutes', { count: Math.round(hours * 60) });
    if (hours === 1) return this.i18n.t('paths.one_hour');
    return this.i18n.t('paths.hours', { count: hours });
  }

  formatDate(dateStr: string | null): string {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString();
  }

  getPageNumbers(): number[] {
    const total = this.totalPages();
    const current = this.currentPage();
    const pages: number[] = [];

    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);

    for (let i = start; i <= end; i++) {
      pages.push(i);
    }

    return pages;
  }
}
