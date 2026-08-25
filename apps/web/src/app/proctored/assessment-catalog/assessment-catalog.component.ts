import { decodeSessionBlob } from '../../core/utils/session-blob';
// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 15
// Learner: Assessment Catalog Component
// Multi-Tenant LMS | Angular Standalone
// Browse, search, filter, enroll in assessments
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Subject, takeUntil, debounceTime, distinctUntilChanged } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import {TopComponent} from '../../partials/top/top.component';

interface ApiResponse<T> { code: number; status: string; data: T; message: string; }

interface CatalogAssessment {
  assessment_id: number; title: string; description: string;
  assessment_type: 'quiz'|'exam'|'practice'|'survey';
  category_id: number; category_name: string;
  course_id: number|null; course_title: string|null;
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
  prerequisites: {id: number; title: string; met: boolean}[];
  is_mandatory: boolean; due_date: string|null;
}

interface Category { id: number; name: string; count: number; }

@Component({
  selector: 'app-assessment-catalog',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent],
  template: `
    <app-top></app-top>
    <main class="main">
      <div class="container-fluid py-4">
            <div class="catalog-container">
      <!-- Hero Banner -->
      <div class="catalog-hero">
        <div class="hero-content">
          <h1>Assessment Catalog</h1>
          <p>Browse assessments, quizzes, and exams to test your knowledge</p>
          <div class="hero-search">
            <tui-icon icon="@tui.search" style="font-size:20px;color:#94a3b8"></tui-icon>
            <input type="text" [(ngModel)]="searchQuery" (ngModelChange)="search$.next($event)"
                   placeholder="Search assessments..." class="hero-input" />
          </div>
        </div>
      </div>

      <!-- Quick Filters -->
      <div class="filter-bar">
        <div class="filter-tabs">
          <button class="ftab" [class.active]="activeTab==='all'" (click)="setTab('all')">All</button>
          <button class="ftab" [class.active]="activeTab==='mandatory'" (click)="setTab('mandatory')">
            <tui-icon icon="@tui.alert-circle" style="font-size:14px"></tui-icon> Mandatory
          </button>
          <button class="ftab" [class.active]="activeTab==='in_progress'" (click)="setTab('in_progress')">In Progress</button>
          <button class="ftab" [class.active]="activeTab==='completed'" (click)="setTab('completed')">Completed</button>
        </div>
        <div class="filter-controls">
          <select class="fc" [(ngModel)]="filterCategory" (change)="applyFilters()">
            <option value="">All Categories</option>
            <option *ngFor="let c of categories" [value]="c.id">{{ c.name }} ({{ c.count }})</option>
          </select>
          <select class="fc" [(ngModel)]="filterType" (change)="applyFilters()">
            <option value="">All Types</option>
            <option value="quiz">Quiz</option><option value="exam">Exam</option>
            <option value="practice">Practice</option><option value="survey">Survey</option>
          </select>
          <select class="fc" [(ngModel)]="filterDifficulty" (change)="applyFilters()">
            <option value="">Any Difficulty</option>
            <option value="beginner">Beginner</option><option value="intermediate">Intermediate</option><option value="advanced">Advanced</option>
          </select>
          <div class="view-toggle">
            <button class="vt" [class.active]="viewMode==='grid'" (click)="viewMode='grid'"><tui-icon icon="@tui.grid" style="font-size:16px"></tui-icon></button>
            <button class="vt" [class.active]="viewMode==='list'" (click)="viewMode='list'"><tui-icon icon="@tui.list" style="font-size:16px"></tui-icon></button>
          </div>
        </div>
      </div>

      <!-- Mandatory Due Soon -->
      <div class="mandatory-section" *ngIf="mandatoryDueSoon.length > 0 && activeTab==='all'">
        <h2><tui-icon icon="@tui.alert-triangle" style="font-size:20px;color:#f59e0b"></tui-icon> Due Soon</h2>
        <div class="mandatory-cards">
          <div *ngFor="let a of mandatoryDueSoon" class="mandatory-card" (click)="openAssessment(a)">
            <div class="mc-left">
              <span class="type-badge" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
              <h4>{{ a.title }}</h4>
              <span class="mc-due">Due {{ formatDate(a.due_date) }}</span>
            </div>
            <div class="mc-right">
              <span class="mc-status" [attr.data-status]="a.enrollment_status">{{ statusLabel(a.enrollment_status) }}</span>
              <button class="btn btn-primary btn-sm">{{ a.enrollment_status==='in_progress' ? 'Continue' : 'Start' }}</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div class="loading" *ngIf="loading"><div class="spinner"></div></div>

      <!-- Grid View -->
      <div class="catalog-grid" *ngIf="!loading && viewMode==='grid'">
        <div *ngFor="let a of filteredAssessments" class="assess-card" (click)="openAssessment(a)">
          <div class="ac-thumb" [style.backgroundImage]="a.thumbnail_url ? 'url('+a.thumbnail_url+')' : ''">
            <span class="ac-type" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
            <span class="ac-proctored" *ngIf="a.proctoring_level!=='none'">
              <tui-icon icon="@tui.shield" style="font-size:12px"></tui-icon> Proctored
            </span>
          </div>
          <div class="ac-body">
            <div class="ac-category">{{ a.category_name }}</div>
            <h3 class="ac-title">{{ a.title }}</h3>
            <p class="ac-desc">{{ truncate(a.description, 80) }}</p>
            <div class="ac-meta">
              <span><tui-icon icon="@tui.help-circle" style="font-size:13px"></tui-icon> {{ a.total_questions }}q</span>
              <span><tui-icon icon="@tui.clock" style="font-size:13px"></tui-icon> {{ a.time_limit_minutes || a.estimated_duration_minutes }}m</span>
              <span *ngIf="a.avg_rating"><tui-icon icon="@tui.star" style="font-size:13px;color:#f59e0b"></tui-icon> {{ a.avg_rating | number:'1.1-1' }}</span>
            </div>
            <div class="ac-tags" *ngIf="a.tags?.length">
              <span *ngFor="let t of a.tags.slice(0,3)" class="tag-chip">{{ t }}</span>
            </div>
            <div class="ac-difficulty">
              <span class="diff-dot" [attr.data-diff]="a.difficulty"></span> {{ a.difficulty }}
            </div>
          </div>
          <div class="ac-footer">
            <div class="ac-status" *ngIf="a.enrollment_status!=='not_enrolled'" [attr.data-status]="a.enrollment_status">
              {{ statusLabel(a.enrollment_status) }}
              <span *ngIf="a.best_score!==null"> · {{ a.best_score }}%</span>
            </div>
            <div class="ac-prereqs" *ngIf="hasUnmetPrerequisites(a)">
              <tui-icon icon="@tui.lock" style="font-size:12px"></tui-icon>
              Prerequisites required
            </div>
            <button
              class="btn btn-primary btn-sm ac-btn"
              [disabled]="!arePrerequisitesMet(a)">
              {{ getActionLabel(a) }}
            </button>

          </div>
        </div>
      </div>

      <!-- List View -->
      <div class="catalog-list" *ngIf="!loading && viewMode==='list'">
        <div *ngFor="let a of filteredAssessments" class="list-item" (click)="openAssessment(a)">
          <div class="li-type"><span class="type-badge" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span></div>
          <div class="li-info">
            <h4>{{ a.title }}</h4>
            <span class="li-cat">{{ a.category_name }}</span>
          </div>
          <div class="li-meta">
            <span>{{ a.total_questions }}q · {{ a.time_limit_minutes || a.estimated_duration_minutes }}m</span>
            <span class="diff-dot" [attr.data-diff]="a.difficulty"></span>
          </div>
          <div class="li-proctoring" *ngIf="a.proctoring_level!=='none'">
            <tui-icon icon="@tui.shield" style="font-size:14px;color:#6366f1"></tui-icon>
          </div>
          <div class="li-status" *ngIf="a.enrollment_status!=='not_enrolled'" [attr.data-status]="a.enrollment_status">
            {{ statusLabel(a.enrollment_status) }}
          </div>
          <button class="btn btn-primary btn-sm">{{ getActionLabel(a) }}</button>
        </div>
      </div>

      <!-- Empty State -->
      <div class="empty-state" *ngIf="!loading && filteredAssessments.length===0">
        <tui-icon icon="@tui.search" style="font-size:48px;color:#94a3b8"></tui-icon>
        <h3>No assessments found</h3>
        <p>Try adjusting your filters or search query.</p>
        <button class="btn btn-outline" (click)="clearFilters()">Clear Filters</button>
      </div>

      <!-- Pagination -->
      <div class="pagination" *ngIf="!loading && totalPages > 1">
        <button class="pg-btn" (click)="goToPage(currentPage-1)" [disabled]="currentPage===1">
          <tui-icon icon="@tui.chevron-left" style="font-size:16px"></tui-icon></button>
        <span class="pg-info">{{ currentPage }} / {{ totalPages }}</span>
        <button class="pg-btn" (click)="goToPage(currentPage+1)" [disabled]="currentPage===totalPages">
          <tui-icon icon="@tui.chevron-right" style="font-size:16px"></tui-icon></button>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    :host { display: block; }
    .catalog-container { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; margin: 0 auto;}
    .catalog-hero { background: linear-gradient(135deg, #303234, #696969); border-radius: 20px; padding: 40px 32px; margin: 0 -24px 24px; }
    .hero-content { max-width: 600px; }
    .hero-content h1 { font-size: 28px; font-weight: 800; color: #fff; margin: 0 0 8px; }
    .hero-content p { color: rgba(255,255,255,0.8); margin: 0 0 20px; font-size: 15px; }
    .hero-search { display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.15); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.25); border-radius: 12px; padding: 10px 16px; }
    .hero-input { flex: 1; background: none; border: none; outline: none; font-size: 16px; color: #fff; }
    .hero-input::placeholder { color: rgba(255,255,255,0.5); }

    .filter-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
    .filter-tabs { display: flex; gap: 4px; }
    .ftab { padding: 7px 14px; border-radius: 8px; border: none; background: #f1f5f9; color: #64748b; cursor: pointer; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 4px; }
    .ftab:hover { background: #e2e8f0; } .ftab.active { background: #6366f1; color: #fff; }
    .filter-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .fc { padding: 7px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; background: #fff; }
    .view-toggle { display: flex; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    .vt { padding: 6px 10px; border: none; background: #fff; cursor: pointer; color: #64748b; }
    .vt.active { background: #6366f1; color: #fff; }

    .mandatory-section { margin-bottom: 24px; }
    .mandatory-section h2 { font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 12px; display: flex; align-items: center; gap: 8px; }
    .mandatory-cards { display: flex; flex-direction: column; gap: 8px; }
    .mandatory-card { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; cursor: pointer; }
    .mandatory-card:hover { background: #fef3c7; }
    .mc-left { display: flex; align-items: center; gap: 12px; }
    .mc-left h4 { margin: 0; font-size: 14px; color: #0f172a; }
    .mc-due { font-size: 12px; color: #92400e; }
    .mc-right { display: flex; align-items: center; gap: 12px; }
    .mc-status { font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 4px; }

    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
    .btn-sm { padding: 5px 12px; font-size: 13px; }

    .loading { display: flex; justify-content: center; padding: 60px 0; }
    .spinner { width: 36px; height: 36px; border: 3px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .catalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    .assess-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; }
    .assess-card:hover { border-color: #a5b4fc; box-shadow: 0 4px 16px rgba(99,102,241,0.1); transform: translateY(-2px); }
    .ac-thumb { height: 120px; background: linear-gradient(135deg, #eef2ff, #f5f3ff); position: relative; display: flex; align-items: flex-start; justify-content: space-between; padding: 10px; background-size: cover; background-position: center; }
    .ac-type { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
    .ac-type[data-type="quiz"] { background: #dbeafe; color: #1e40af; }
    .ac-type[data-type="exam"] { background: #fef2f2; color: #991b1b; }
    .ac-type[data-type="practice"] { background: #d1fae5; color: #065f46; }
    .ac-type[data-type="survey"] { background: #fef3c7; color: #92400e; }
    .type-badge { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
    .type-badge[data-type="quiz"] { background: #dbeafe; color: #1e40af; }
    .type-badge[data-type="exam"] { background: #fef2f2; color: #991b1b; }
    .type-badge[data-type="practice"] { background: #d1fae5; color: #065f46; }
    .type-badge[data-type="survey"] { background: #fef3c7; color: #92400e; }
    .ac-proctored { padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; background: rgba(99,102,241,0.15); color: #4f46e5; display: flex; align-items: center; gap: 3px; }
    .ac-body { padding: 14px 16px 0; flex: 1; }
    .ac-category { font-size: 11px; font-weight: 600; color: #6366f1; text-transform: uppercase; margin-bottom: 4px; }
    .ac-title { font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 6px; line-height: 1.3; }
    .ac-desc { font-size: 13px; color: #64748b; margin: 0 0 10px; line-height: 1.4; }
    .ac-meta { display: flex; gap: 12px; font-size: 12px; color: #64748b; margin-bottom: 8px; }
    .ac-meta span { display: flex; align-items: center; gap: 3px; }
    .ac-tags { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 8px; }
    .tag-chip { padding: 2px 8px; border-radius: 20px; font-size: 11px; background: #f1f5f9; color: #475569; }
    .ac-difficulty { font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px; text-transform: capitalize; margin-bottom: 8px; }
    .diff-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .diff-dot[data-diff="beginner"] { background: #22c55e; }
    .diff-dot[data-diff="intermediate"] { background: #f59e0b; }
    .diff-dot[data-diff="advanced"] { background: #ef4444; }
    .ac-footer { padding: 12px 16px; border-top: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px; }
    .ac-status { font-size: 12px; font-weight: 600; flex: 1; }
    .ac-status[data-status="enrolled"] { color: #6366f1; }
    .ac-status[data-status="in_progress"] { color: #f59e0b; }
    .ac-status[data-status="completed"],
    .ac-status[data-status="passed"] { color: #22c55e; }
    .ac-status[data-status="failed"] { color: #ef4444; }
    .ac-prereqs { font-size: 11px; color: #94a3b8; display: flex; align-items: center; gap: 4px; }
    .ac-btn { margin-left: auto; }

    .catalog-list { display: flex; flex-direction: column; gap: 8px; }
    .list-item { display: flex; align-items: center; gap: 14px; padding: 14px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.15s; }
    .list-item:hover { border-color: #a5b4fc; }
    .li-info { flex: 1; min-width: 0; }
    .li-info h4 { margin: 0; font-size: 14px; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .li-cat { font-size: 12px; color: #64748b; }
    .li-meta { font-size: 12px; color: #94a3b8; display: flex; align-items: center; gap: 6px; }
    .li-status { font-size: 12px; font-weight: 600; }
    .li-status[data-status="in_progress"] { color: #f59e0b; }
    .li-status[data-status="completed"], .li-status[data-status="passed"] { color: #22c55e; }
    .li-status[data-status="failed"] { color: #ef4444; }

    .empty-state { text-align: center; padding: 60px 20px; }
    .empty-state h3 { margin: 16px 0 8px; color: #0f172a; }
    .empty-state p { color: #64748b; margin-bottom: 16px; }

    .pagination { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 28px; }
    .pg-btn { padding: 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; cursor: pointer; }
    .pg-btn:hover:not(:disabled) { border-color: #6366f1; }
    .pg-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .pg-info { font-size: 14px; color: #64748b; }

    @media (max-width: 768px) {
      .catalog-hero { padding: 24px 20px; }
      .filter-bar { flex-direction: column; align-items: stretch; }
      .catalog-grid { grid-template-columns: 1fr; }
    }
  `]
})
export class AssessmentCatalogComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private router = inject(Router);
  private destroy$ = new Subject<void>();
  private apiUrl = GlobalComponent.baseURL + '/user/assessment.php';
  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };
  assessments: CatalogAssessment[] = [];
  filteredAssessments: CatalogAssessment[] = [];
  categories: Category[] = [];
  loading = true;

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
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    this.loadCatalog();
    this.loadCategories();
    this.search$.pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$)).subscribe(() => this.applyFilters());
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  loadCatalog(): void {
    const body: any = { action: 'get-catalog', token: this.user_session.token, user_id: this.user_session.id, tenant_id: this.user_session.tenant_id };
    this.http.post<ApiResponse<CatalogAssessment[]>>(this.apiUrl, body).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.loading = false; if (r.status === 'success') { this.assessments = r.data || []; this.applyFilters(); } },
      error: () => { this.loading = false; }
    });
  }

  loadCategories(): void {
    const body: any = { action: 'get-categories', token: this.user_session.token, user_id: this.user_session.id, tenant_id: this.user_session.tenant_id };
    this.http.post<ApiResponse<Category[]>>(this.apiUrl, body).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { if (r.status === 'success') this.categories = r.data || []; }
    });
  }

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
    if (this.filterCategory) result = result.filter(a => a.category_id === +this.filterCategory);
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
