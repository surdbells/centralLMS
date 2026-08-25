import { Component, OnInit, OnDestroy, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathListParams,
  SkillLevel,
  SKILL_LEVELS
} from '../../interfaces/learning-path.interfaces';
import {TopComponent} from '../../partials/top/top.component';

@Component({
  selector: 'app-learning-path-catalog',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, TopComponent],
  templateUrl: './learning-path-catalog.component.html',
  styleUrls: ['./learning-path-catalog.component.scss']
})
export class LearningPathCatalogComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  paths = signal<LearningPath[]>([]);
  loading = signal(true);

  // Filters
  searchQuery = signal('');
  skillFilter = signal<SkillLevel | ''>('');
  categoryFilter = signal<number | null>(null);
  sortBy = signal('popular');

  // Pagination
  currentPage = signal(1);
  totalPages = signal(1);
  totalItems = signal(0);
  perPage = 12;

  // Reference data
  skillLevels = SKILL_LEVELS;
  categories = signal<any[]>([]);

  // Computed
  hasFilters = computed(() =>
    this.searchQuery() || this.skillFilter() || this.categoryFilter()
  );

  constructor(private learningPathService: LearningPathService) {}

  ngOnInit(): void {
    this.loadPaths();
    this.loadCategories();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadPaths(): void {
    this.loading.set(true);

    const params: LearningPathListParams = {
      page: this.currentPage(),
      per_page: this.perPage,
      status: 'published',
      sort_by: this.getSortField(),
      sort_dir: this.getSortDirection()
    };

    if (this.searchQuery()) {
      params.search = this.searchQuery();
    }
    if (this.skillFilter()) {
      params.skill_level = this.skillFilter() as SkillLevel;
    }
    if (this.categoryFilter()) {
      params.category_id = this.categoryFilter()!;
    }

    this.learningPathService.list(params).pipe(takeUntil(this.destroy$)).subscribe({
      next: (response) => {
        this.paths.set(response.data);
        this.totalPages.set(response.pagination.total_pages);
        this.totalItems.set(response.pagination.total);
        this.loading.set(false);
      },
      error: () => this.loading.set(false)
    });
  }

  loadCategories(): void {
    // Load from category service
    this.categories.set([
      { category_id: 1, name: 'Compliance' },
      { category_id: 2, name: 'Leadership' },
      { category_id: 3, name: 'Technical Skills' },
      { category_id: 4, name: 'Soft Skills' }
    ]);
  }

  getSortField(): string {
    switch (this.sortBy()) {
      case 'popular': return 'total_enrollments';
      case 'rating': return 'avg_rating';
      case 'newest': return 'created_at';
      case 'duration': return 'estimated_duration_hours';
      default: return 'created_at';
    }
  }

  getSortDirection(): 'asc' | 'desc' {
    return this.sortBy() === 'duration' ? 'asc' : 'desc';
  }

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
    this.skillFilter.set('');
    this.categoryFilter.set(null);
    this.sortBy.set('popular');
    this.currentPage.set(1);
    this.loadPaths();
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.totalPages()) {
      this.currentPage.set(page);
      this.loadPaths();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }

  getSkillLevel(level: string): { label: string; color: string } {
    return this.skillLevels.find(s => s.value === level) || { label: level, color: 'secondary' };
  }

  formatDuration(hours: number | null): string {
    if (!hours) return 'Self-paced';
    if (hours < 1) return `${Math.round(hours * 60)} min`;
    if (hours === 1) return '1 hour';
    return `${hours} hours`;
  }

  getPageNumbers(): number[] {
    const pages: number[] = [];
    const total = this.totalPages();
    const current = this.currentPage();
    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
  }
}
