// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 15
// Learner: Assessment Catalog Component
// Multi-Tenant LMS | Angular Standalone
// Browse, search, filter, enroll in assessments
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil, debounceTime, distinctUntilChanged } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import {TopComponent} from '../../partials/top/top.component';
import { AssessmentsService, type LearnerAssessment } from '../../services/assessments.service';

interface CatalogAssessment {
  assessment_id: string; title: string; description: string;
  assessment_type: 'quiz'|'exam'|'assignment'|'survey';
  category_id: string; category_name: string;
  course_id: string|null; course_title: string|null;
  total_questions: number; total_points: number;
  time_limit_minutes: number; max_attempts: number;
  passing_score: number; passing_type: 'percentage'|'points';
  proctoring_level: 'none'|'basic'|'standard'|'advanced';
  difficulty: 'beginner'|'intermediate'|'advanced';
  thumbnail_url: string; tags: string[];
  enrollment_status: 'not_enrolled'|'enrolled'|'in_progress'|'completed'|'passed'|'failed';
  attempt_count: number; best_score: number|null;
  avg_rating: number; review_count: number;
  estimated_duration_minutes: number;
  availability_start: string|null; availability_end: string|null;
  prerequisites: {id: string; title: string; met: boolean}[];
  is_mandatory: boolean; due_date: string|null;
}

interface Category { id: string; name: string; count: number; }

@Component({
  selector: 'app-assessment-catalog',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent],
  template: `
    <app-top></app-top>
    <main class="main">
    <div class="ui-page catalog-container">
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-ui-checks-grid"></i></div>
        <div class="ui-page-header__titles">
          <h1>Assessment Catalog</h1>
          <p>Browse assessments, quizzes, and exams to test your knowledge</p>
        </div>
      </header>

      <div class="alert alert-info d-flex align-items-center gap-2" *ngIf="notice" style="margin-bottom:16px">
        <i class="bi bi-info-circle"></i>
        <span>{{ notice }}</span>
      </div>

      <!-- Mandatory Due Soon -->
      <section class="ui-section mandatory-section" *ngIf="mandatoryDueSoon.length > 0 && activeTab==='all'">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title"><i class="bi bi-exclamation-triangle due-icon"></i> Due Soon</h2>
            <p class="ui-section__subtitle">Mandatory assessments due within a week</p></div>
        </div>
        <ul class="ui-list mandatory-cards">
          <li *ngFor="let a of mandatoryDueSoon" class="mandatory-card" (click)="openAssessment(a)">
            <div class="mc-left">
              <span class="ui-pill ui-pill--brand type-badge" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
              <h4>{{ a.title }}</h4>
              <span class="ui-pill ui-pill--warning mc-due"><i class="bi bi-calendar-event"></i> Due {{ formatDate(a.due_date) }}</span>
            </div>
            <div class="mc-right">
              <span class="ui-pill status-pill" *ngIf="statusLabel(a.enrollment_status)" [attr.data-status]="a.enrollment_status"><span class="ui-pill__dot"></span>{{ statusLabel(a.enrollment_status) }}</span>
              <button type="button" class="btn btn-primary btn-sm">{{ a.enrollment_status==='in_progress' ? 'Continue' : 'Start' }}</button>
            </div>
          </li>
        </ul>
      </section>

      <section class="ui-section">
        <!-- Quick Filters -->
        <div class="ui-toolbar">
          <div class="ui-filters">
            <button type="button" class="ui-filter" [class.is-active]="activeTab==='all'" (click)="setTab('all')">All</button>
            <button type="button" class="ui-filter" [class.is-active]="activeTab==='mandatory'" (click)="setTab('mandatory')">
              <i class="bi bi-exclamation-circle"></i> Mandatory
            </button>
            <button type="button" class="ui-filter" [class.is-active]="activeTab==='in_progress'" (click)="setTab('in_progress')">In Progress</button>
            <button type="button" class="ui-filter" [class.is-active]="activeTab==='completed'" (click)="setTab('completed')">Completed</button>
          </div>
          <label class="ui-search">
            <i class="bi bi-search"></i>
            <input type="search" [(ngModel)]="searchQuery" (ngModelChange)="search$.next($event)"
                   placeholder="Search assessments..." aria-label="Search assessments" />
          </label>
        </div>
        <div class="ui-toolbar filter-controls">
          <div class="ui-filters">
            <select class="form-select" [(ngModel)]="filterCategory" (change)="applyFilters()" aria-label="Category">
              <option value="">All Categories</option>
              <option *ngFor="let c of categories" [value]="c.id">{{ c.name }} ({{ c.count }})</option>
            </select>
            <select class="form-select" [(ngModel)]="filterType" (change)="applyFilters()" aria-label="Type">
              <option value="">All Types</option>
              <option value="quiz">Quiz</option><option value="exam">Exam</option>
              <option value="assignment">Assignment</option><option value="survey">Survey</option>
            </select>
            <select class="form-select" [(ngModel)]="filterDifficulty" (change)="applyFilters()" aria-label="Difficulty">
              <option value="">Any Difficulty</option>
              <option value="beginner">Beginner</option><option value="intermediate">Intermediate</option><option value="advanced">Advanced</option>
            </select>
          </div>
          <div class="view-toggle" role="group" aria-label="View">
            <button type="button" class="vt" [class.active]="viewMode==='grid'" (click)="viewMode='grid'" aria-label="Grid view"><i class="bi bi-grid"></i></button>
            <button type="button" class="vt" [class.active]="viewMode==='list'" (click)="viewMode='list'" aria-label="List view"><i class="bi bi-list-ul"></i></button>
          </div>
        </div>

        <!-- Loading -->
        <div class="loading" *ngIf="loading"><div class="spinner-border text-secondary" role="status"></div></div>

        <!-- Grid View -->
        <div class="ui-grid catalog-grid" *ngIf="!loading && viewMode==='grid'">
          <article *ngFor="let a of filteredAssessments" class="ui-card assess-card" (click)="openAssessment(a)">
            <div class="ui-card__media ac-thumb" [style.backgroundImage]="a.thumbnail_url ? 'url('+a.thumbnail_url+')' : ''">
              <span class="ui-pill ac-type" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
              <span class="ui-pill ac-proctored" *ngIf="a.proctoring_level!=='none'">
                <i class="bi bi-shield-check"></i> Proctored
              </span>
              <i class="bi bi-ui-checks thumb-glyph" *ngIf="!a.thumbnail_url" aria-hidden="true"></i>
            </div>
            <div class="ui-card__body ac-body">
              <div class="ac-category" *ngIf="a.category_name">{{ a.category_name }}</div>
              <h3 class="ui-card__title ac-title">{{ a.title }}</h3>
              <p class="ac-desc" *ngIf="a.description">{{ truncate(a.description, 80) }}</p>
              <div class="ui-card__meta ac-meta">
                <span><i class="bi bi-question-circle"></i> {{ a.total_questions }}q</span>
                <span><i class="bi bi-clock"></i> {{ a.time_limit_minutes || a.estimated_duration_minutes }}m</span>
                <span *ngIf="a.avg_rating"><i class="bi bi-star-fill rating-star"></i> {{ a.avg_rating | number:'1.1-1' }}</span>
                <span class="ac-difficulty"><span class="diff-dot" [attr.data-diff]="a.difficulty"></span> {{ a.difficulty }}</span>
              </div>
              <div class="ac-tags" *ngIf="a.tags?.length">
                <span *ngFor="let t of a.tags.slice(0,3)" class="ui-pill ui-pill--neutral">{{ t }}</span>
              </div>
            </div>
            <div class="ui-card__footer ac-footer">
              <span class="ui-pill status-pill" *ngIf="a.enrollment_status!=='not_enrolled'" [attr.data-status]="a.enrollment_status">
                <span class="ui-pill__dot"></span>{{ statusLabel(a.enrollment_status) }}<span *ngIf="a.best_score!==null"> · {{ a.best_score }}%</span>
              </span>
              <div class="ac-prereqs" *ngIf="hasUnmetPrerequisites(a)">
                <i class="bi bi-lock"></i>
                Prerequisites required
              </div>
              <button type="button"
                class="btn btn-primary btn-sm ac-btn"
                [disabled]="!arePrerequisitesMet(a)">
                {{ getActionLabel(a) }}
              </button>
            </div>
          </article>
        </div>

        <!-- List View -->
        <ul class="ui-list catalog-list" *ngIf="!loading && viewMode==='list'">
          <li *ngFor="let a of filteredAssessments" class="list-item" (click)="openAssessment(a)">
            <div class="li-type"><span class="ui-pill ui-pill--brand type-badge" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span></div>
            <div class="li-info">
              <h4>{{ a.title }}</h4>
              <span class="li-cat">{{ a.category_name }}</span>
            </div>
            <div class="li-meta">
              <span>{{ a.total_questions }}q · {{ a.time_limit_minutes || a.estimated_duration_minutes }}m</span>
              <span class="diff-dot" [attr.data-diff]="a.difficulty" [title]="a.difficulty"></span>
            </div>
            <div class="li-proctoring" *ngIf="a.proctoring_level!=='none'" title="Proctored">
              <i class="bi bi-shield-check"></i>
            </div>
            <span class="ui-pill status-pill" *ngIf="a.enrollment_status!=='not_enrolled'" [attr.data-status]="a.enrollment_status">
              <span class="ui-pill__dot"></span>{{ statusLabel(a.enrollment_status) }}
            </span>
            <button type="button" class="btn btn-primary btn-sm">{{ getActionLabel(a) }}</button>
          </li>
        </ul>

        <!-- Empty State -->
        <div class="ui-state" *ngIf="!loading && filteredAssessments.length===0">
          <span class="ui-state__icon"><i class="bi bi-search"></i></span>
          <h3>No assessments found</h3>
          <p>Try adjusting your filters or search query.</p>
          <button type="button" class="btn btn-outline-secondary" (click)="clearFilters()">Clear Filters</button>
        </div>

        <!-- Pagination -->
        <div class="pager" *ngIf="!loading && totalPages > 1">
          <button type="button" class="btn btn-sm btn-outline-secondary" (click)="goToPage(currentPage-1)" [disabled]="currentPage===1" aria-label="Previous page">
            <i class="bi bi-chevron-left"></i></button>
          <span class="pg-info">{{ currentPage }} / {{ totalPages }}</span>
          <button type="button" class="btn btn-sm btn-outline-secondary" (click)="goToPage(currentPage+1)" [disabled]="currentPage===totalPages" aria-label="Next page">
            <i class="bi bi-chevron-right"></i></button>
        </div>
      </section>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .due-icon { color: var(--ui-warning); margin-right: 4px; }
    .type-badge, .ac-type { text-transform: uppercase; letter-spacing: .03em; font-size: .6875rem; height: 22px; }

    /* Status pills: tone by enrolment status. */
    .status-pill[data-status="enrolled"] { background: rgba(var(--brand-primary-rgb), .1); border-color: rgba(var(--brand-primary-rgb), .28); color: var(--brand-primary); }
    .status-pill[data-status="in_progress"] { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .status-pill[data-status="completed"],
    .status-pill[data-status="passed"] { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .status-pill[data-status="failed"] { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }

    /* Due soon */
    .mandatory-card { justify-content: space-between; cursor: pointer; }
    .mandatory-card:hover h4 { color: var(--brand-primary); }
    .mc-left { display: flex; align-items: center; gap: 12px; min-width: 0; flex-wrap: wrap; }
    .mc-left h4 { margin: 0; font-size: .875rem; font-weight: 600; color: var(--ui-text); transition: color .15s ease; }
    .mc-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }

    /* Toolbar */
    .filter-controls { margin-bottom: 16px; }
    .view-toggle { display: inline-flex; border: 1px solid var(--ui-border); border-radius: var(--ui-radius-ctl); overflow: hidden; height: var(--ui-ctl-h); }
    .vt { width: 38px; border: 0; background: var(--ui-surface); cursor: pointer; color: var(--ui-text-muted); }
    .vt + .vt { border-left: 1px solid var(--ui-border); }
    .vt:hover { color: var(--ui-text); }
    .vt.active { background: rgba(var(--brand-primary-rgb), .08); color: var(--brand-primary); }

    .loading { display: flex; justify-content: center; padding: 60px 0; }

    /* Grid cards */
    .assess-card { cursor: pointer; }
    .ac-thumb { aspect-ratio: auto; height: 120px; display: flex; align-items: flex-start; justify-content: space-between; padding: 10px; background-size: cover; background-position: center; }
    .ac-thumb .ui-pill { position: relative; z-index: 1; background: var(--ui-surface); }
    .ac-type { color: var(--brand-primary); border-color: rgba(var(--brand-primary-rgb), .28); }
    .ac-proctored { color: var(--ui-text-muted); }
    .thumb-glyph { position: absolute; right: 16px; bottom: 10px; font-size: 2.5rem; color: rgba(var(--brand-primary-rgb), .22); }
    .ac-body { gap: 6px; }
    .ac-category { font-size: .6875rem; font-weight: 600; color: var(--brand-primary); text-transform: uppercase; letter-spacing: .04em; }
    .ac-desc { font-size: .8125rem; color: var(--ui-text-muted); margin: 0; line-height: 1.45; }
    .ac-meta { display: flex; flex-wrap: wrap; gap: 12px; font-size: .75rem; }
    .ac-meta span { display: inline-flex; align-items: center; gap: 4px; }
    .rating-star { color: var(--ui-warning); }
    .ac-tags { display: flex; gap: 4px; flex-wrap: wrap; }
    .ac-difficulty { text-transform: capitalize; }
    .diff-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; background: var(--ui-text-subtle); }
    .diff-dot[data-diff="beginner"] { background: var(--ui-success); }
    .diff-dot[data-diff="intermediate"] { background: var(--ui-warning); }
    .diff-dot[data-diff="advanced"] { background: var(--ui-danger); }
    .ac-footer { justify-content: flex-start; flex-wrap: wrap; }
    .ac-prereqs { font-size: .6875rem; color: var(--ui-text-subtle); display: flex; align-items: center; gap: 4px; }
    .ac-btn { margin-left: auto; }

    /* List rows */
    .list-item { cursor: pointer; gap: 14px; }
    .list-item:hover h4 { color: var(--brand-primary); }
    .li-info { flex: 1; min-width: 0; }
    .li-info h4 { margin: 0; font-size: .875rem; font-weight: 600; color: var(--ui-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: color .15s ease; }
    .li-cat { font-size: .75rem; color: var(--ui-text-muted); }
    .li-meta { font-size: .75rem; color: var(--ui-text-subtle); display: flex; align-items: center; gap: 6px; }
    .li-proctoring { color: var(--brand-primary); }

    .pager { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 24px; }
    .pg-info { font-size: .8125rem; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; }

    @media (max-width: 767.98px) {
      .list-item { flex-wrap: wrap; }
      .mandatory-card { flex-wrap: wrap; }
    }
  `]
})
export class AssessmentCatalogComponent implements OnInit, OnDestroy {
  private router = inject(Router);
  private assessmentsApi = inject(AssessmentsService);
  private destroy$ = new Subject<void>();

  assessments: CatalogAssessment[] = [];
  filteredAssessments: CatalogAssessment[] = [];
  categories: Category[] = [];
  loading = false;
  notice: string | null = null;

  searchQuery = '';
  search$ = new Subject<string>();
  activeTab = 'all';
  filterCategory = '';
  filterType = '';
  filterDifficulty = '';
  viewMode: 'grid'|'list' = 'grid';

  currentPage = 1;
  pageSize = 12;
  totalPages = 1;

  get mandatoryDueSoon(): CatalogAssessment[] {
    const now = new Date();
    const week = new Date(now.getTime() + 7 * 86400000);
    return this.assessments.filter(a => a.is_mandatory && a.due_date &&
      a.enrollment_status !== 'passed' && a.enrollment_status !== 'completed' &&
      new Date(a.due_date) <= week).sort((a, b) => new Date(a.due_date!).getTime() - new Date(b.due_date!).getTime());
  }

  ngOnInit(): void {
    this.loadCatalog();
    this.search$.pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$)).subscribe(() => this.applyFilters());
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  // The learner-facing catalogue: the published assessments available to this
  // learner, with their assignment (mandatory + due date) and their own
  // results. Ratings/reviews have no source and default to zero.
  loadCatalog(): void {
    this.loading = true;
    this.notice = null;
    this.assessmentsApi.catalogue().pipe(takeUntil(this.destroy$)).subscribe({
      next: (rows) => {
        this.assessments = rows.map((r) => this.toCatalog(r));
        this.buildCategories();
        this.loading = false;
        this.applyFilters();
      },
      error: () => {
        this.loading = false;
        this.assessments = [];
        this.notice = 'Could not load the assessment catalogue.';
        this.applyFilters();
      },
    });
  }

  private toCatalog(r: LearnerAssessment): CatalogAssessment {
    return {
      assessment_id: r.assessmentId,
      title: r.title,
      description: r.description ?? '',
      assessment_type: r.kind as CatalogAssessment['assessment_type'],
      category_id: r.categoryId ?? '',
      category_name: r.categoryName ?? '',
      course_id: r.courseId,
      course_title: r.courseTitle,
      total_questions: r.questionCount,
      total_points: r.totalPoints,
      time_limit_minutes: r.timeLimitMinutes ?? 0,
      max_attempts: r.maxAttempts ?? 0,
      passing_score: r.passingScore,
      passing_type: r.passingType as CatalogAssessment['passing_type'],
      proctoring_level: r.proctoringLevel as CatalogAssessment['proctoring_level'],
      difficulty: (r.difficulty ?? 'beginner') as CatalogAssessment['difficulty'],
      thumbnail_url: '',
      tags: [],
      enrollment_status: r.enrollmentStatus as CatalogAssessment['enrollment_status'],
      attempt_count: r.attemptCount,
      best_score: r.bestScore,
      avg_rating: 0,
      review_count: 0,
      estimated_duration_minutes: 0,
      availability_start: r.availableFrom,
      availability_end: r.availableUntil,
      prerequisites: [],
      is_mandatory: r.isMandatory,
      due_date: r.dueDate,
    };
  }

  private buildCategories(): void {
    const map = new Map<string, Category>();
    for (const a of this.assessments) {
      if (!a.category_id) continue;
      const existing = map.get(a.category_id);
      if (existing) existing.count++;
      else map.set(a.category_id, { id: a.category_id, name: a.category_name || 'Uncategorised', count: 1 });
    }
    this.categories = Array.from(map.values()).sort((x, y) => x.name.localeCompare(y.name));
  }

  loadCategories(): void { /* categories are derived from the catalogue payload */ }

  setTab(tab: string): void { this.activeTab = tab; this.currentPage = 1; this.applyFilters(); }

  applyFilters(): void {
    let result = [...this.assessments];
    if (this.searchQuery.trim()) {
      const q = this.searchQuery.toLowerCase();
      result = result.filter(a => a.title.toLowerCase().includes(q) || a.description.toLowerCase().includes(q) || a.category_name.toLowerCase().includes(q));
    }
    if (this.activeTab === 'mandatory') result = result.filter(a => a.is_mandatory);
    else if (this.activeTab === 'in_progress') result = result.filter(a => a.enrollment_status === 'in_progress' || a.enrollment_status === 'enrolled');
    else if (this.activeTab === 'completed') result = result.filter(a => a.enrollment_status === 'completed' || a.enrollment_status === 'passed');
    if (this.filterCategory) result = result.filter(a => a.category_id === this.filterCategory);
    if (this.filterType) result = result.filter(a => a.assessment_type === this.filterType);
    if (this.filterDifficulty) result = result.filter(a => a.difficulty === this.filterDifficulty);
    this.totalPages = Math.max(1, Math.ceil(result.length / this.pageSize));
    if (this.currentPage > this.totalPages) this.currentPage = 1;
    this.filteredAssessments = result.slice((this.currentPage - 1) * this.pageSize, this.currentPage * this.pageSize);
  }

  clearFilters(): void {
    this.searchQuery = ''; this.activeTab = 'all'; this.filterCategory = ''; this.filterType = ''; this.filterDifficulty = '';
    this.currentPage = 1; this.applyFilters();
  }

  goToPage(p: number): void { if (p >= 1 && p <= this.totalPages) { this.currentPage = p; this.applyFilters(); } }

  openAssessment(a: CatalogAssessment): void {
    if (a.enrollment_status === 'in_progress' || a.enrollment_status === 'enrolled')
      this.router.navigate(['/learner/assessments', a.assessment_id, 'lobby']);
    else this.router.navigate(['/learner/assessments', a.assessment_id]);
  }

  statusLabel(s: string): string {
    const m: Record<string, string> = { not_enrolled: '', enrolled: 'Enrolled', in_progress: 'In Progress', completed: 'Completed', passed: 'Passed', failed: 'Failed' };
    return m[s] || s;
  }

  getActionLabel(a: CatalogAssessment): string {
    if (a.enrollment_status === 'in_progress') return 'Continue';
    if (a.enrollment_status === 'completed' || a.enrollment_status === 'passed') return 'Review';
    if (a.enrollment_status === 'failed') return 'Retry';
    return 'Start';
  }

  formatDate(d: string|null): string {
    if (!d) return ''; return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  truncate(t: string, max: number): string {
    if (!t) return ''; const s = t.replace(/<[^>]*>/g, '');
    return s.length > max ? s.substring(0, max) + '...' : s;
  }

  arePrerequisitesMet(a: any): boolean {
    return !a.prerequisites?.length || a.prerequisites.every((p: any) => p.met);
  }
  hasUnmetPrerequisites(a: any): boolean {
    return !!a.prerequisites?.length && !a.prerequisites.every((p: any) => p.met);
  }
}
