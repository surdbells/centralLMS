// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 13
// Learner: Assessment Lobby Component
// Multi-Tenant LMS | Angular Standalone
// Pre-assessment: instructions, requirements, system checks
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, forkJoin, of, takeUntil } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import {
  AssessmentsService,
  type Assessment as ApiAssessment,
  type ProctoringSettings,
  type AttemptResult,
} from '../../services/assessments.service';

interface AssessmentInfo {
  assessment_id: string; title: string; description: string;
  assessment_type: 'quiz'|'exam'|'practice'|'survey';
  total_questions: number; total_points: number; time_limit_minutes: number;
  max_attempts: number; passing_score: number; passing_type: 'percentage'|'points';
  shuffle_questions: boolean; shuffle_options: boolean; show_results: boolean;
  allow_review: boolean; allow_back_navigation: boolean;
  proctoring_level: 'none'|'basic'|'standard'|'advanced';
  requires_webcam: boolean; requires_screen_share: boolean;
  requires_id_verification: boolean; requires_lockdown: boolean;
  availability_start: string|null; availability_end: string|null;
  instructions: string; sections: SectionInfo[];
  attempt_count: number; remaining_attempts: number;
  has_active_attempt: boolean; active_attempt_id: string|null;
  estimated_duration_minutes: number;
}

interface SectionInfo { section_id: number; title: string; question_count: number; points: number; }

interface SystemCheck {
  name: string; label: string; status: 'pending'|'checking'|'passed'|'failed'|'skipped';
  message: string; required: boolean;
}

@Component({
  selector: 'app-assessment-lobby',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
    <div class="lobby-container">
      <!-- Loading -->
      <div class="loading" *ngIf="loading">
        <div class="spinner"></div><p>Loading assessment...</p>
      </div>

      <!-- Error -->
      <div class="error-card" *ngIf="error">
        <tui-icon icon="@tui.alert-circle" style="font-size:48px;color:#ef4444"></tui-icon>
        <h2>Unable to Load Assessment</h2>
        <p>{{ error }}</p>
        <button class="btn btn-outline" (click)="goBack()"><tui-icon icon="@tui.arrow-left"></tui-icon> Go Back</button>
      </div>

      <div class="lobby-content" *ngIf="!loading && !error && assessment">
        <!-- Header Card -->
        <div class="assessment-card">
          <div class="ac-badge-row">
            <span class="ac-type" [attr.data-type]="assessment.assessment_type">{{ assessment.assessment_type }}</span>
            <span class="ac-proctoring" *ngIf="assessment.proctoring_level!=='none'" [attr.data-level]="assessment.proctoring_level">
              <tui-icon icon="@tui.shield" style="font-size:14px"></tui-icon> {{ assessment.proctoring_level }} proctoring
            </span>
          </div>
          <h1 class="ac-title">{{ assessment.title }}</h1>
          <p class="ac-desc" *ngIf="assessment.description" [innerHTML]="assessment.description"></p>

          <div class="ac-stats">
            <div class="ac-stat">
              <tui-icon icon="@tui.help-circle" style="font-size:18px"></tui-icon>
              <div><span class="ac-stat-val">{{ assessment.total_questions }}</span><span class="ac-stat-lbl">Questions</span></div>
            </div>
            <div class="ac-stat">
              <tui-icon icon="@tui.star" style="font-size:18px"></tui-icon>
              <div><span class="ac-stat-val">{{ assessment.total_points }}</span><span class="ac-stat-lbl">Points</span></div>
            </div>
            <div class="ac-stat" *ngIf="assessment.time_limit_minutes">
              <tui-icon icon="@tui.clock" style="font-size:18px"></tui-icon>
              <div><span class="ac-stat-val">{{ assessment.time_limit_minutes }}</span><span class="ac-stat-lbl">Minutes</span></div>
            </div>
            <div class="ac-stat">
              <tui-icon icon="@tui.target" style="font-size:18px"></tui-icon>
              <div><span class="ac-stat-val">{{ assessment.passing_score }}{{ assessment.passing_type==='percentage' ? '%' : 'pts' }}</span><span class="ac-stat-lbl">To Pass</span></div>
            </div>
            <div class="ac-stat" *ngIf="assessment.max_attempts > 0">
              <tui-icon icon="@tui.refresh-cw" style="font-size:18px"></tui-icon>
              <div><span class="ac-stat-val">{{ assessment.remaining_attempts }}</span><span class="ac-stat-lbl">Attempts Left</span></div>
            </div>
          </div>

          <!-- Sections preview -->
          <div class="sections-preview" *ngIf="(assessment?.sections?.length ?? 0) > 1">
            <h3>Sections</h3>
            <div *ngFor="let s of assessment.sections; let i=index" class="section-row">
              <span class="sec-num">{{ i+1 }}</span>
              <span class="sec-title">{{ s.title }}</span>
              <span class="sec-info">{{ s.question_count }} questions · {{ s.points }} pts</span>
            </div>
          </div>
        </div>

        <!-- Instructions -->
        <div class="info-card" *ngIf="assessment.instructions">
          <h2><tui-icon icon="@tui.file-text" style="font-size:20px"></tui-icon> Instructions</h2>
          <div class="instructions-body" [innerHTML]="assessment.instructions"></div>
        </div>

        <!-- Rules -->
        <div class="info-card rules-card">
          <h2><tui-icon icon="@tui.info" style="font-size:20px"></tui-icon> Assessment Rules</h2>
          <ul class="rules-list">
            <li *ngIf="assessment.time_limit_minutes"><tui-icon icon="@tui.clock" style="font-size:16px"></tui-icon> Time limit: <strong>{{ assessment.time_limit_minutes }} minutes</strong>. Timer starts when you begin.</li>
            <li *ngIf="!assessment.allow_back_navigation"><tui-icon icon="@tui.alert-triangle" style="font-size:16px;color:#f59e0b"></tui-icon> <strong>No back navigation</strong> - you cannot return to previous questions.</li>
            <li *ngIf="assessment.allow_back_navigation"><tui-icon icon="@tui.check" style="font-size:16px;color:#22c55e"></tui-icon> You can navigate freely between questions.</li>
            <li *ngIf="assessment.shuffle_questions"><tui-icon icon="@tui.shuffle" style="font-size:16px"></tui-icon> Questions are presented in random order.</li>
            <li *ngIf="assessment.shuffle_options"><tui-icon icon="@tui.shuffle" style="font-size:16px"></tui-icon> Answer options are shuffled.</li>
            <li><tui-icon icon="@tui.award" style="font-size:16px"></tui-icon> Passing score: <strong>{{ assessment.passing_score }}{{ assessment.passing_type==='percentage' ? '%' : ' points' }}</strong></li>
            <li *ngIf="assessment.max_attempts > 1"><tui-icon icon="@tui.refresh-cw" style="font-size:16px"></tui-icon> You have <strong>{{ assessment.remaining_attempts }}</strong> attempt(s) remaining.</li>
          </ul>
        </div>

        <!-- Proctoring Requirements -->
        <div class="info-card proctoring-card" *ngIf="assessment.proctoring_level!=='none'">
          <h2><tui-icon icon="@tui.shield" style="font-size:20px"></tui-icon> Proctoring Requirements</h2>
          <div class="proctor-reqs">
            <div class="proctor-req" *ngIf="assessment.requires_webcam">
              <tui-icon icon="@tui.camera" style="font-size:20px;color:#6366f1"></tui-icon>
              <div><strong>Webcam Required</strong><p>Your webcam will be active during the assessment.</p></div>
            </div>
            <div class="proctor-req" *ngIf="assessment.requires_screen_share">
              <tui-icon icon="@tui.monitor" style="font-size:20px;color:#6366f1"></tui-icon>
              <div><strong>Screen Sharing</strong><p>Your screen will be monitored.</p></div>
            </div>
            <div class="proctor-req" *ngIf="assessment.requires_id_verification">
              <tui-icon icon="@tui.credit-card" style="font-size:20px;color:#6366f1"></tui-icon>
              <div><strong>ID Verification</strong><p>You'll need to show a valid photo ID.</p></div>
            </div>
            <div class="proctor-req" *ngIf="assessment.requires_lockdown">
              <tui-icon icon="@tui.lock" style="font-size:20px;color:#6366f1"></tui-icon>
              <div><strong>Browser Lockdown</strong><p>Other tabs and apps will be restricted.</p></div>
            </div>
          </div>
        </div>

        <!-- System Checks -->
        <div class="info-card checks-card" *ngIf="assessment.proctoring_level!=='none'">
          <h2><tui-icon icon="@tui.check-square" style="font-size:20px"></tui-icon> System Check</h2>
          <div class="checks-list">
            <div *ngFor="let c of systemChecks" class="check-item" [attr.data-status]="c.status">
              <div class="check-icon">
                <div class="spinner-sm" *ngIf="c.status==='checking'"></div>
                <tui-icon *ngIf="c.status==='passed'" icon="@tui.check-circle" style="font-size:20px;color:#22c55e"></tui-icon>
                <tui-icon *ngIf="c.status==='failed'" icon="@tui.x-circle" style="font-size:20px;color:#ef4444"></tui-icon>
                <tui-icon *ngIf="c.status==='pending'" icon="@tui.circle" style="font-size:20px;color:#94a3b8"></tui-icon>
                <tui-icon *ngIf="c.status==='skipped'" icon="@tui.minus-circle" style="font-size:20px;color:#94a3b8"></tui-icon>
              </div>
              <div class="check-info">
                <span class="check-label">{{ c.label }}</span>
                <span class="check-msg" *ngIf="c.message">{{ c.message }}</span>
              </div>
              <span class="check-required" *ngIf="c.required">Required</span>
            </div>
          </div>
          <button class="btn btn-outline btn-sm" (click)="runSystemChecks()" [disabled]="checksRunning"
                  *ngIf="!allChecksPassed">
            <tui-icon icon="@tui.refresh-cw" style="font-size:14px"></tui-icon> {{ checksRunning ? 'Checking...' : 'Run Checks' }}
          </button>
        </div>

        <!-- Resume Active Attempt -->
        <div class="info-card resume-card" *ngIf="assessment.has_active_attempt">
          <tui-icon icon="@tui.alert-triangle" style="font-size:24px;color:#f59e0b"></tui-icon>
          <div>
            <h3>Active Attempt Found</h3>
            <p>You have an in-progress attempt. You can resume where you left off.</p>
          </div>
        </div>

        <!-- Agreement -->
        <div class="agreement" *ngIf="assessment.proctoring_level!=='none'">
          <label class="cb-label">
            <input type="checkbox" [(ngModel)]="agreedToTerms" />
            I understand that this assessment is proctored and agree to the monitoring requirements.
          </label>
        </div>

        <!-- Actions -->
        <div class="lobby-actions">
          <button class="btn btn-outline" (click)="goBack()"><tui-icon icon="@tui.arrow-left"></tui-icon> Back</button>
          <button class="btn btn-primary btn-lg" (click)="startAssessment()" [disabled]="!canStart()">
            <tui-icon [icon]="assessment.has_active_attempt ? '@tui.play' : '@tui.circle-play'" style="font-size:18px"></tui-icon>
            {{ assessment.has_active_attempt ? 'Resume Assessment' : 'Begin Assessment' }}
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .lobby-container { max-width: 800px; margin: 0 auto; padding: 24px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .loading { display: flex; flex-direction: column; align-items: center; padding: 80px 0; color: #64748b; }
    .spinner { width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 12px; }
    .spinner-sm { width: 20px; height: 20px; border: 2px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .error-card { text-align: center; padding: 60px 20px; }
    .error-card h2 { margin: 16px 0 8px; color: #0f172a; } .error-card p { color: #64748b; margin-bottom: 20px; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-lg { padding: 12px 28px; font-size: 16px; border-radius: 10px; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; } .btn-outline:hover { background: #f9fafb; }
    .btn-sm { padding: 5px 10px; font-size: 13px; }

    .assessment-card { background: linear-gradient(135deg, #eef2ff, #f5f3ff); border: 1px solid #c7d2fe; border-radius: 16px; padding: 28px; margin-bottom: 20px; }
    .ac-badge-row { display: flex; gap: 8px; margin-bottom: 12px; }
    .ac-type { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
    .ac-type[data-type="quiz"] { background: #dbeafe; color: #1e40af; }
    .ac-type[data-type="exam"] { background: #fef2f2; color: #991b1b; }
    .ac-type[data-type="practice"] { background: #d1fae5; color: #065f46; }
    .ac-type[data-type="survey"] { background: #fef3c7; color: #92400e; }
    .ac-proctoring { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 4px; }
    .ac-proctoring[data-level="basic"] { background: #fef3c7; color: #92400e; }
    .ac-proctoring[data-level="standard"] { background: #fed7aa; color: #9a3412; }
    .ac-proctoring[data-level="advanced"] { background: #fecaca; color: #991b1b; }
    .ac-title { font-size: 28px; font-weight: 800; color: #0f172a; margin: 0 0 8px; }
    .ac-desc { color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 20px; }
    .ac-stats { display: flex; gap: 20px; flex-wrap: wrap; }
    .ac-stat { display: flex; align-items: center; gap: 10px; color: #6366f1; }
    .ac-stat-val { display: block; font-size: 20px; font-weight: 700; color: #0f172a; }
    .ac-stat-lbl { font-size: 12px; color: #64748b; }
    .sections-preview { margin-top: 20px; padding-top: 16px; border-top: 1px solid #c7d2fe; }
    .sections-preview h3 { font-size: 14px; font-weight: 600; color: #475569; margin: 0 0 10px; text-transform: uppercase; }
    .section-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(199,210,254,0.5); }
    .sec-num { width: 24px; height: 24px; border-radius: 50%; background: #6366f1; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
    .sec-title { flex: 1; font-size: 14px; font-weight: 500; color: #0f172a; }
    .sec-info { font-size: 12px; color: #64748b; }

    .info-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; margin-bottom: 16px; }
    .info-card h2 { font-size: 18px; font-weight: 600; color: #0f172a; margin: 0 0 14px; display: flex; align-items: center; gap: 10px; }
    .instructions-body { font-size: 15px; line-height: 1.7; color: #334155; }
    .rules-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
    .rules-list li { display: flex; align-items: center; gap: 10px; font-size: 14px; color: #334155; padding: 8px 12px; background: #f8fafc; border-radius: 8px; }
    .proctor-reqs { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .proctor-req { display: flex; gap: 12px; padding: 14px; background: #f5f3ff; border-radius: 10px; border: 1px solid #e0e7ff; }
    .proctor-req p { font-size: 13px; color: #64748b; margin: 4px 0 0; }
    .proctor-req strong { color: #0f172a; font-size: 14px; }

    .checks-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
    .check-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; }
    .check-item[data-status="passed"] { background: #f0fdf4; border-color: #bbf7d0; }
    .check-item[data-status="failed"] { background: #fef2f2; border-color: #fecaca; }
    .check-icon { width: 24px; flex-shrink: 0; }
    .check-info { flex: 1; }
    .check-label { font-size: 14px; font-weight: 500; color: #0f172a; display: block; }
    .check-msg { font-size: 12px; color: #64748b; }
    .check-required { font-size: 11px; font-weight: 600; color: #6366f1; background: #eef2ff; padding: 2px 8px; border-radius: 4px; }

    .resume-card { display: flex; align-items: center; gap: 16px; background: #fffbeb; border-color: #fde68a; }
    .resume-card h3 { margin: 0; font-size: 16px; color: #92400e; }
    .resume-card p { margin: 4px 0 0; font-size: 14px; color: #a16207; }

    .agreement { padding: 16px; background: #f8fafc; border-radius: 10px; margin-bottom: 20px; }
    .cb-label { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: #334155; cursor: pointer; line-height: 1.5; }
    .cb-label input { margin-top: 3px; }

    .lobby-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; }

    @media (max-width: 600px) {
      .ac-stats { flex-direction: column; gap: 12px; }
      .proctor-reqs { grid-template-columns: 1fr; }
      .lobby-actions { flex-direction: column; }
    }
  `]
})
export class AssessmentLobbyComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroy$ = new Subject<void>();

  assessment: AssessmentInfo|null = null;
  loading = true;
  error = '';
  agreedToTerms = false;
  starting = false;

  // System checks
  systemChecks: SystemCheck[] = [];
  checksRunning = false;

  get allChecksPassed(): boolean {
    return this.systemChecks.filter(c => c.required).every(c => c.status === 'passed');
  }

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (id) this.loadAssessment(id);
    else { this.loading = false; this.error = 'No assessment ID provided.'; }
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  loadAssessment(id: string): void {
    // The assessment itself, its proctoring settings, and how many times this
    // person has already sat it — in one pass.
    forkJoin({
      assessment: this.assessments.show(id),
      proctoring: this.assessments.proctoringSettings(id).pipe(catchError(() => of(null))),
      attempts: this.assessments.myAttempts(200).pipe(catchError(() => of([] as AttemptResult[]))),
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ assessment, proctoring, attempts }) => {
        this.assessment = this.toInfo(assessment, proctoring, attempts);
        this.loading = false;
        this.initSystemChecks();
        if (this.assessment.proctoring_level !== 'none') this.runSystemChecks();
      },
      error: () => { this.loading = false; this.error = 'Failed to load assessment.'; },
    });
  }

  /** Maps the new API's shapes into the shape this screen was built around. */
  private toInfo(
    a: ApiAssessment,
    p: ProctoringSettings | null,
    attempts: AttemptResult[],
  ): AssessmentInfo {
    const mine = attempts.filter(t => t.assessmentId === a.id);
    const active = mine.find(t => t.status === 'in_progress') ?? null;
    // The API returns { enabled, settings: { requireWebcam, requireFullscreen, … } };
    // enablement is `enabled` (not `isEnabled`) and the flags live under `settings`.
    const pr = p as unknown as Record<string, unknown> | null;
    const settings = (pr?.['settings'] as Record<string, unknown>) ?? {};
    const proctored = !!pr?.['enabled'];
    const type = (['quiz', 'exam', 'practice', 'survey'].includes(a.kind) ? a.kind : 'quiz') as AssessmentInfo['assessment_type'];

    return {
      assessment_id: a.id,
      title: a.title,
      description: a.description ?? '',
      assessment_type: type,
      total_questions: a.questionCount,
      total_points: a.totalPoints,
      time_limit_minutes: a.timeLimitMinutes ?? 0,
      max_attempts: a.maxAttempts ?? 0,
      passing_score: a.passingScore,
      passing_type: (a.passingType === 'points' ? 'points' : 'percentage'),
      shuffle_questions: a.shuffleQuestions,
      shuffle_options: a.shuffleOptions,
      show_results: a.showScoreImmediately,
      allow_review: a.revealAnswers !== 'never',
      allow_back_navigation: true,
      proctoring_level: proctored ? 'standard' : 'none',
      requires_webcam: !!settings['requireWebcam'],
      requires_screen_share: !!settings['recordScreen'],
      requires_id_verification: !!settings['requireIdVerification'],
      requires_lockdown: !!settings['requireFullscreen'],
      availability_start: null,
      availability_end: null,
      instructions: a.instructions ?? '',
      sections: [],
      attempt_count: mine.length,
      remaining_attempts: a.maxAttempts ? Math.max(0, a.maxAttempts - mine.length) : 999,
      has_active_attempt: active !== null,
      active_attempt_id: active?.id ?? null,
      estimated_duration_minutes: a.timeLimitMinutes ?? 0,
    };
  }

  initSystemChecks(): void {
    if (!this.assessment) return;
    const checks: SystemCheck[] = [
      { name: 'browser', label: 'Browser Compatibility', status: 'pending', message: '', required: true }
    ];
    if (this.assessment.requires_webcam)
      checks.push({ name: 'webcam', label: 'Webcam Access', status: 'pending', message: '', required: true });
    if (this.assessment.requires_screen_share)
      checks.push({ name: 'screen', label: 'Screen Sharing', status: 'pending', message: '', required: false });
    checks.push({ name: 'network', label: 'Network Connection', status: 'pending', message: '', required: true });
    if (this.assessment.requires_lockdown)
      checks.push({ name: 'lockdown', label: 'Browser Lockdown', status: 'pending', message: '', required: false });
    this.systemChecks = checks;
  }

  async runSystemChecks(): Promise<void> {
    this.checksRunning = true;
    for (const check of this.systemChecks) {
      check.status = 'checking'; check.message = '';
      await this.delay(400);
      try {
        if (check.name === 'browser') {
          const ok = !!(window as any).MediaStream || !!(navigator as any).mediaDevices;
          check.status = ok ? 'passed' : 'failed';
          check.message = ok ? 'Browser supported' : 'Browser may not support all features';
        } else if (check.name === 'webcam') {
          try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: true });
            stream.getTracks().forEach(t => t.stop());
            check.status = 'passed'; check.message = 'Webcam accessible';
          } catch { check.status = 'failed'; check.message = 'Webcam access denied. Please allow camera.'; }
        } else if (check.name === 'screen') {
          check.status = 'passed'; check.message = 'Will request when assessment starts';
        } else if (check.name === 'network') {
          check.status = navigator.onLine ? 'passed' : 'failed';
          check.message = navigator.onLine ? 'Connected' : 'No internet connection';
        } else if (check.name === 'lockdown') {
          check.status = 'passed'; check.message = 'Will activate on start';
        }
      } catch { check.status = 'failed'; check.message = 'Check failed'; }
    }
    this.checksRunning = false;
  }

  canStart(): boolean {
    if (!this.assessment) return false;
    if (this.assessment.remaining_attempts <= 0 && !this.assessment.has_active_attempt) return false;
    if (this.assessment.proctoring_level !== 'none') {
      if (!this.agreedToTerms) return false;
      const requiredFailed = this.systemChecks.filter(c => c.required && c.status === 'failed');
      if (requiredFailed.length > 0) return false;
    }
    return true;
  }

  startAssessment(): void {
    if (!this.assessment || !this.canStart() || this.starting) return;
    this.starting = true;
    const assessmentId = this.assessment.assessment_id;
    // The server opens a new attempt or returns the one already open, so
    // resuming and starting are the same call.
    this.assessments.startAttempt(assessmentId).pipe(takeUntil(this.destroy$)).subscribe({
      next: paper => {
        this.starting = false;
        const attemptId = String(paper.attempt?.['id'] ?? '');
        void this.router.navigate(['/learner/assessments', assessmentId, 'attempt', attemptId]);
      },
      error: () => { this.starting = false; this.error = 'Failed to start attempt.'; },
    });
  }

  goBack(): void { this.router.navigate(['/learner/assessments']); }
  private delay(ms: number): Promise<void> { return new Promise(r => setTimeout(r, ms)); }
}
