// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 15
// Learner: Assessment Dashboard Component
// Multi-Tenant LMS | Angular Standalone
// Personalized dashboard: stats, progress, upcoming, recent
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, forkJoin, of, takeUntil } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { TopComponent } from '../../partials/top/top.component';
import { AssessmentsService, type AttemptResult, type LearnerAssessment } from '../../services/assessments.service';
import { CertificatesService } from '../../services/certificates.service';
import { GamificationService } from '../../services/gamification.service';

interface LearnerStats {
  total_assessments: number; completed: number; passed: number; failed: number;
  in_progress: number; avg_score: number; total_points_earned: number;
  certificates_earned: number; streak_days: number; ranking: number;
  total_learners: number; completion_rate: number;
}

interface ActiveAssessment {
  assessment_id: string; title: string; assessment_type: string;
  attempt_id: string; progress_percent: number; questions_answered: number;
  total_questions: number; time_remaining_seconds: number|null;
  started_at: string; proctoring_level: string;
}

interface UpcomingAssessment {
  assessment_id: string; title: string; assessment_type: string;
  due_date: string|null; is_mandatory: boolean; time_limit_minutes: number;
  total_questions: number; proctoring_level: string; category_name: string;
}

interface RecentResult {
  attempt_id: string; assessment_id: string; title: string;
  assessment_type: string; completed_at: string; score: number;
  max_score: number; percentage: number; passed: boolean;
  grade: string; certificate_id: string|null;
}

interface SkillProgress { name: string; score: number; max: number; percentage: number; assessments_taken: number; }

@Component({
  selector: 'app-learner-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent],
  template: `
    <app-top></app-top>
    <main class="main">
    <div class="ui-page dashboard-container">
      <!-- Welcome Header -->
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-clipboard-data"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ greeting }}</h1>
          <p>Track your assessment progress and performance.</p>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-primary" (click)="router.navigate(['/learner/assessments/catalog'])">
            <i class="bi bi-search"></i> Browse Catalog
          </button>
        </div>
      </header>

      <!-- Stats Cards -->
      <div class="ui-kpi-grid ui-kpi-grid--5" *ngIf="stats">
        <button type="button" class="ui-kpi ui-kpi--brand" (click)="router.navigate(['/learner/assessments/history'])">
          <span class="ui-kpi__label"><i class="bi bi-file-earmark-check"></i> Completed</span>
          <span class="ui-kpi__value">{{ stats.completed }}</span>
        </button>
        <div class="ui-kpi ui-kpi--success">
          <span class="ui-kpi__label"><i class="bi bi-check-circle"></i> Passed</span>
          <span class="ui-kpi__value">{{ stats.passed }}</span>
        </div>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-graph-up"></i> Avg Score</span>
          <span class="ui-kpi__value">{{ stats.avg_score | number:'1.0-0' }}<small>%</small></span>
          <div class="ui-progress"><div class="ui-bar"><span [style.width.%]="stats.avg_score"></span></div></div>
        </div>
        <div class="ui-kpi ui-kpi--warning">
          <span class="ui-kpi__label"><i class="bi bi-award"></i> Certificates</span>
          <span class="ui-kpi__value">{{ stats.certificates_earned }}</span>
        </div>
        <div class="ui-kpi ui-kpi--brand">
          <span class="ui-kpi__label"><i class="bi bi-star"></i> Points</span>
          <span class="ui-kpi__value">{{ stats.total_points_earned }}</span>
        </div>
      </div>

      <!-- Completion Ring + Rank -->
      <div class="overview-row" *ngIf="stats">
        <section class="ui-section ring-card">
          <div class="ring-wrapper">
            <svg viewBox="0 0 120 120" class="ring-svg">
              <circle cx="60" cy="60" r="52" fill="none" class="ring-track" stroke-width="10"/>
              <circle cx="60" cy="60" r="52" fill="none" class="ring-fill" stroke-width="10"
                      stroke-linecap="round" [attr.stroke-dasharray]="ringDash"
                      [attr.stroke-dashoffset]="ringOffset" transform="rotate(-90 60 60)"/>
            </svg>
            <div class="ring-center">
              <span class="ring-val">{{ stats.completion_rate | number:'1.0-0' }}%</span>
              <span class="ring-lbl">Complete</span>
            </div>
          </div>
          <div class="ring-details">
            <span class="ui-pill ui-pill--success"><span class="ui-pill__dot"></span> Passed: {{ stats.passed }}</span>
            <span class="ui-pill ui-pill--danger"><span class="ui-pill__dot"></span> Failed: {{ stats.failed }}</span>
            <span class="ui-pill ui-pill--warning"><span class="ui-pill__dot"></span> In Progress: {{ stats.in_progress }}</span>
          </div>
        </section>
        <section class="ui-section rank-card" *ngIf="stats.ranking">
          <div class="rank-badge">
            <span class="ui-state__icon"><i class="bi bi-trophy"></i></span>
            <span class="rank-num">#{{ stats.ranking }}</span>
          </div>
          <p>of {{ stats.total_learners }} learners</p>
          <span class="ui-pill ui-pill--warning streak-row" *ngIf="stats.streak_days">
            <i class="bi bi-lightning-charge"></i>
            {{ stats.streak_days }}-day streak
          </span>
        </section>
      </div>

      <div class="ui-row">
        <!-- Active / In-Progress -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">In Progress</h2><p class="ui-section__subtitle">Pick up where you left off</p></div>
          </div>
          <div class="active-list" *ngIf="activeAssessments.length > 0">
            <div *ngFor="let a of activeAssessments" class="active-card" (click)="resumeAttempt(a)">
              <div class="act-top">
                <span class="ui-pill ui-pill--brand type-badge" *ngIf="a.assessment_type" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
                <span class="act-proctored" *ngIf="a.proctoring_level && a.proctoring_level!=='none'" title="Proctored">
                  <i class="bi bi-shield-check"></i></span>
              </div>
              <h4>{{ a.title }}</h4>
              <div class="act-progress">
                <div class="ui-progress">
                  <div class="ui-bar"><span [style.width.%]="a.progress_percent"></span></div>
                </div>
                <span>{{ a.questions_answered }}/{{ a.total_questions }} · {{ a.progress_percent }}%</span>
              </div>
              <div class="act-footer">
                <span class="act-time" *ngIf="a.time_remaining_seconds">
                  <i class="bi bi-clock"></i> {{ formatDuration(a.time_remaining_seconds) }} left
                </span>
                <button type="button" class="btn btn-primary btn-sm ms-auto">Resume</button>
              </div>
            </div>
          </div>
          <div class="ui-state ui-state--compact" *ngIf="activeAssessments.length===0">
            <span class="ui-state__icon"><i class="bi bi-check2-circle"></i></span>
            <p>No assessments in progress</p>
          </div>
        </section>

        <!-- Upcoming / Assigned -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Upcoming</h2><p class="ui-section__subtitle">Assessments assigned to you</p></div>
          </div>
          <ul class="ui-list upcoming-list" *ngIf="upcomingAssessments.length > 0">
            <li *ngFor="let a of upcomingAssessments" class="upcoming-item" (click)="openAssessment(a.assessment_id)">
              <div class="upc-left">
                <span class="ui-pill ui-pill--brand type-badge" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
                <div class="upc-info">
                  <h4>{{ a.title }}</h4>
                  <span class="upc-cat">{{ a.category_name }}</span>
                </div>
              </div>
              <div class="upc-right">
                <span class="ui-pill ui-pill--danger" *ngIf="a.is_mandatory" title="Mandatory"><i class="bi bi-exclamation-circle"></i></span>
                <span class="ui-pill ui-pill--warning" *ngIf="a.due_date">Due {{ formatDate(a.due_date) }}</span>
                <span class="upc-meta">{{ a.total_questions }}q · {{ a.time_limit_minutes }}m</span>
              </div>
            </li>
          </ul>
          <div class="ui-state ui-state--compact" *ngIf="upcomingAssessments.length===0">
            <span class="ui-state__icon"><i class="bi bi-inbox"></i></span>
            <p>No upcoming assessments</p>
          </div>
        </section>
      </div>

      <!-- Recent Results -->
      <section class="ui-section">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title">Recent Results</h2><p class="ui-section__subtitle">Your latest scored attempts</p></div>
          <div class="ui-section__actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="router.navigate(['/learner/assessments/history'])">View All</button>
          </div>
        </div>
        <div class="ui-grid results-grid" *ngIf="recentResults.length > 0">
          <article *ngFor="let r of recentResults" class="ui-card result-card" (click)="viewResult(r)">
            <div class="ui-card__body">
              <div class="rc-top">
                <span class="ui-pill ui-pill--brand type-badge" *ngIf="r.assessment_type" [attr.data-type]="r.assessment_type">{{ r.assessment_type }}</span>
                <span class="rc-date">{{ formatDate(r.completed_at) }}</span>
              </div>
              <h4 class="ui-card__title">{{ r.title }}</h4>
              <div class="rc-score">
                <div class="score-ring-sm" [class.pass]="r.passed" [class.fail]="!r.passed">
                  <span>{{ r.percentage | number:'1.0-0' }}%</span>
                </div>
                <div class="rc-grade">
                  <span class="grade-letter" [class.pass]="r.passed" [class.fail]="!r.passed">{{ r.grade }}</span>
                  <span class="rc-detail">{{ r.score }}/{{ r.max_score }}</span>
                </div>
              </div>
            </div>
            <div class="ui-card__footer">
              <span class="ui-pill" [class.ui-pill--success]="r.passed" [class.ui-pill--danger]="!r.passed"><span class="ui-pill__dot"></span>{{ r.passed ? 'Passed' : 'Failed' }}</span>
              <i *ngIf="r.certificate_id" class="bi bi-award cert-icon" title="Certificate earned"></i>
            </div>
          </article>
        </div>
        <div class="ui-state ui-state--compact" *ngIf="recentResults.length===0">
          <span class="ui-state__icon"><i class="bi bi-clipboard"></i></span>
          <p>No results yet. Start an assessment to see your performance here.</p>
        </div>
      </section>

      <!-- Skills Progress -->
      <section class="ui-section" *ngIf="skillProgress.length > 0">
        <div class="ui-section__head"><div><h2 class="ui-section__title">Skill Progress</h2></div></div>
        <div class="skills-grid">
          <div *ngFor="let s of skillProgress" class="skill-bar-row">
            <span class="sk-name">{{ s.name }}</span>
            <div class="ui-bar sk-bar"><span class="sk-fill" [style.width.%]="s.percentage" [class.high]="s.percentage>=80" [class.mid]="s.percentage>=50 && s.percentage<80" [class.low]="s.percentage<50"></span></div>
            <span class="ui-pct sk-val">{{ s.percentage | number:'1.0-0' }}%</span>
          </div>
        </div>
      </section>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .ui-kpi-grid { margin-bottom: 20px; }
    .ui-state--compact { padding: 28px 16px; }
    .ui-state--compact p { margin: 10px 0 0; }

    .overview-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px; }
    .overview-row > .ui-section { margin-bottom: 0; }
    .ring-card { display: flex; align-items: center; gap: 28px; }
    .ring-wrapper { position: relative; width: 120px; height: 120px; flex-shrink: 0; }
    .ring-svg { width: 100%; height: 100%; }
    .ring-track { stroke: var(--ui-surface-2); }
    .ring-fill { stroke: var(--brand-primary); transition: stroke-dashoffset .5s ease; }
    .ring-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .ring-val { font-size: 1.5rem; font-weight: 700; color: var(--ui-text); font-variant-numeric: tabular-nums; }
    .ring-lbl { font-size: .6875rem; color: var(--ui-text-muted); }
    .ring-details { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
    .rank-card { text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .rank-badge { display: flex; align-items: center; gap: 12px; }
    .rank-num { font-size: 1.75rem; font-weight: 700; color: var(--ui-text); font-variant-numeric: tabular-nums; }
    .rank-card p { color: var(--ui-text-muted); font-size: .875rem; margin: 6px 0 0; }
    .streak-row { margin-top: 10px; }

    .type-badge { text-transform: uppercase; letter-spacing: .03em; font-size: .6875rem; height: 22px; }

    .active-list { display: flex; flex-direction: column; gap: 10px; }
    .active-card { padding: 14px 16px; border: 1px solid var(--ui-border); border-radius: var(--ui-radius-card); cursor: pointer; transition: border-color .15s ease; }
    .active-card:hover { border-color: rgba(var(--brand-primary-rgb), .45); }
    .act-top { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
    .act-top:empty { display: none; }
    .act-proctored { color: var(--brand-primary); }
    .active-card h4 { margin: 0 0 8px; font-size: .875rem; font-weight: 600; color: var(--ui-text); }
    .act-progress { margin-bottom: 10px; }
    .act-progress .ui-progress { margin-bottom: 4px; }
    .act-progress > span { font-size: .75rem; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; }
    .act-footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .act-time { font-size: .75rem; color: var(--ui-warning-text); display: flex; align-items: center; gap: 4px; }

    .upcoming-item { justify-content: space-between; cursor: pointer; }
    .upcoming-item:hover h4 { color: var(--brand-primary); }
    .upc-left { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
    .upc-info { min-width: 0; }
    .upc-info h4 { margin: 0; font-size: .875rem; font-weight: 600; color: var(--ui-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: color .15s ease; }
    .upc-cat { font-size: .75rem; color: var(--ui-text-muted); }
    .upc-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .upc-meta { font-size: .75rem; color: var(--ui-text-subtle); }

    .result-card { cursor: pointer; }
    .rc-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .rc-date { font-size: .75rem; color: var(--ui-text-subtle); margin-left: auto; }
    .rc-score { display: flex; align-items: center; gap: 12px; }
    .score-ring-sm { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .875rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .score-ring-sm.pass { background: rgba(var(--ui-success-rgb), .08); color: var(--ui-success-text); border: 3px solid var(--ui-success); }
    .score-ring-sm.fail { background: rgba(var(--ui-danger-rgb), .08); color: var(--ui-danger-text); border: 3px solid var(--ui-danger); }
    .grade-letter { font-size: 1.25rem; font-weight: 700; display: block; }
    .grade-letter.pass { color: var(--ui-success); } .grade-letter.fail { color: var(--ui-danger); }
    .rc-detail { font-size: .75rem; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; }
    .cert-icon { font-size: 1rem; color: var(--brand-primary); }

    .skills-grid { display: flex; flex-direction: column; gap: 12px; }
    .skill-bar-row { display: flex; align-items: center; gap: 12px; }
    .sk-name { font-size: .8125rem; font-weight: 550; color: var(--ui-text); width: 140px; flex-shrink: 0; }
    .ui-bar > .sk-fill.high { background: var(--ui-success); } .ui-bar > .sk-fill.mid { background: var(--ui-warning); } .ui-bar > .sk-fill.low { background: var(--ui-danger); }

    @media (max-width: 767.98px) {
      .overview-row { grid-template-columns: 1fr; }
      .ring-card { flex-direction: column; align-items: flex-start; }
    }
  `]
})
export class LearnerDashboardComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private certificates = inject(CertificatesService);
  private gamification = inject(GamificationService);
  router = inject(Router);
  private destroy$ = new Subject<void>();

  stats: LearnerStats|null = null;
  activeAssessments: ActiveAssessment[] = [];
  upcomingAssessments: UpcomingAssessment[] = [];
  recentResults: RecentResult[] = [];
  skillProgress: SkillProgress[] = [];
  readonly CIRCUMFERENCE = 2 * Math.PI * 52;
  get ringDash(): string { return this.CIRCUMFERENCE.toString(); }
  get ringOffset(): string { return (this.CIRCUMFERENCE * (1 - (this.stats?.completion_rate || 0) / 100)).toString(); }

  get greeting(): string {
    const h = new Date().getHours();
    const g = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    return g + '!';
  }

  ngOnInit(): void {
    this.loadDashboard();
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  /**
   * There is no single learner-dashboard endpoint on the new API, so the view
   * is assembled: the attempt stats and in-progress/recent lists from the
   * caller's own attempts, points and rank from gamification, and the
   * certificate count from their certificates. What has no learner-facing
   * source -- an assigned/upcoming list, live in-progress fields, per-skill
   * assessment scores -- stays empty rather than guessed at.
   */
  loadDashboard(): void {
    forkJoin({
      attempts: this.assessments.myAttempts(200).pipe(catchError(() => of([] as AttemptResult[]))),
      certs: this.certificates.mine().pipe(catchError(() => of([]))),
      game: this.gamification.mine().pipe(catchError(() => of(null))),
      board: this.gamification.leaderboard('all_time', 100).pipe(catchError(() => of(null))),
      catalogue: this.assessments.catalogue({ assigned: true }).pipe(catchError(() => of([] as LearnerAssessment[]))),
    }).pipe(takeUntil(this.destroy$)).subscribe(({ attempts, certs, game, board, catalogue }) => {
      const completed = attempts.filter(a => this.isScored(a));
      const passed = completed.filter(a => a.passed === true).length;
      const failed = completed.filter(a => a.passed === false).length;
      const inProgress = attempts.filter(a => a.status === 'in_progress');
      const scores = completed.map(a => a.percentage ?? 0);

      this.stats = {
        total_assessments: attempts.length,
        completed: completed.length,
        passed, failed,
        in_progress: inProgress.length,
        avg_score: scores.length ? Math.round(scores.reduce((x, y) => x + y, 0) / scores.length) : 0,
        total_points_earned: game?.points ?? 0,
        certificates_earned: certs.length,
        streak_days: 0,
        ranking: game?.rank?.rank ?? 0,
        total_learners: board?.entries.length ?? 0,
        // No assigned/available denominator, so completion is of what was sat.
        completion_rate: attempts.length ? Math.round((completed.length / attempts.length) * 100) : 0,
      };

      this.activeAssessments = inProgress.map(a => ({
        assessment_id: a.assessmentId, title: a.assessmentTitle ?? 'Assessment',
        assessment_type: '', attempt_id: a.id,
        progress_percent: 0, questions_answered: 0, total_questions: 0,
        time_remaining_seconds: null, started_at: a.startedAt ?? '', proctoring_level: '',
      }));

      this.recentResults = completed
        .sort((a, b) => new Date(b.submittedAt ?? 0).getTime() - new Date(a.submittedAt ?? 0).getTime())
        .slice(0, 6)
        .map(a => ({
          attempt_id: a.id, assessment_id: a.assessmentId, title: a.assessmentTitle ?? 'Assessment',
          assessment_type: '', completed_at: a.submittedAt ?? '',
          score: a.rawScore ?? 0, max_score: a.maxScore ?? 0, percentage: a.percentage ?? 0,
          passed: a.passed === true, grade: '', certificate_id: null,
        }));

      // Assigned assessments the learner has not yet passed/completed, soonest
      // due first (nulls last).
      this.upcomingAssessments = catalogue
        .filter(a => a.enrollmentStatus !== 'passed' && a.enrollmentStatus !== 'completed')
        .sort((a, b) => {
          if (!a.dueDate) return b.dueDate ? 1 : 0;
          if (!b.dueDate) return -1;
          return new Date(a.dueDate).getTime() - new Date(b.dueDate).getTime();
        })
        .map(a => ({
          assessment_id: a.assessmentId, title: a.title, assessment_type: a.kind,
          due_date: a.dueDate, is_mandatory: a.isMandatory, time_limit_minutes: a.timeLimitMinutes ?? 0,
          total_questions: a.questionCount, proctoring_level: a.proctoringLevel, category_name: a.categoryName ?? '',
        }));
      this.skillProgress = [];
    });
  }

  private isScored(a: AttemptResult): boolean {
    return a.status === 'graded' || a.status === 'auto_submitted';
  }

  resumeAttempt(a: ActiveAssessment): void {
    this.router.navigate(['/learner/assessments', a.assessment_id, 'attempt', a.attempt_id]);
  }

  openAssessment(id: string): void {
    this.router.navigate(['/learner/assessments', id, 'lobby']);
  }

  viewResult(r: RecentResult): void {
    this.router.navigate(['/learner/assessments', r.assessment_id, 'results', r.attempt_id]);
  }

  formatDate(d: string|null): string {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  formatDuration(sec: number): string {
    const m = Math.floor(sec / 60); const s = sec % 60;
    if (m >= 60) return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    return m + ':' + String(s).padStart(2, '0');
  }
}
