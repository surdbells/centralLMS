// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 13
// Learner: Assessment Player Component
// Multi-Tenant LMS | Angular Standalone
// Core exam interface: navigation, timer, auto-save, submit
// =============================================

import { Component, OnInit, OnDestroy, inject, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, forkJoin, of, takeUntil, interval } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { AssessmentsService } from '../../services/assessments.service';
import { QuestionRendererComponent, PlayerQuestion, QuestionAnswer } from '../question-renderer/question-renderer.component';
import { ProctoringMonitorComponent } from '../proctoring-monitor/proctoring-monitor.component';

interface AttemptData {
  attempt_id: string; assessment_id: string; assessment_title: string;
  time_limit_seconds: number|null; time_remaining_seconds: number|null;
  started_at: string; allow_back_navigation: boolean;
  show_question_numbers: boolean; one_question_per_page: boolean;
  sections: PlayerSection[]; total_questions: number;
  // Populated when the proctoring slice lands; null keeps the monitor hidden.
  proctoring_config: any;
}

interface PlayerSection {
  section_id: string; title: string; description: string;
  questions: PlayerQuestion[];
}

@Component({
  selector: 'app-assessment-player',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, QuestionRendererComponent, ProctoringMonitorComponent],
  template: `
    <div class="player-container" *ngIf="attempt">
      <!-- Top Bar -->
      <div class="top-bar">
        <div class="top-left">
          <h2 class="assess-title">{{ attempt.assessment_title }}</h2>
          <span class="section-label" *ngIf="currentSection">{{ currentSection.title }}</span>
        </div>
        <div class="top-center">
          <div class="timer" *ngIf="attempt.time_limit_seconds" [class.urgent]="timeRemaining <= 300"
               [class.critical]="timeRemaining <= 60">
            <tui-icon icon="@tui.clock" style="font-size:18px"></tui-icon>
            <span class="timer-display">{{ formatTime(timeRemaining) }}</span>
          </div>
        </div>
        <div class="top-right">
          <div class="progress-info">
            <span>{{ answeredCount }}/{{ attempt.total_questions }} answered</span>
            <div class="progress-bar"><div class="progress-fill" [style.width.%]="(answeredCount/attempt.total_questions)*100"></div></div>
          </div>
          <button class="btn btn-danger btn-sm" (click)="confirmSubmit()">
            <tui-icon icon="@tui.send" style="font-size:14px"></tui-icon> Submit
          </button>
        </div>
      </div>

      <div class="player-body">
        <!-- Question Navigator (sidebar) -->
        <div class="nav-sidebar" [class.collapsed]="navCollapsed">
          <div class="nav-header">
            <h3>Questions</h3>
            <button class="nav-toggle" (click)="navCollapsed=!navCollapsed">
              <tui-icon [icon]="navCollapsed ? '@tui.chevron-right' : '@tui.chevron-left'" style="font-size:16px"></tui-icon>
            </button>
          </div>
          <div class="nav-grid" *ngIf="!navCollapsed">
            <ng-container *ngFor="let sec of attempt.sections; let si=index">
              <div class="nav-section-title" *ngIf="attempt.sections.length > 1">{{ sec.title }}</div>
              <div class="nav-items">
                <button *ngFor="let q of sec.questions; let qi=index" class="nav-item"
                        [class.current]="isCurrentQuestion(sec.section_id, q.question_id)"
                        [class.answered]="isAnswered(q.question_id)"
                        [class.flagged]="isFlagged(q.question_id)"
                        [disabled]="!attempt.allow_back_navigation && !isCurrentQuestion(sec.section_id, q.question_id)"
                        (click)="navigateTo(si, qi)">
                  {{ getQuestionNumber(si, qi) }}
                  <span class="nav-flag" *ngIf="isFlagged(q.question_id)">
                    <tui-icon icon="@tui.flag" style="font-size:10px"></tui-icon>
                  </span>
                </button>
              </div>
            </ng-container>
          </div>
          <div class="nav-legend" *ngIf="!navCollapsed">
            <span class="legend-item"><span class="legend-dot current"></span> Current</span>
            <span class="legend-item"><span class="legend-dot answered"></span> Answered</span>
            <span class="legend-item"><span class="legend-dot flagged"></span> Flagged</span>
            <span class="legend-item"><span class="legend-dot unanswered"></span> Unanswered</span>
          </div>
        </div>

        <!-- Main Question Area -->
        <div class="question-area">
          <!-- Section Header -->
          <div class="section-header" *ngIf="currentSection && attempt.sections.length > 1">
            <span class="section-badge">Section {{ currentSectionIndex + 1 }}</span>
            <h3>{{ currentSection.title }}</h3>
            <p *ngIf="currentSection.description">{{ currentSection.description }}</p>
          </div>

          <!-- Question Counter -->
          <div class="question-counter">
            <span>Question {{ currentGlobalIndex + 1 }} of {{ attempt.total_questions }}</span>
          </div>

          <!-- Rendered Question -->
          <app-question-renderer *ngIf="currentQuestion"
            [question]="currentQuestion"
            [savedAnswer]="getAnswer(currentQuestion.question_id)"
            (answerChange)="onAnswerChange($event)">
          </app-question-renderer>

          <!-- Navigation Buttons -->
          <div class="question-nav">
            <button class="btn btn-outline" (click)="prevQuestion()"
                    [disabled]="!attempt.allow_back_navigation || (currentSectionIndex===0 && currentQuestionIndex===0)">
              <tui-icon icon="@tui.chevron-left" style="font-size:16px"></tui-icon> Previous
            </button>
            <div class="nav-center-info">
              <span *ngIf="!isLastQuestion()">{{ attempt.total_questions - currentGlobalIndex - 1 }} remaining</span>
            </div>
            <button *ngIf="!isLastQuestion()" class="btn btn-primary" (click)="nextQuestion()">
              Next <tui-icon icon="@tui.chevron-right" style="font-size:16px"></tui-icon>
            </button>
            <button *ngIf="isLastQuestion()" class="btn btn-primary" (click)="confirmSubmit()">
              <tui-icon icon="@tui.send" style="font-size:16px"></tui-icon> Finish & Submit
            </button>
          </div>
        </div>
      </div>

      <!-- Proctoring Monitor -->
      <app-proctoring-monitor *ngIf="attempt.proctoring_config?.webcam_enabled || attempt.proctoring_config?.tab_switch_detection"
        [attemptId]="attempt.attempt_id"
        [config]="attempt.proctoring_config"
        (onViolation)="onProctoringViolation($event)"
        (onTerminate)="forceSubmit()">
      </app-proctoring-monitor>

      <!-- Submit Confirmation Modal -->
      <div class="modal-overlay" *ngIf="showSubmitModal" (click)="showSubmitModal=false">
        <div class="modal-content" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h3><tui-icon icon="@tui.send" style="font-size:20px"></tui-icon> Submit Assessment</h3>
          </div>
          <div class="modal-body">
            <div class="submit-summary">
              <div class="ss-row"><span>Total Questions</span><strong>{{ attempt.total_questions }}</strong></div>
              <div class="ss-row ok"><span>Answered</span><strong>{{ answeredCount }}</strong></div>
              <div class="ss-row warn" *ngIf="unansweredCount > 0"><span>Unanswered</span><strong>{{ unansweredCount }}</strong></div>
              <div class="ss-row flag" *ngIf="flaggedCount > 0"><span>Flagged for Review</span><strong>{{ flaggedCount }}</strong></div>
            </div>
            <p class="submit-warn" *ngIf="unansweredCount > 0">
              <tui-icon icon="@tui.alert-triangle" style="font-size:16px;color:#f59e0b"></tui-icon>
              You have {{ unansweredCount }} unanswered question(s). Are you sure you want to submit?
            </p>
            <p class="submit-note">Once submitted, you cannot change your answers.</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" (click)="showSubmitModal=false">Review Answers</button>
            <button class="btn btn-primary" (click)="submitAssessment()" [disabled]="submitting">
              {{ submitting ? 'Submitting...' : 'Submit Assessment' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Time Warning Modal -->
      <div class="time-warning" *ngIf="showTimeWarning" (click)="showTimeWarning=false">
        <tui-icon icon="@tui.clock" style="font-size:24px;color:#ef4444"></tui-icon>
        <div>
          <strong>{{ timeRemaining <= 60 ? 'Less than 1 minute!' : '5 minutes remaining!' }}</strong>
          <p>Your assessment will be auto-submitted when time runs out.</p>
        </div>
      </div>

      <!-- Auto-Save Indicator -->
      <div class="autosave-indicator" *ngIf="autoSaveStatus" [class]="'as-' + autoSaveStatus">
        <tui-icon [icon]="autoSaveStatus==='saving' ? '@tui.loader' : (autoSaveStatus==='saved' ? '@tui.check' : '@tui.alert-circle')" style="font-size:14px"></tui-icon>
        {{ autoSaveStatus==='saving' ? 'Saving...' : (autoSaveStatus==='saved' ? 'Saved' : 'Save failed') }}
      </div>
    </div>

    <!-- Loading State -->
    <div class="loading" *ngIf="!attempt && !loadError">
      <div class="spinner"></div><p>Loading assessment...</p>
    </div>
    <div class="error-state" *ngIf="loadError">
      <tui-icon icon="@tui.alert-circle" style="font-size:48px;color:#ef4444"></tui-icon>
      <h2>Error</h2><p>{{ loadError }}</p>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .player-container { height: 100vh; display: flex; flex-direction: column; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #f8fafc; }
    .loading, .error-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; color: #64748b; }
    .spinner { width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 12px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .error-state h2 { margin: 16px 0 8px; color: #0f172a; }

    /* Top Bar */
    .top-bar { display: flex; align-items: center; justify-content: space-between; padding: 10px 20px; background: #fff; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; gap: 16px; z-index: 100; }
    .top-left { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
    .assess-title { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .section-label { font-size: 13px; color: #6366f1; background: #eef2ff; padding: 2px 10px; border-radius: 4px; white-space: nowrap; }
    .top-center { flex-shrink: 0; }
    .timer { display: flex; align-items: center; gap: 8px; padding: 6px 14px; background: #f1f5f9; border-radius: 8px; color: #334155; }
    .timer.urgent { background: #fffbeb; color: #92400e; }
    .timer.critical { background: #fef2f2; color: #dc2626; animation: pulse 1s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }
    .timer-display { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .top-right { display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
    .progress-info { text-align: right; }
    .progress-info span { font-size: 12px; color: #64748b; }
    .progress-bar { width: 120px; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 4px; }
    .progress-fill { height: 100%; background: #6366f1; border-radius: 3px; transition: width 0.3s; }

    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; } .btn-outline:hover { background: #f9fafb; }
    .btn-outline:disabled { opacity: 0.4; cursor: not-allowed; }
    .btn-danger { background: #ef4444; color: #fff; } .btn-danger:hover { background: #dc2626; }
    .btn-sm { padding: 5px 12px; font-size: 13px; }

    /* Body Layout */
    .player-body { display: flex; flex: 1; overflow: hidden; }

    /* Nav Sidebar */
    .nav-sidebar { width: 220px; background: #fff; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; flex-shrink: 0; overflow-y: auto; transition: width 0.2s; }
    .nav-sidebar.collapsed { width: 40px; }
    .nav-header { display: flex; align-items: center; justify-content: space-between; padding: 12px; border-bottom: 1px solid #f1f5f9; }
    .nav-header h3 { font-size: 14px; font-weight: 600; color: #0f172a; margin: 0; }
    .nav-toggle { background: none; border: none; cursor: pointer; color: #64748b; padding: 4px; border-radius: 4px; }
    .nav-toggle:hover { background: #f1f5f9; }
    .nav-section-title { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; padding: 10px 12px 4px; }
    .nav-items { display: flex; flex-wrap: wrap; gap: 4px; padding: 4px 12px 8px; }
    .nav-item { width: 36px; height: 36px; border-radius: 8px; border: 2px solid #e2e8f0; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: #64748b; cursor: pointer; position: relative; transition: all 0.15s; }
    .nav-item:hover:not(:disabled) { border-color: #a5b4fc; }
    .nav-item.current { border-color: #6366f1; background: #6366f1; color: #fff; }
    .nav-item.answered { border-color: #22c55e; background: #f0fdf4; color: #15803d; }
    .nav-item.answered.current { background: #6366f1; color: #fff; border-color: #6366f1; }
    .nav-item.flagged { border-color: #f59e0b; }
    .nav-item:disabled { opacity: 0.4; cursor: not-allowed; }
    .nav-flag { position: absolute; top: -4px; right: -4px; color: #f59e0b; }
    .nav-legend { padding: 12px; border-top: 1px solid #f1f5f9; display: flex; flex-wrap: wrap; gap: 8px; }
    .legend-item { display: flex; align-items: center; gap: 4px; font-size: 11px; color: #64748b; }
    .legend-dot { width: 10px; height: 10px; border-radius: 3px; border: 2px solid; }
    .legend-dot.current { border-color: #6366f1; background: #6366f1; }
    .legend-dot.answered { border-color: #22c55e; background: #f0fdf4; }
    .legend-dot.flagged { border-color: #f59e0b; }
    .legend-dot.unanswered { border-color: #e2e8f0; }

    /* Question Area */
    .question-area { flex: 1; overflow-y: auto; padding: 28px 40px; max-width: 900px; margin: 0 auto; width: 100%; box-sizing: border-box; }
    .section-header { margin-bottom: 16px; padding: 14px 18px; background: #eef2ff; border-radius: 10px; border: 1px solid #c7d2fe; }
    .section-badge { font-size: 11px; font-weight: 600; color: #6366f1; text-transform: uppercase; }
    .section-header h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin: 4px 0 0; }
    .section-header p { font-size: 13px; color: #64748b; margin: 4px 0 0; }
    .question-counter { font-size: 13px; color: #64748b; margin-bottom: 12px; }

    /* Question Nav */
    .question-nav { display: flex; align-items: center; justify-content: space-between; margin-top: 28px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
    .nav-center-info { font-size: 13px; color: #94a3b8; }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 10000; }
    .modal-content { background: #fff; border-radius: 16px; width: 90%; max-width: 480px; }
    .modal-header { padding: 18px 22px; border-bottom: 1px solid #e2e8f0; }
    .modal-header h3 { font-size: 18px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 8px; }
    .modal-body { padding: 22px; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px; border-top: 1px solid #e2e8f0; }
    .submit-summary { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
    .ss-row { display: flex; justify-content: space-between; padding: 8px 14px; border-radius: 8px; background: #f8fafc; font-size: 14px; }
    .ss-row strong { font-weight: 700; }
    .ss-row.ok { background: #f0fdf4; color: #15803d; }
    .ss-row.warn { background: #fffbeb; color: #92400e; }
    .ss-row.flag { background: #fff7ed; color: #9a3412; }
    .submit-warn { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #92400e; margin: 0 0 8px; }
    .submit-note { font-size: 13px; color: #94a3b8; margin: 0; }

    /* Time Warning */
    .time-warning { position: fixed; top: 60px; left: 50%; transform: translateX(-50%); display: flex; align-items: center; gap: 12px; padding: 14px 22px; background: #fef2f2; border: 2px solid #ef4444; border-radius: 12px; z-index: 10000; cursor: pointer; animation: slideDown 0.3s ease; }
    .time-warning strong { color: #dc2626; } .time-warning p { margin: 2px 0 0; font-size: 13px; color: #b91c1c; }
    @keyframes slideDown { from { transform: translateX(-50%) translateY(-20px); opacity: 0; } to { transform: translateX(-50%) translateY(0); opacity: 1; } }

    /* Auto-save */
    .autosave-indicator { position: fixed; bottom: 16px; left: 16px; display: flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; font-size: 12px; z-index: 100; }
    .as-saving { background: #fffbeb; color: #92400e; }
    .as-saved { background: #f0fdf4; color: #15803d; }
    .as-error { background: #fef2f2; color: #dc2626; }

    @media (max-width: 768px) {
      .nav-sidebar { display: none; }
      .question-area { padding: 16px; }
      .top-bar { padding: 8px 12px; }
      .assess-title { font-size: 14px; }
      .timer-display { font-size: 16px; }
    }
  `]
})
export class AssessmentPlayerComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroy$ = new Subject<void>();

  attempt: AttemptData|null = null;
  loadError = '';
  answers: Map<string, QuestionAnswer> = new Map();

  currentSectionIndex = 0;
  currentQuestionIndex = 0;
  navCollapsed = false;

  timeRemaining = 0;
  private timerInterval: any;
  showTimeWarning = false;
  private fiveMinWarnShown = false;
  private oneMinWarnShown = false;

  showSubmitModal = false;
  submitting = false;
  autoSaveStatus: 'saving'|'saved'|'error'|null = null;
  private autoSaveTimeout: any;
  private questionStartTime = Date.now();
  /** The question whose answer is waiting to be saved. */
  private pendingAnswerId: string | null = null;

  get currentSection(): PlayerSection|null { return this.attempt?.sections[this.currentSectionIndex] || null; }
  get currentQuestion(): PlayerQuestion|null { return this.currentSection?.questions[this.currentQuestionIndex] || null; }
  get currentGlobalIndex(): number {
    if (!this.attempt) return 0;
    let idx = 0;
    for (let s = 0; s < this.currentSectionIndex; s++) idx += this.attempt.sections[s].questions.length;
    return idx + this.currentQuestionIndex;
  }
  get answeredCount(): number { let c = 0; this.answers.forEach(a => { if (this.isAnswerFilled(a)) c++; }); return c; }
  get unansweredCount(): number { return (this.attempt?.total_questions || 0) - this.answeredCount; }
  get flaggedCount(): number { let c = 0; this.answers.forEach(a => { if (a.is_flagged) c++; }); return c; }

  ngOnInit(): void {
    const attemptId = this.route.snapshot.paramMap.get('attemptId');
    if (attemptId) this.loadAttempt(attemptId);
    else this.loadError = 'No attempt ID. Please start from the assessment lobby.';
  }

  ngOnDestroy(): void {
    this.destroy$.next(); this.destroy$.complete();
    if (this.timerInterval) clearInterval(this.timerInterval);
    if (this.autoSaveTimeout) clearTimeout(this.autoSaveTimeout);
  }

  @HostListener('window:beforeunload', ['$event'])
  onBeforeUnload(e: BeforeUnloadEvent): void {
    if (this.attempt && !this.submitting) { e.preventDefault(); e.returnValue = ''; }
  }

  loadAttempt(attemptId: string): void {
    this.assessments.attempt(attemptId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (raw) => {
        const paper = raw as unknown as Record<string, unknown>;
        // The new API returns the attempt flat, with an `answers` array carrying
        // each question and whatever the learner has put down so far.
        const rows = Array.isArray(paper['answers']) ? (paper['answers'] as Array<Record<string, unknown>>) : [];
        const questions = rows.map(row => this.toPlayerQuestion(row['question'] as Record<string, unknown>));

        this.attempt = {
          attempt_id: attemptId,
          assessment_id: String(paper['assessmentId'] ?? ''),
          assessment_title: String(paper['assessmentTitle'] ?? 'Assessment'),
          time_limit_seconds: (paper['secondsRemaining'] != null || paper['expiresAt'] != null)
            ? Number(paper['secondsRemaining'] ?? 0) : null,
          time_remaining_seconds: paper['secondsRemaining'] != null ? Number(paper['secondsRemaining']) : null,
          started_at: String(paper['startedAt'] ?? ''),
          allow_back_navigation: true,
          show_question_numbers: true,
          one_question_per_page: true,
          // One section holding every question — the new paper is flat.
          sections: [{ section_id: 'all', title: '', description: '', questions }],
          total_questions: questions.length,
          proctoring_config: null,
        };

        // Seed a blank answer per question, then fill in what was saved.
        for (const q of questions) {
          this.answers.set(q.question_id, this.blankAnswer(q));
        }
        for (const row of rows) {
          const q = row['question'] as Record<string, unknown>;
          const qid = String(q['id'] ?? '');
          const existing = this.answers.get(qid);
          if (existing && row['answer'] != null) {
            this.answers.set(qid, this.fromApiAnswer(existing, row['answer'] as Record<string, unknown>));
          }
        }

        if (this.attempt.time_limit_seconds) {
          this.timeRemaining = this.attempt.time_remaining_seconds || this.attempt.time_limit_seconds;
          this.startTimer();
        }
        this.startAutoSave();
      },
      error: () => { this.loadError = 'Failed to load attempt data.'; },
    });
  }

  /** A blank answer scaffold for a question. */
  private blankAnswer(q: PlayerQuestion): QuestionAnswer {
    return {
      question_id: q.question_id, answer_type: q.question_type,
      selected_option_ids: [], text_answer: '', numeric_answer: null,
      blank_answers: [], match_answers: [], order_answer: [],
      file_url: '', hotspot_click: null, is_flagged: false, time_spent_seconds: 0,
    };
  }

  /** Maps one API question onto the shape the renderer reads. */
  private toPlayerQuestion(q: Record<string, unknown>): PlayerQuestion {
    const opts = Array.isArray(q['options']) ? (q['options'] as Array<Record<string, unknown>>) : [];
    return {
      question_id: String(q['id'] ?? ''),
      question_text: String(q['text'] ?? ''),
      question_type: this.mapType(String(q['type'] ?? '')),
      points: Number(q['points'] ?? 1),
      hint: (q['hint'] as string) ?? undefined,
      required: true,
      numeric_unit: undefined,
      options: opts.map(o => ({
        id: String(o['id'] ?? ''),
        option_text: String(o['text'] ?? ''),
        order_position: Number(o['sortOrder'] ?? 0),
      })),
    };
  }

  /** New-API question type -> the renderer's own type names. */
  private mapType(apiType: string): string {
    return ({
      multiple_choice: 'multiple_choice',
      multiple_choice_single: 'multiple_choice',
      multiple_choice_multiple: 'multiple_response',
      true_false: 'true_false',
      short_answer: 'short_answer',
      essay: 'long_answer',
      numeric: 'numeric',
      fill_in_blanks: 'fill_blank',
      matching: 'matching',
      ordering: 'ordering',
    } as Record<string, string>)[apiType] ?? apiType;
  }

  /** Fills a saved API answer back into the renderer's answer shape. */
  private fromApiAnswer(base: QuestionAnswer, api: Record<string, unknown>): QuestionAnswer {
    const ids = Array.isArray(api['optionIds']) ? (api['optionIds'] as unknown[]).map(String) : [];
    return {
      ...base,
      selected_option_ids: base.answer_type === 'ordering' ? base.selected_option_ids : ids,
      order_answer: base.answer_type === 'ordering' ? ids : base.order_answer,
      text_answer: typeof api['text'] === 'string' ? api['text'] : base.text_answer,
      numeric_answer: typeof api['value'] === 'number' ? api['value'] : base.numeric_answer,
      blank_answers: Array.isArray(api['texts']) ? (api['texts'] as unknown[]).map(String) : base.blank_answers,
    };
  }

  // === Timer ===
  startTimer(): void {
    this.timerInterval = setInterval(() => {
      this.timeRemaining--;
      if (this.timeRemaining <= 300 && !this.fiveMinWarnShown) { this.fiveMinWarnShown = true; this.showTimeWarning = true; setTimeout(() => this.showTimeWarning = false, 5000); }
      if (this.timeRemaining <= 60 && !this.oneMinWarnShown) { this.oneMinWarnShown = true; this.showTimeWarning = true; setTimeout(() => this.showTimeWarning = false, 5000); }
      if (this.timeRemaining <= 0) { clearInterval(this.timerInterval); this.forceSubmit(); }
    }, 1000);
  }

  formatTime(seconds: number): string {
    if (seconds <= 0) return '00:00';
    const h = Math.floor(seconds / 3600); const m = Math.floor((seconds % 3600) / 60); const s = seconds % 60;
    if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
  }

  // === Navigation ===
  navigateTo(sectionIdx: number, questionIdx: number): void {
    this.saveTimeSpent();
    this.currentSectionIndex = sectionIdx;
    this.currentQuestionIndex = questionIdx;
    this.questionStartTime = Date.now();
  }

  nextQuestion(): void {
    this.saveTimeSpent();
    const sec = this.currentSection;
    if (!sec || !this.attempt) return;
    if (this.currentQuestionIndex < sec.questions.length - 1) {
      this.currentQuestionIndex++;
    } else if (this.currentSectionIndex < this.attempt.sections.length - 1) {
      this.currentSectionIndex++;
      this.currentQuestionIndex = 0;
    }
    this.questionStartTime = Date.now();
  }

  prevQuestion(): void {
    if (!this.attempt?.allow_back_navigation) return;
    this.saveTimeSpent();
    if (this.currentQuestionIndex > 0) {
      this.currentQuestionIndex--;
    } else if (this.currentSectionIndex > 0) {
      this.currentSectionIndex--;
      this.currentQuestionIndex = this.attempt.sections[this.currentSectionIndex].questions.length - 1;
    }
    this.questionStartTime = Date.now();
  }

  isLastQuestion(): boolean {
    if (!this.attempt) return false;
    const lastSec = this.attempt.sections.length - 1;
    return this.currentSectionIndex === lastSec && this.currentQuestionIndex === this.attempt.sections[lastSec].questions.length - 1;
  }

  isCurrentQuestion(sectionId: string, questionId: string): boolean {
    return this.currentSection?.section_id === sectionId && this.currentQuestion?.question_id === questionId;
  }

  getQuestionNumber(sectionIdx: number, questionIdx: number): number {
    if (!this.attempt) return 0;
    let num = 1;
    for (let s = 0; s < sectionIdx; s++) num += this.attempt.sections[s].questions.length;
    return num + questionIdx;
  }

  // === Answers ===
  getAnswer(questionId: string): QuestionAnswer|undefined { return this.answers.get(questionId); }

  onAnswerChange(answer: QuestionAnswer): void {
    this.answers.set(answer.question_id, answer);
    // Save this one answer, debounced — the new API records them one at a time.
    this.pendingAnswerId = answer.question_id;
    this.triggerAutoSave();
  }

  isAnswered(questionId: string): boolean {
    const a = this.answers.get(questionId);
    return a ? this.isAnswerFilled(a) : false;
  }

  isFlagged(questionId: string): boolean { return this.answers.get(questionId)?.is_flagged || false; }

  isAnswerFilled(a: QuestionAnswer): boolean {
    if (a.selected_option_ids?.length) return true;
    if (a.text_answer?.trim()) return true;
    if (a.numeric_answer !== null && a.numeric_answer !== undefined) return true;
    if (a.blank_answers?.some(b => b.trim())) return true;
    if (a.match_answers?.length) return true;
    if (a.order_answer?.length) return true;
    if (a.file_url) return true;
    if (a.hotspot_click) return true;
    return false;
  }

  saveTimeSpent(): void {
    if (!this.currentQuestion) return;
    const elapsed = Math.round((Date.now() - this.questionStartTime) / 1000);
    const ans = this.answers.get(this.currentQuestion.question_id);
    if (ans) ans.time_spent_seconds = (ans.time_spent_seconds || 0) + elapsed;
  }

  // === Auto-save ===
  startAutoSave(): void {
    // A steady heartbeat that saves the answer being worked on.
    interval(30000).pipe(takeUntil(this.destroy$)).subscribe(() => {
      if (this.currentQuestion) this.saveOne(this.currentQuestion.question_id);
    });
  }

  triggerAutoSave(): void {
    if (this.autoSaveTimeout) clearTimeout(this.autoSaveTimeout);
    this.autoSaveTimeout = setTimeout(() => {
      const id = this.pendingAnswerId;
      if (id) this.saveOne(id);
    }, 1500);
  }

  /** Saves one question's answer through the new API. */
  private saveOne(questionId: string): void {
    if (!this.attempt) return;
    const a = this.answers.get(questionId);
    if (!a) return;
    this.saveTimeSpent();
    this.autoSaveStatus = 'saving';
    this.assessments.saveAnswer(this.attempt.attempt_id, questionId, this.toApiAnswer(a))
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => { this.autoSaveStatus = 'saved'; setTimeout(() => this.autoSaveStatus = null, 2000); },
        error: () => { this.autoSaveStatus = 'error'; setTimeout(() => this.autoSaveStatus = null, 3000); },
      });
    this.questionStartTime = Date.now();
  }

  /** The renderer's answer shape -> the payload the grader reads. */
  private toApiAnswer(a: QuestionAnswer): Record<string, unknown> {
    switch (a.answer_type) {
      case 'multiple_choice':
      case 'multiple_response':
      case 'true_false':
        return { optionIds: a.selected_option_ids ?? [] };
      case 'ordering':
        return { optionIds: a.order_answer ?? [] };
      case 'short_answer':
      case 'long_answer':
        return { text: a.text_answer ?? '' };
      case 'numeric':
        return { value: a.numeric_answer };
      case 'fill_blank':
        return { texts: a.blank_answers ?? [] };
      case 'matching':
        return { pairs: Object.fromEntries((a.match_answers ?? []).map(m => [m.premise_id, m.response_id])) };
      default:
        return { optionIds: a.selected_option_ids ?? [] };
    }
  }

  // === Submit ===
  confirmSubmit(): void { this.showSubmitModal = true; }

  submitAssessment(): void {
    if (!this.attempt || this.submitting) return;
    this.submitting = true;
    this.saveTimeSpent();
    const attempt = this.attempt;
    // Save every answer, then submit — answers already save on change, but this
    // catches anything typed in the last moment.
    const saves = Array.from(this.answers.entries()).map(([qid, a]) =>
      this.assessments.saveAnswer(attempt.attempt_id, qid, this.toApiAnswer(a)).pipe(catchError(() => of(null))),
    );
    const finalize = () => {
      this.assessments.submitAttempt(attempt.attempt_id).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.submitting = false;
          if (this.timerInterval) clearInterval(this.timerInterval);
          void this.router.navigate(['/learner/assessments', attempt.assessment_id, 'results', attempt.attempt_id]);
        },
        error: () => { this.submitting = false; alert('Submission failed. Please try again.'); },
      });
    };
    if (saves.length === 0) { finalize(); return; }
    forkJoin(saves).pipe(takeUntil(this.destroy$)).subscribe({ next: finalize, error: finalize });
  }

  forceSubmit(): void {
    this.showSubmitModal = false;
    this.submitAssessment();
  }

  // === Proctoring ===
  onProctoringViolation(event: { type?: string } | undefined): void {
    if (!this.attempt) return;
    this.assessments.recordViolation(this.attempt.attempt_id, {
      type: event?.type ?? 'unknown',
      questionId: this.currentQuestion?.question_id ?? null,
      questionIndex: this.currentGlobalIndex,
    }).pipe(takeUntil(this.destroy$)).subscribe({ error: () => undefined });
  }
}
