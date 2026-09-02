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
import { AssessmentsService, type AttemptResult } from '../../services/assessments.service';
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
            <div class="container-fluid py-4">
                <div class="dashboard-container">
      <!-- Welcome Header -->
      <div class="dash-header">
        <div class="welcome">
          <h1>{{ greeting }}</h1>
          <p>Track your assessment progress and performance.</p>
        </div>
        <div class="header-actions">
          <button class="btn btn-primary" (click)="router.navigate(['/learner/assessments/catalog'])">
            <tui-icon icon="@tui.search" style="font-size:16px"></tui-icon> Browse Catalog
          </button>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-row" *ngIf="stats">
        <div class="stat-card" (click)="router.navigate(['/learner/assessments/history'])">
          <div class="sc-icon blue"><tui-icon icon="@tui.file-text" style="font-size:22px"></tui-icon></div>
          <div class="sc-data"><span class="sc-val">{{ stats.completed }}</span><span class="sc-label">Completed</span></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon green"><tui-icon icon="@tui.check-circle" style="font-size:22px"></tui-icon></div>
          <div class="sc-data"><span class="sc-val">{{ stats.passed }}</span><span class="sc-label">Passed</span></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon purple"><tui-icon icon="@tui.trending-up" style="font-size:22px"></tui-icon></div>
          <div class="sc-data"><span class="sc-val">{{ stats.avg_score | number:'1.0-0' }}%</span><span class="sc-label">Avg Score</span></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon amber"><tui-icon icon="@tui.award" style="font-size:22px"></tui-icon></div>
          <div class="sc-data"><span class="sc-val">{{ stats.certificates_earned }}</span><span class="sc-label">Certificates</span></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon teal"><tui-icon icon="@tui.star" style="font-size:22px"></tui-icon></div>
          <div class="sc-data"><span class="sc-val">{{ stats.total_points_earned }}</span><span class="sc-label">Points</span></div>
        </div>
      </div>

      <!-- Completion Ring + Rank -->
      <div class="overview-row" *ngIf="stats">
        <div class="ring-card">
          <div class="ring-wrapper">
            <svg viewBox="0 0 120 120" class="ring-svg">
              <circle cx="60" cy="60" r="52" fill="none" stroke="#e2e8f0" stroke-width="10"/>
              <circle cx="60" cy="60" r="52" fill="none" stroke="#6366f1" stroke-width="10"
                      stroke-linecap="round" [attr.stroke-dasharray]="ringDash"
                      [attr.stroke-dashoffset]="ringOffset" transform="rotate(-90 60 60)"/>
            </svg>
            <div class="ring-center">
              <span class="ring-val">{{ stats.completion_rate | number:'1.0-0' }}%</span>
              <span class="ring-lbl">Complete</span>
            </div>
          </div>
          <div class="ring-details">
            <div class="rd-item"><span class="rd-dot pass"></span> Passed: {{ stats.passed }}</div>
            <div class="rd-item"><span class="rd-dot fail"></span> Failed: {{ stats.failed }}</div>
            <div class="rd-item"><span class="rd-dot prog"></span> In Progress: {{ stats.in_progress }}</div>
          </div>
        </div>
        <div class="rank-card" *ngIf="stats.ranking">
          <div class="rank-badge">
            <tui-icon icon="@tui.trophy" style="font-size:28px;color:#f59e0b"></tui-icon>
            <span class="rank-num">#{{ stats.ranking }}</span>
          </div>
          <p>of {{ stats.total_learners }} learners</p>
          <div class="streak-row" *ngIf="stats.streak_days">
            <tui-icon icon="@tui.zap" style="font-size:16px;color:#f59e0b"></tui-icon>
            {{ stats.streak_days }}-day streak
          </div>
        </div>
      </div>

      <div class="two-col">
        <!-- Active / In-Progress -->
        <div class="dash-section">
          <div class="ds-header">
            <h2><tui-icon icon="@tui.circle-play" style="font-size:20px;color:#f59e0b"></tui-icon> In Progress</h2>
          </div>
          <div class="active-list" *ngIf="activeAssessments.length > 0">
            <div *ngFor="let a of activeAssessments" class="active-card" (click)="resumeAttempt(a)">
              <div class="act-top">
                <span class="type-badge" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
                <span class="act-proctored" *ngIf="a.proctoring_level!=='none'">
                  <tui-icon icon="@tui.shield" style="font-size:12px"></tui-icon></span>
              </div>
              <h4>{{ a.title }}</h4>
              <div class="act-progress">
                <div class="prog-bar"><div class="prog-fill" [style.width.%]="a.progress_percent"></div></div>
                <span>{{ a.questions_answered }}/{{ a.total_questions }} · {{ a.progress_percent }}%</span>
              </div>
              <div class="act-footer">
                <span class="act-time" *ngIf="a.time_remaining_seconds">
                  <tui-icon icon="@tui.clock" style="font-size:13px"></tui-icon> {{ formatDuration(a.time_remaining_seconds) }} left
                </span>
                <button class="btn btn-primary btn-sm">Resume</button>
              </div>
            </div>
          </div>
          <div class="empty-mini" *ngIf="activeAssessments.length===0">
            <tui-icon icon="@tui.check-circle" style="font-size:24px;color:#22c55e"></tui-icon>
            <p>No assessments in progress</p>
          </div>
        </div>

        <!-- Upcoming / Assigned -->
        <div class="dash-section">
          <div class="ds-header">
            <h2><tui-icon icon="@tui.calendar" style="font-size:20px;color:#6366f1"></tui-icon> Upcoming</h2>
          </div>
          <div class="upcoming-list" *ngIf="upcomingAssessments.length > 0">
            <div *ngFor="let a of upcomingAssessments" class="upcoming-item" (click)="openAssessment(a.assessment_id)">
              <div class="upc-left">
                <span class="type-badge" [attr.data-type]="a.assessment_type">{{ a.assessment_type }}</span>
                <div class="upc-info">
                  <h4>{{ a.title }}</h4>
                  <span class="upc-cat">{{ a.category_name }}</span>
                </div>
              </div>
              <div class="upc-right">
                <span class="upc-mandatory" *ngIf="a.is_mandatory"><tui-icon icon="@tui.alert-circle" style="font-size:14px;color:#ef4444"></tui-icon></span>
                <span class="upc-due" *ngIf="a.due_date">Due {{ formatDate(a.due_date) }}</span>
                <span class="upc-meta">{{ a.total_questions }}q · {{ a.time_limit_minutes }}m</span>
              </div>
            </div>
          </div>
          <div class="empty-mini" *ngIf="upcomingAssessments.length===0">
            <tui-icon icon="@tui.inbox" style="font-size:24px;color:#94a3b8"></tui-icon>
            <p>No upcoming assessments</p>
          </div>
        </div>
      </div>

      <!-- Recent Results -->
      <div class="dash-section full-width">
        <div class="ds-header">
          <h2><tui-icon icon="@tui.bar-chart-2" style="font-size:20px;color:#22c55e"></tui-icon> Recent Results</h2>
          <button class="btn btn-ghost btn-sm" (click)="router.navigate(['/learner/assessments/history'])">View All</button>
        </div>
        <div class="results-grid" *ngIf="recentResults.length > 0">
          <div *ngFor="let r of recentResults" class="result-card" (click)="viewResult(r)">
            <div class="rc-top">
              <span class="type-badge" [attr.data-type]="r.assessment_type">{{ r.assessment_type }}</span>
              <span class="rc-date">{{ formatDate(r.completed_at) }}</span>
            </div>
            <h4>{{ r.title }}</h4>
            <div class="rc-score">
              <div class="score-ring-sm" [class.pass]="r.passed" [class.fail]="!r.passed">
                <span>{{ r.percentage | number:'1.0-0' }}%</span>
              </div>
              <div class="rc-grade">
                <span class="grade-letter" [class.pass]="r.passed" [class.fail]="!r.passed">{{ r.grade }}</span>
                <span class="rc-detail">{{ r.score }}/{{ r.max_score }}</span>
              </div>
            </div>
            <div class="rc-footer">
              <span class="rc-status" [class.pass]="r.passed" [class.fail]="!r.passed">{{ r.passed ? 'Passed' : 'Failed' }}</span>
              <tui-icon *ngIf="r.certificate_id" icon="@tui.award" style="font-size:16px;color:#f59e0b" title="Certificate earned"></tui-icon>
            </div>
          </div>
        </div>
        <div class="empty-mini" *ngIf="recentResults.length===0">
          <tui-icon icon="@tui.clipboard" style="font-size:24px;color:#94a3b8"></tui-icon>
          <p>No results yet. Start an assessment to see your performance here.</p>
        </div>
      </div>

      <!-- Skills Progress -->
      <div class="dash-section full-width" *ngIf="skillProgress.length > 0">
        <div class="ds-header"><h2><tui-icon icon="@tui.target" style="font-size:20px;color:#8b5cf6"></tui-icon> Skill Progress</h2></div>
        <div class="skills-grid">
          <div *ngFor="let s of skillProgress" class="skill-bar-row">
            <span class="sk-name">{{ s.name }}</span>
            <div class="sk-bar"><div class="sk-fill" [style.width.%]="s.percentage" [class.high]="s.percentage>=80" [class.mid]="s.percentage>=50 && s.percentage<80" [class.low]="s.percentage<50"></div></div>
            <span class="sk-val">{{ s.percentage | number:'1.0-0' }}%</span>
          </div>
        </div>
      </div>
    </div>
            </div>
    </main>
  `,
  styles: [`
    :host { display: block; }
    .dashboard-container { margin: 0 auto; padding: 24px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .dash-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .welcome h1 { font-size: 26px; font-weight: 800; color: #0f172a; margin: 0; }
    .welcome p { color: #64748b; margin: 4px 0 0; font-size: 14px; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
    .btn-ghost { background: none; color: #6366f1; } .btn-ghost:hover { text-decoration: underline; }
    .btn-sm { padding: 5px 12px; font-size: 13px; }

    .stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 24px; }
    .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; cursor: pointer; transition: all 0.15s; }
    .stat-card:hover { border-color: #a5b4fc; box-shadow: 0 2px 8px rgba(99,102,241,0.08); }
    .sc-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .sc-icon.blue { background: #eef2ff; color: #6366f1; }
    .sc-icon.green { background: #f0fdf4; color: #22c55e; }
    .sc-icon.purple { background: #f5f3ff; color: #8b5cf6; }
    .sc-icon.amber { background: #fffbeb; color: #f59e0b; }
    .sc-icon.teal { background: #f0fdfa; color: #14b8a6; }
    .sc-val { display: block; font-size: 22px; font-weight: 700; color: #0f172a; }
    .sc-label { font-size: 12px; color: #64748b; }

    .overview-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px; }
    .ring-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; display: flex; align-items: center; gap: 28px; }
    .ring-wrapper { position: relative; width: 120px; height: 120px; flex-shrink: 0; }
    .ring-svg { width: 100%; height: 100%; }
    .ring-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .ring-val { font-size: 24px; font-weight: 800; color: #0f172a; }
    .ring-lbl { font-size: 11px; color: #64748b; }
    .ring-details { display: flex; flex-direction: column; gap: 8px; }
    .rd-item { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #334155; }
    .rd-dot { width: 10px; height: 10px; border-radius: 50%; }
    .rd-dot.pass { background: #22c55e; } .rd-dot.fail { background: #ef4444; } .rd-dot.prog { background: #f59e0b; }
    .rank-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .rank-badge { display: flex; align-items: center; gap: 8px; }
    .rank-num { font-size: 32px; font-weight: 800; color: #0f172a; }
    .rank-card p { color: #64748b; font-size: 14px; margin: 4px 0 0; }
    .streak-row { display: flex; align-items: center; gap: 6px; font-size: 14px; color: #f59e0b; font-weight: 600; margin-top: 10px; }

    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
    .dash-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; }
    .dash-section.full-width { margin-bottom: 20px; }
    .ds-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ds-header h2 { font-size: 16px; font-weight: 600; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }

    .type-badge { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
    .type-badge[data-type="quiz"] { background: #dbeafe; color: #1e40af; }
    .type-badge[data-type="exam"] { background: #fef2f2; color: #991b1b; }
    .type-badge[data-type="practice"] { background: #d1fae5; color: #065f46; }
    .type-badge[data-type="survey"] { background: #fef3c7; color: #92400e; }

    .active-list { display: flex; flex-direction: column; gap: 10px; }
    .active-card { padding: 14px; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.15s; }
    .active-card:hover { border-color: #a5b4fc; }
    .act-top { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
    .act-proctored { color: #6366f1; }
    .active-card h4 { margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #0f172a; }
    .act-progress { margin-bottom: 8px; }
    .prog-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-bottom: 4px; }
    .prog-fill { height: 100%; background: #6366f1; border-radius: 3px; transition: width 0.3s; }
    .act-progress span { font-size: 12px; color: #64748b; }
    .act-footer { display: flex; align-items: center; justify-content: space-between; }
    .act-time { font-size: 12px; color: #f59e0b; display: flex; align-items: center; gap: 4px; }

    .upcoming-list { display: flex; flex-direction: column; gap: 8px; }
    .upcoming-item { display: flex; align-items: center; justify-content: space-between; padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; gap: 10px; }
    .upcoming-item:hover { border-color: #a5b4fc; }
    .upc-left { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
    .upc-info h4 { margin: 0; font-size: 14px; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .upc-cat { font-size: 12px; color: #64748b; }
    .upc-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .upc-due { font-size: 12px; color: #f59e0b; font-weight: 500; }
    .upc-meta { font-size: 12px; color: #94a3b8; }

    .results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .result-card { padding: 16px; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.15s; }
    .result-card:hover { border-color: #a5b4fc; }
    .rc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .rc-date { font-size: 12px; color: #94a3b8; }
    .result-card h4 { margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #0f172a; line-height: 1.3; }
    .rc-score { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
    .score-ring-sm { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; }
    .score-ring-sm.pass { background: #f0fdf4; color: #15803d; border: 3px solid #22c55e; }
    .score-ring-sm.fail { background: #fef2f2; color: #dc2626; border: 3px solid #ef4444; }
    .grade-letter { font-size: 22px; font-weight: 800; display: block; }
    .grade-letter.pass { color: #22c55e; } .grade-letter.fail { color: #ef4444; }
    .rc-detail { font-size: 12px; color: #64748b; }
    .rc-footer { display: flex; align-items: center; justify-content: space-between; }
    .rc-status { font-size: 13px; font-weight: 600; }
    .rc-status.pass { color: #22c55e; } .rc-status.fail { color: #ef4444; }

    .skills-grid { display: flex; flex-direction: column; gap: 12px; }
    .skill-bar-row { display: flex; align-items: center; gap: 12px; }
    .sk-name { font-size: 14px; font-weight: 500; color: #334155; width: 140px; flex-shrink: 0; }
    .sk-bar { flex: 1; height: 10px; background: #e2e8f0; border-radius: 5px; overflow: hidden; }
    .sk-fill { height: 100%; border-radius: 5px; transition: width 0.5s; }
    .sk-fill.high { background: #22c55e; } .sk-fill.mid { background: #f59e0b; } .sk-fill.low { background: #ef4444; }
    .sk-val { font-size: 14px; font-weight: 600; color: #334155; width: 50px; text-align: right; }

    .empty-mini { text-align: center; padding: 24px; }
    .empty-mini p { font-size: 13px; color: #94a3b8; margin: 8px 0 0; }

    @media (max-width: 768px) {
      .stats-row { grid-template-columns: repeat(2, 1fr); }
      .overview-row, .two-col { grid-template-columns: 1fr; }
      .results-grid { grid-template-columns: 1fr; }
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
    }).pipe(takeUntil(this.destroy$)).subscribe(({ attempts, certs, game, board }) => {
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

      this.upcomingAssessments = [];
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
