import { Component, OnInit, OnDestroy, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil, interval } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

interface AssessmentDetail {
  id: number;
  title: string;
  description: string;
  type: string;
  status: string;
  time_limit_minutes: number | null;
  passing_score: number;
  passing_score_type: string;
  total_questions: number;
  total_points: number;
  instructions: string;
  welcome_message: string;
  completion_message: string;
  shuffle_questions: number;
  shuffle_options: number;
  show_results: number;
  show_correct_answers: number;
  show_explanations: number;
  show_score: number;
  show_progress_bar: number;
  show_question_numbers: number;
  questions_per_page: number;
  navigation_type: string;
  proctoring_level: string;
  negative_marking: number;
  negative_marking_penalty: number;
  allow_partial_credit: number;
  sections: PreviewSection[];
}

interface PreviewSection {
  id: number;
  title: string;
  description: string;
  sort_order: number;
  questions: PreviewQuestion[];
}

interface PreviewQuestion {
  id: number;
  section_id: number;
  question_text: string;
  question_type: string;
  points: number;
  options: any[];
  correct_answer: any;
  explanation: string;
  media_url: string;
  sort_order: number;
}

interface QuestionState {
  questionId: number;
  sectionIndex: number;
  questionIndex: number;
  globalIndex: number;
  answered: boolean;
  flagged: boolean;
  answer: any;
  correct?: boolean;
  pointsEarned?: number;
}

interface ApiResponse<T> { status: string; data: T; message?: string; }

type PreviewScreen = 'instructions' | 'in-progress' | 'review' | 'results';

@Component({
  selector: 'app-assessment-preview',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <!-- Preview Banner -->
    <div class="preview-banner">
      <div class="banner-left">
        <tui-icon icon="@tui.eye"></tui-icon>
        <span>Preview Mode</span>
        <span class="banner-note">- Responses are not saved</span>
      </div>
      <button class="btn-exit" (click)="onExit.emit()">
        <tui-icon icon="@tui.x"></tui-icon> Exit Preview
      </button>
    </div>

    <div class="preview-container" *ngIf="!loading && assessment">

      <!-- INSTRUCTIONS SCREEN -->
      <div class="screen instructions-screen" *ngIf="currentScreen === 'instructions'">
        <div class="instructions-card">
          <div class="instructions-header">
            <div class="assessment-badges">
              <span class="badge type-badge" [class]="'type-' + assessment.type">{{ assessment.type | titlecase }}</span>
              <span class="badge procto-badge" *ngIf="assessment.proctoring_level !== 'none'">
                <tui-icon icon="@tui.shield" style="font-size:14px"></tui-icon>
                {{ assessment.proctoring_level | titlecase }} Proctoring
              </span>
            </div>
            <h1>{{ assessment.title }}</h1>
            <p class="description" *ngIf="assessment.description">{{ assessment.description }}</p>
          </div>

          <div class="welcome-msg" *ngIf="assessment.welcome_message">
            <div [innerHTML]="assessment.welcome_message"></div>
          </div>

          <div class="info-grid">
            <div class="info-item">
              <tui-icon icon="@tui.help-circle"></tui-icon>
              <div>
                <span class="info-label">Questions</span>
                <span class="info-value">{{ assessment.total_questions }}</span>
              </div>
            </div>
            <div class="info-item">
              <tui-icon icon="@tui.star"></tui-icon>
              <div>
                <span class="info-label">Total Points</span>
                <span class="info-value">{{ assessment.total_points }}</span>
              </div>
            </div>
            <div class="info-item" *ngIf="assessment.time_limit_minutes">
              <tui-icon icon="@tui.clock"></tui-icon>
              <div>
                <span class="info-label">Time Limit</span>
                <span class="info-value">{{ assessment.time_limit_minutes }} minutes</span>
              </div>
            </div>
            <div class="info-item">
              <tui-icon icon="@tui.target"></tui-icon>
              <div>
                <span class="info-label">Passing Score</span>
                <span class="info-value">{{ assessment.passing_score }}{{ assessment.passing_score_type === 'percentage' ? '%' : ' pts' }}</span>
              </div>
            </div>
            <div class="info-item" *ngIf="assessment.negative_marking">
              <tui-icon icon="@tui.alert-triangle"></tui-icon>
              <div>
                <span class="info-label">Negative Marking</span>
                <span class="info-value">-{{ assessment.negative_marking_penalty }}% per wrong answer</span>
              </div>
            </div>
            <div class="info-item">
              <tui-icon icon="@tui.navigation"></tui-icon>
              <div>
                <span class="info-label">Navigation</span>
                <span class="info-value">{{ assessment.navigation_type === 'free' ? 'Free navigation' : assessment.navigation_type === 'linear' ? 'Linear (forward only)' : 'Section-based' }}</span>
              </div>
            </div>
          </div>

          <div class="instructions-block" *ngIf="assessment.instructions">
            <h3>Instructions</h3>
            <div [innerHTML]="assessment.instructions"></div>
          </div>

          <div class="proctoring-requirements" *ngIf="assessment.proctoring_level !== 'none'">
            <h3><tui-icon icon="@tui.shield"></tui-icon> Proctoring Requirements</h3>
            <ul>
              <li *ngIf="assessment.proctoring_level === 'basic' || assessment.proctoring_level === 'standard' || assessment.proctoring_level === 'advanced'">
                <tui-icon icon="@tui.check" style="color:#22c55e;font-size:14px"></tui-icon> Tab switching will be monitored
              </li>
              <li *ngIf="assessment.proctoring_level === 'standard' || assessment.proctoring_level === 'advanced'">
                <tui-icon icon="@tui.check" style="color:#22c55e;font-size:14px"></tui-icon> Webcam access required
              </li>
              <li *ngIf="assessment.proctoring_level === 'standard' || assessment.proctoring_level === 'advanced'">
                <tui-icon icon="@tui.check" style="color:#22c55e;font-size:14px"></tui-icon> Periodic snapshots will be taken
              </li>
              <li *ngIf="assessment.proctoring_level === 'advanced'">
                <tui-icon icon="@tui.check" style="color:#22c55e;font-size:14px"></tui-icon> Screen recording will be active
              </li>
              <li *ngIf="assessment.proctoring_level === 'advanced'">
                <tui-icon icon="@tui.check" style="color:#22c55e;font-size:14px"></tui-icon> Full-screen mode enforced
              </li>
            </ul>
          </div>

          <button class="btn-start" (click)="startAssessment()">
            <tui-icon icon="@tui.play"></tui-icon> Start Assessment
          </button>
        </div>
      </div>

      <!-- IN-PROGRESS SCREEN -->
      <div class="screen progress-screen" *ngIf="currentScreen === 'in-progress'">
        <!-- Header Bar -->
        <div class="progress-header">
          <div class="header-left">
            <h2>{{ assessment.title }}</h2>
            <span class="question-counter">Question {{ currentGlobalIndex + 1 }} of {{ allQuestions.length }}</span>
          </div>
          <div class="header-right">
            <div class="timer" *ngIf="assessment.time_limit_minutes" [class.warning]="timerWarning" [class.danger]="timerDanger">
              <tui-icon icon="@tui.clock"></tui-icon>
              <span>{{ formatTime(remainingSeconds) }}</span>
            </div>
            <button class="btn-flag" [class.flagged]="currentQuestionState?.flagged" (click)="toggleFlag()">
              <tui-icon icon="@tui.flag"></tui-icon>
            </button>
            <button class="btn-submit-final" (click)="confirmSubmit()">Submit</button>
          </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-bar-container" *ngIf="assessment.show_progress_bar">
          <div class="progress-fill" [style.width.%]="progressPercent"></div>
        </div>

        <!-- Main Content -->
        <div class="progress-body">
          <!-- Question Area -->
          <div class="question-area">
            <!-- Section Header -->
            <div class="section-header" *ngIf="currentSection">
              <span class="section-label">Section: {{ currentSection.title }}</span>
            </div>

            <div class="question-card">
              <div class="question-number" *ngIf="assessment.show_question_numbers">
                <span>Q{{ currentGlobalIndex + 1 }}</span>
                <span class="question-points">{{ currentQuestion?.points }} {{ currentQuestion?.points === 1 ? 'point' : 'points' }}</span>
              </div>

              <div class="question-text" [innerHTML]="currentQuestion?.question_text"></div>

              <div class="question-media" *ngIf="currentQuestion?.media_url">
                <img [src]="currentQuestion?.media_url" alt="Question media" />
              </div>

              <!-- Multiple Choice -->
              <div class="options-list" *ngIf="currentQuestion?.question_type === 'multiple_choice' || currentQuestion?.question_type === 'true_false'">
                <label class="option-item" *ngFor="let opt of currentQuestion?.options; let i = index"
                       [class.selected]="currentQuestionState?.answer === opt.id"
                       (click)="selectAnswer(opt.id)">
                  <span class="option-radio" [class.checked]="currentQuestionState?.answer === opt.id"></span>
                  <span class="option-text">{{ opt.text || opt.label }}</span>
                </label>
              </div>

              <!-- Multiple Select -->
              <div class="options-list" *ngIf="currentQuestion?.question_type === 'multiple_select'">
                <label class="option-item" *ngFor="let opt of currentQuestion?.options; let i = index"
                       [class.selected]="isOptionSelected(opt.id)"
                       (click)="toggleOption(opt.id)">
                  <span class="option-checkbox" [class.checked]="isOptionSelected(opt.id)"></span>
                  <span class="option-text">{{ opt.text || opt.label }}</span>
                </label>
              </div>

              <!-- Short Answer -->
              <div class="text-answer" *ngIf="currentQuestion?.question_type === 'short_answer'">
                <input type="text" class="form-input" placeholder="Type your answer..."
                       [ngModel]="currentQuestionState?.answer" (ngModelChange)="updateTextAnswer($event)" />
              </div>

              <!-- Essay / Long Answer -->
              <div class="text-answer" *ngIf="currentQuestion?.question_type === 'essay' || currentQuestion?.question_type === 'long_answer'">
                <textarea class="form-textarea" placeholder="Type your answer..." rows="8"
                          [ngModel]="currentQuestionState?.answer" (ngModelChange)="updateTextAnswer($event)"></textarea>
                <div class="word-count" *ngIf="currentQuestionState?.answer">
                  {{ getWordCount(currentQuestionState?.answer) }} words
                </div>
              </div>

              <!-- Fill in the Blank -->
              <div class="fill-blank" *ngIf="currentQuestion?.question_type === 'fill_in_blank'">
                <input type="text" class="form-input" placeholder="Fill in the blank..."
                       [ngModel]="currentQuestionState?.answer" (ngModelChange)="updateTextAnswer($event)" />
              </div>

              <!-- Matching -->
              <div class="matching-area" *ngIf="currentQuestion?.question_type === 'matching'">
                <div class="match-pair" *ngFor="let item of currentQuestion?.options; let i = index">
                  <span class="match-left">{{ item.left || item.premise }}</span>
                  <tui-icon icon="@tui.arrow-right" style="color:#94a3b8"></tui-icon>
                  <select class="form-select" [ngModel]="getMatchAnswer(i)" (ngModelChange)="setMatchAnswer(i, $event)">
                    <option value="">Select match...</option>
                    <option *ngFor="let choice of getMatchChoices()" [value]="choice">{{ choice }}</option>
                  </select>
                </div>
              </div>

              <!-- Ordering -->
              <div class="ordering-area" *ngIf="currentQuestion?.question_type === 'ordering'">
                <div class="order-item" *ngFor="let item of getOrderItems(); let i = index">
                  <span class="order-number">{{ i + 1 }}</span>
                  <span class="order-text">{{ item.text || item }}</span>
                  <div class="order-actions">
                    <button class="btn-icon-sm" (click)="moveOrderItem(i, -1)" [disabled]="i === 0">
                      <tui-icon icon="@tui.chevron-up"></tui-icon>
                    </button>
                    <button class="btn-icon-sm" (click)="moveOrderItem(i, 1)" [disabled]="i === getOrderItems().length - 1">
                      <tui-icon icon="@tui.chevron-down"></tui-icon>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Numeric -->
              <div class="numeric-answer" *ngIf="currentQuestion?.question_type === 'numeric'">
                <input type="number" class="form-input" placeholder="Enter a number..."
                       [ngModel]="currentQuestionState?.answer" (ngModelChange)="updateTextAnswer($event)" />
              </div>

              <!-- Hotspot (simplified for preview) -->
              <div class="hotspot-area" *ngIf="currentQuestion?.question_type === 'hotspot'">
                <div class="hotspot-placeholder">
                  <tui-icon icon="@tui.mouse-pointer" style="font-size:24px"></tui-icon>
                  <p>Click on the image to select hotspot area</p>
                  <small>Hotspot interaction is simplified in preview mode</small>
                </div>
              </div>
            </div>

            <!-- Navigation Buttons -->
            <div class="question-nav">
              <button class="btn-nav" (click)="previousQuestion()"
                      [disabled]="currentGlobalIndex === 0 || assessment.navigation_type === 'linear'">
                <tui-icon icon="@tui.chevron-left"></tui-icon> Previous
              </button>
              <button class="btn-nav primary" *ngIf="currentGlobalIndex < allQuestions.length - 1" (click)="nextQuestion()">
                Next <tui-icon icon="@tui.chevron-right"></tui-icon>
              </button>
              <button class="btn-nav submit" *ngIf="currentGlobalIndex === allQuestions.length - 1" (click)="confirmSubmit()">
                Finish & Submit
              </button>
            </div>
          </div>

          <!-- Navigation Panel (sidebar) -->
          <div class="nav-panel" *ngIf="assessment.navigation_type === 'free'">
            <h4>Questions</h4>
            <div class="nav-grid">
              <button class="nav-cell" *ngFor="let qs of questionStates; let i = index"
                      [class.current]="i === currentGlobalIndex"
                      [class.answered]="qs.answered"
                      [class.flagged]="qs.flagged"
                      (click)="goToQuestion(i)">
                {{ i + 1 }}
              </button>
            </div>
            <div class="nav-legend">
              <span><span class="legend-dot current"></span> Current</span>
              <span><span class="legend-dot answered"></span> Answered</span>
              <span><span class="legend-dot flagged"></span> Flagged</span>
              <span><span class="legend-dot"></span> Unanswered</span>
            </div>
            <div class="nav-summary">
              <div>Answered: {{ answeredCount }} / {{ allQuestions.length }}</div>
              <div>Flagged: {{ flaggedCount }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- SUBMIT CONFIRMATION MODAL -->
      <div class="modal-overlay" *ngIf="showSubmitConfirm" (click)="showSubmitConfirm = false">
        <div class="modal-card" (click)="$event.stopPropagation()">
          <h3>Submit Assessment?</h3>
          <div class="submit-summary">
            <div class="summary-row">
              <span>Total Questions</span>
              <span>{{ allQuestions.length }}</span>
            </div>
            <div class="summary-row">
              <span>Answered</span>
              <span class="text-green">{{ answeredCount }}</span>
            </div>
            <div class="summary-row" *ngIf="unansweredCount > 0">
              <span>Unanswered</span>
              <span class="text-orange">{{ unansweredCount }}</span>
            </div>
            <div class="summary-row" *ngIf="flaggedCount > 0">
              <span>Flagged for Review</span>
              <span class="text-blue">{{ flaggedCount }}</span>
            </div>
          </div>
          <p class="submit-warning" *ngIf="unansweredCount > 0">
            You have {{ unansweredCount }} unanswered question{{ unansweredCount > 1 ? 's' : '' }}. Are you sure you want to submit?
          </p>
          <div class="modal-actions">
            <button class="btn-secondary" (click)="showSubmitConfirm = false">Go Back</button>
            <button class="btn-primary" (click)="submitAssessment()">Confirm Submit</button>
          </div>
        </div>
      </div>

      <!-- REVIEW SCREEN -->
      <div class="screen review-screen" *ngIf="currentScreen === 'review'">
        <div class="review-card">
          <h2>Assessment Review</h2>
          <p class="review-subtitle">Review your answers before viewing results</p>

          <div class="review-section" *ngFor="let section of assessment.sections; let si = index">
            <h3 class="review-section-title" *ngIf="assessment.sections.length > 1">
              Section {{ si + 1 }}: {{ section.title }}
            </h3>

            <div class="review-question" *ngFor="let q of section.questions; let qi = index"
                 [class.correct]="getQuestionState(q.id)?.correct === true"
                 [class.incorrect]="getQuestionState(q.id)?.correct === false">
              <div class="review-q-header">
                <span class="review-q-num">Q{{ getQuestionState(q.id)?.globalIndex! + 1 }}</span>
                <span class="review-q-type">{{ formatQuestionType(q.question_type) }}</span>
                <span class="review-q-points">
                  <span *ngIf="assessment.show_results">{{ getQuestionState(q.id)?.pointsEarned || 0 }} / </span>{{ q.points }} pts
                </span>
                <span class="review-q-status" *ngIf="assessment.show_results">
                  <tui-icon *ngIf="getQuestionState(q.id)?.correct" icon="@tui.check-circle" style="color:#22c55e"></tui-icon>
                  <tui-icon *ngIf="getQuestionState(q.id)?.correct === false" icon="@tui.x-circle" style="color:#ef4444"></tui-icon>
                </span>
              </div>

              <div class="review-q-text" [innerHTML]="q.question_text"></div>

              <div class="review-answer">
                <span class="answer-label">Your Answer:</span>
                <span class="answer-value" [class.no-answer]="!getQuestionState(q.id)?.answered">
                  {{ formatAnswer(q, getQuestionState(q.id)?.answer) || 'Not answered' }}
                </span>
              </div>

              <div class="review-correct" *ngIf="assessment.show_correct_answers && getQuestionState(q.id)?.correct === false">
                <span class="answer-label correct-label">Correct Answer:</span>
                <span class="answer-value">{{ formatCorrectAnswer(q) }}</span>
              </div>

              <div class="review-explanation" *ngIf="assessment.show_explanations && q.explanation">
                <tui-icon icon="@tui.info" style="font-size:14px;color:#6366f1"></tui-icon>
                <span [innerHTML]="q.explanation"></span>
              </div>
            </div>
          </div>

          <button class="btn-primary" (click)="currentScreen = 'results'" *ngIf="assessment.show_results">
            View Results <tui-icon icon="@tui.arrow-right"></tui-icon>
          </button>
        </div>
      </div>

      <!-- RESULTS SCREEN -->
      <div class="screen results-screen" *ngIf="currentScreen === 'results'">
        <div class="results-card">
          <div class="results-icon" [class.pass]="passed" [class.fail]="!passed">
            <tui-icon [icon]="passed ? '@tui.check-circle' : '@tui.x-circle'"></tui-icon>
          </div>
          <h2>{{ passed ? 'Congratulations!' : 'Assessment Complete' }}</h2>
          <p class="results-status" [class.pass]="passed" [class.fail]="!passed">
            {{ passed ? 'You passed!' : 'You did not meet the passing score.' }}
          </p>

          <div class="score-display">
            <div class="score-circle" [class.pass]="passed" [class.fail]="!passed">
              <span class="score-value">{{ scorePercent }}%</span>
              <span class="score-label">Score</span>
            </div>
          </div>

          <div class="results-grid">
            <div class="result-item">
              <span class="result-label">Points Earned</span>
              <span class="result-value">{{ totalPointsEarned }} / {{ assessment.total_points }}</span>
            </div>
            <div class="result-item">
              <span class="result-label">Correct Answers</span>
              <span class="result-value">{{ correctCount }} / {{ allQuestions.length }}</span>
            </div>
            <div class="result-item">
              <span class="result-label">Passing Score</span>
              <span class="result-value">{{ assessment.passing_score }}{{ assessment.passing_score_type === 'percentage' ? '%' : ' pts' }}</span>
            </div>
            <div class="result-item">
              <span class="result-label">Time Taken</span>
              <span class="result-value">{{ formatTime(timeTaken) }}</span>
            </div>
          </div>

          <!-- Section Breakdown -->
          <div class="section-breakdown" *ngIf="assessment.sections.length > 1">
            <h3>Section Breakdown</h3>
            <div class="breakdown-item" *ngFor="let sb of sectionBreakdown">
              <div class="breakdown-header">
                <span>{{ sb.title }}</span>
                <span>{{ sb.earned }} / {{ sb.total }} pts ({{ sb.percent }}%)</span>
              </div>
              <div class="breakdown-bar">
                <div class="breakdown-fill" [style.width.%]="sb.percent"
                     [class.high]="sb.percent >= 70" [class.mid]="sb.percent >= 40 && sb.percent < 70"
                     [class.low]="sb.percent < 40"></div>
              </div>
            </div>
          </div>

          <div class="completion-msg" *ngIf="assessment.completion_message">
            <div [innerHTML]="assessment.completion_message"></div>
          </div>

          <div class="results-actions">
            <button class="btn-secondary" (click)="currentScreen = 'review'">
              <tui-icon icon="@tui.list"></tui-icon> Review Answers
            </button>
            <button class="btn-primary" (click)="onExit.emit()">
              <tui-icon icon="@tui.x"></tui-icon> Exit Preview
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div class="loading-container" *ngIf="loading">
      <div class="spinner"></div>
      <p>Loading assessment preview...</p>
    </div>
  `,
  styles: [`
    :host { display: block; background: #f1f5f9; min-height: 100vh; }

    .preview-banner {
      background: linear-gradient(135deg, #7c3aed, #6366f1);
      color: white; padding: 10px 24px; display: flex; align-items: center;
      justify-content: space-between; position: sticky; top: 0; z-index: 100;
    }
    .banner-left { display: flex; align-items: center; gap: 8px; font-weight: 600; }
    .banner-note { font-weight: 400; opacity: 0.8; font-size: 13px; }
    .btn-exit {
      background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);
      color: white; padding: 6px 16px; border-radius: 6px; cursor: pointer;
      display: flex; align-items: center; gap: 6px; font-size: 13px;
    }
    .btn-exit:hover { background: rgba(255,255,255,0.3); }

    .preview-container { max-width: 1200px; margin: 0 auto; padding: 24px; }

    /* INSTRUCTIONS SCREEN */
    .instructions-card {
      background: white; border-radius: 12px; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .instructions-header { text-align: center; margin-bottom: 32px; }
    .instructions-header h1 { font-size: 28px; font-weight: 700; color: #0f172a; margin: 16px 0 8px; }
    .description { color: #64748b; font-size: 15px; max-width: 600px; margin: 0 auto; }
    .assessment-badges { display: flex; gap: 8px; justify-content: center; }
    .badge {
      padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase;
    }
    .type-badge { background: #ede9fe; color: #7c3aed; }
    .type-quiz { background: #dbeafe; color: #2563eb; }
    .type-exam { background: #fce7f3; color: #db2777; }
    .type-practice { background: #d1fae5; color: #059669; }
    .type-survey { background: #fef3c7; color: #d97706; }
    .procto-badge { background: #fef2f2; color: #dc2626; display: flex; align-items: center; gap: 4px; }

    .welcome-msg {
      background: #f0fdf4; border-left: 4px solid #22c55e; padding: 16px 20px;
      border-radius: 0 8px 8px 0; margin-bottom: 24px;
    }

    .info-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 32px;
    }
    .info-item {
      display: flex; align-items: center; gap: 12px; padding: 16px;
      background: #f8fafc; border-radius: 8px;
    }
    .info-item tui-icon { color: #6366f1; font-size: 20px; }
    .info-label { display: block; font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
    .info-value { display: block; font-size: 18px; font-weight: 700; color: #0f172a; }

    .instructions-block { margin-bottom: 24px; }
    .instructions-block h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 12px; }

    .proctoring-requirements {
      background: #fef2f2; border-radius: 8px; padding: 20px; margin-bottom: 32px;
    }
    .proctoring-requirements h3 {
      display: flex; align-items: center; gap: 8px; font-size: 16px;
      font-weight: 600; color: #dc2626; margin-bottom: 12px;
    }
    .proctoring-requirements ul { list-style: none; padding: 0; margin: 0; }
    .proctoring-requirements li {
      display: flex; align-items: center; gap: 8px; padding: 6px 0; color: #334155; font-size: 14px;
    }

    .btn-start {
      display: flex; align-items: center; gap: 8px; margin: 0 auto;
      background: #6366f1; color: white; border: none; padding: 14px 40px;
      border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer;
    }
    .btn-start:hover { background: #4f46e5; }

    /* IN-PROGRESS SCREEN */
    .progress-header {
      background: white; padding: 16px 24px; border-radius: 12px;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 4px;
    }
    .header-left h2 { font-size: 18px; font-weight: 600; color: #0f172a; margin: 0; }
    .question-counter { font-size: 13px; color: #64748b; }
    .header-right { display: flex; align-items: center; gap: 12px; }
    .timer {
      display: flex; align-items: center; gap: 6px; font-size: 18px;
      font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums;
    }
    .timer.warning { color: #f59e0b; }
    .timer.danger { color: #ef4444; animation: pulse 1s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }

    .btn-flag {
      background: #f1f5f9; border: 1px solid #e2e8f0; padding: 8px; border-radius: 6px; cursor: pointer;
    }
    .btn-flag.flagged { background: #fef3c7; border-color: #f59e0b; color: #f59e0b; }
    .btn-submit-final {
      background: #6366f1; color: white; border: none; padding: 8px 20px;
      border-radius: 6px; font-weight: 600; cursor: pointer;
    }

    .progress-bar-container {
      height: 4px; background: #e2e8f0; border-radius: 2px; margin-bottom: 20px; overflow: hidden;
    }
    .progress-fill { height: 100%; background: #6366f1; transition: width 0.3s; border-radius: 2px; }

    .progress-body { display: flex; gap: 20px; }
    .question-area { flex: 1; min-width: 0; }

    .section-header {
      background: #ede9fe; padding: 8px 16px; border-radius: 8px; margin-bottom: 12px;
    }
    .section-label { font-size: 13px; font-weight: 600; color: #6366f1; }

    .question-card {
      background: white; border-radius: 12px; padding: 32px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .question-number {
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;
    }
    .question-number span:first-child {
      background: #6366f1; color: white; padding: 4px 12px; border-radius: 6px;
      font-weight: 700; font-size: 14px;
    }
    .question-points { font-size: 13px; color: #64748b; }
    .question-text { font-size: 16px; line-height: 1.6; color: #0f172a; margin-bottom: 24px; }
    .question-media { margin-bottom: 20px; }
    .question-media img { max-width: 100%; border-radius: 8px; }

    /* Options */
    .options-list { display: flex; flex-direction: column; gap: 10px; }
    .option-item {
      display: flex; align-items: center; gap: 12px; padding: 14px 16px;
      border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer; transition: all 0.15s;
    }
    .option-item:hover { border-color: #c7d2fe; background: #f8fafc; }
    .option-item.selected { border-color: #6366f1; background: #ede9fe; }
    .option-radio {
      width: 20px; height: 20px; border-radius: 50%; border: 2px solid #cbd5e1;
      flex-shrink: 0; position: relative;
    }
    .option-radio.checked { border-color: #6366f1; }
    .option-radio.checked::after {
      content: ''; position: absolute; top: 3px; left: 3px; width: 10px; height: 10px;
      background: #6366f1; border-radius: 50%;
    }
    .option-checkbox {
      width: 20px; height: 20px; border-radius: 4px; border: 2px solid #cbd5e1;
      flex-shrink: 0; position: relative;
    }
    .option-checkbox.checked { border-color: #6366f1; background: #6366f1; }
    .option-checkbox.checked::after {
      content: '✓'; position: absolute; top: -1px; left: 3px; color: white;
      font-size: 14px; font-weight: 700;
    }
    .option-text { font-size: 15px; color: #334155; }

    /* Text Inputs */
    .form-input, .form-textarea, .form-select {
      width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 8px;
      font-size: 15px; color: #0f172a; outline: none; font-family: inherit;
    }
    .form-input:focus, .form-textarea:focus, .form-select:focus { border-color: #6366f1; }
    .form-textarea { resize: vertical; }
    .word-count { text-align: right; font-size: 12px; color: #94a3b8; margin-top: 4px; }

    /* Matching */
    .matching-area { display: flex; flex-direction: column; gap: 12px; }
    .match-pair { display: flex; align-items: center; gap: 12px; }
    .match-left {
      flex: 1; padding: 10px 14px; background: #f8fafc; border-radius: 6px;
      font-size: 14px; font-weight: 500;
    }
    .form-select { flex: 1; }

    /* Ordering */
    .ordering-area { display: flex; flex-direction: column; gap: 8px; }
    .order-item {
      display: flex; align-items: center; gap: 12px; padding: 12px 16px;
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
    }
    .order-number {
      width: 28px; height: 28px; background: #6366f1; color: white; border-radius: 50%;
      display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px;
    }
    .order-text { flex: 1; font-size: 14px; }
    .order-actions { display: flex; gap: 4px; }
    .btn-icon-sm {
      background: white; border: 1px solid #e2e8f0; padding: 4px; border-radius: 4px;
      cursor: pointer; display: flex;
    }
    .btn-icon-sm:disabled { opacity: 0.3; cursor: default; }

    /* Hotspot placeholder */
    .hotspot-placeholder {
      text-align: center; padding: 40px; background: #f8fafc; border: 2px dashed #e2e8f0;
      border-radius: 8px; color: #94a3b8;
    }
    .hotspot-placeholder p { margin: 12px 0 4px; font-weight: 500; }

    /* Navigation */
    .question-nav {
      display: flex; justify-content: space-between; margin-top: 20px; gap: 12px;
    }
    .btn-nav {
      display: flex; align-items: center; gap: 6px; padding: 10px 20px;
      border: 1px solid #e2e8f0; border-radius: 8px; background: white;
      font-size: 14px; font-weight: 500; cursor: pointer; color: #334155;
    }
    .btn-nav:disabled { opacity: 0.4; cursor: default; }
    .btn-nav.primary { background: #6366f1; color: white; border-color: #6366f1; margin-left: auto; }
    .btn-nav.submit { background: #22c55e; color: white; border-color: #22c55e; margin-left: auto; }

    /* Nav Panel Sidebar */
    .nav-panel {
      width: 220px; flex-shrink: 0; background: white; border-radius: 12px;
      padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); align-self: flex-start;
      position: sticky; top: 70px;
    }
    .nav-panel h4 { font-size: 14px; font-weight: 600; color: #0f172a; margin: 0 0 12px; }
    .nav-grid {
      display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-bottom: 16px;
    }
    .nav-cell {
      width: 36px; height: 36px; border-radius: 6px; border: 2px solid #e2e8f0;
      background: white; font-size: 13px; font-weight: 600; cursor: pointer;
      display: flex; align-items: center; justify-content: center; color: #64748b;
    }
    .nav-cell.current { border-color: #6366f1; background: #ede9fe; color: #6366f1; }
    .nav-cell.answered { background: #d1fae5; border-color: #22c55e; color: #059669; }
    .nav-cell.flagged { border-color: #f59e0b; }
    .nav-cell.flagged::after { content: '⚑'; position: absolute; top: -2px; right: 0; font-size: 8px; }
    .nav-cell { position: relative; }
    .nav-legend { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
    .nav-legend span { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #64748b; }
    .legend-dot {
      width: 12px; height: 12px; border-radius: 3px; border: 2px solid #e2e8f0; background: white;
    }
    .legend-dot.current { border-color: #6366f1; background: #ede9fe; }
    .legend-dot.answered { background: #d1fae5; border-color: #22c55e; }
    .legend-dot.flagged { border-color: #f59e0b; }
    .nav-summary { font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 12px; }
    .nav-summary div { margin-bottom: 4px; }

    /* SUBMIT CONFIRMATION */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex;
      align-items: center; justify-content: center; z-index: 200;
    }
    .modal-card {
      background: white; border-radius: 12px; padding: 32px; max-width: 440px; width: 100%;
    }
    .modal-card h3 { font-size: 20px; font-weight: 700; margin: 0 0 20px; }
    .submit-summary { margin-bottom: 16px; }
    .summary-row {
      display: flex; justify-content: space-between; padding: 8px 0;
      border-bottom: 1px solid #f1f5f9; font-size: 14px;
    }
    .text-green { color: #22c55e; font-weight: 600; }
    .text-orange { color: #f59e0b; font-weight: 600; }
    .text-blue { color: #3b82f6; font-weight: 600; }
    .submit-warning { color: #f59e0b; font-size: 14px; margin-bottom: 20px; }
    .modal-actions { display: flex; gap: 12px; justify-content: flex-end; }
    .btn-secondary {
      padding: 10px 20px; border: 1px solid #e2e8f0; border-radius: 8px;
      background: white; cursor: pointer; font-weight: 500;
    }
    .btn-primary {
      padding: 10px 20px; border: none; border-radius: 8px; background: #6366f1;
      color: white; cursor: pointer; font-weight: 600;
    }

    /* REVIEW SCREEN */
    .review-card {
      background: white; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .review-card h2 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
    .review-subtitle { color: #64748b; margin-bottom: 24px; }
    .review-section-title { font-size: 18px; font-weight: 600; color: #6366f1; margin: 24px 0 16px; }
    .review-question {
      border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 12px;
    }
    .review-question.correct { border-left: 4px solid #22c55e; }
    .review-question.incorrect { border-left: 4px solid #ef4444; }
    .review-q-header {
      display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
    }
    .review-q-num {
      background: #f1f5f9; padding: 2px 10px; border-radius: 4px;
      font-weight: 700; font-size: 13px; color: #6366f1;
    }
    .review-q-type { font-size: 12px; color: #94a3b8; text-transform: uppercase; }
    .review-q-points { margin-left: auto; font-size: 13px; color: #64748b; font-weight: 500; }
    .review-q-text { font-size: 15px; line-height: 1.5; color: #0f172a; margin-bottom: 12px; }
    .review-answer, .review-correct { margin-bottom: 8px; font-size: 14px; }
    .answer-label { font-weight: 600; color: #64748b; margin-right: 8px; }
    .correct-label { color: #22c55e; }
    .answer-value { color: #0f172a; }
    .answer-value.no-answer { color: #94a3b8; font-style: italic; }
    .review-explanation {
      display: flex; align-items: flex-start; gap: 8px; background: #ede9fe;
      padding: 12px; border-radius: 6px; font-size: 14px; color: #4338ca;
    }

    /* RESULTS SCREEN */
    .results-card {
      background: white; border-radius: 12px; padding: 48px; text-align: center;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 700px; margin: 0 auto;
    }
    .results-icon tui-icon { font-size: 64px; }
    .results-icon.pass { color: #22c55e; }
    .results-icon.fail { color: #ef4444; }
    .results-card h2 { font-size: 28px; font-weight: 700; margin: 16px 0 8px; }
    .results-status { font-size: 18px; font-weight: 600; margin-bottom: 32px; }
    .results-status.pass { color: #22c55e; }
    .results-status.fail { color: #ef4444; }

    .score-display { margin-bottom: 32px; }
    .score-circle {
      width: 140px; height: 140px; border-radius: 50%; display: inline-flex;
      flex-direction: column; align-items: center; justify-content: center;
      border: 6px solid #e2e8f0;
    }
    .score-circle.pass { border-color: #22c55e; }
    .score-circle.fail { border-color: #ef4444; }
    .score-value { font-size: 36px; font-weight: 800; color: #0f172a; }
    .score-label { font-size: 13px; color: #94a3b8; text-transform: uppercase; }

    .results-grid {
      display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;
      margin-bottom: 32px; text-align: left;
    }
    .result-item {
      padding: 16px; background: #f8fafc; border-radius: 8px;
    }
    .result-label { display: block; font-size: 12px; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px; }
    .result-value { font-size: 18px; font-weight: 700; color: #0f172a; }

    .section-breakdown { text-align: left; margin-bottom: 32px; }
    .section-breakdown h3 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
    .breakdown-item { margin-bottom: 12px; }
    .breakdown-header { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 6px; }
    .breakdown-bar { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
    .breakdown-fill { height: 100%; border-radius: 4px; transition: width 0.5s; }
    .breakdown-fill.high { background: #22c55e; }
    .breakdown-fill.mid { background: #f59e0b; }
    .breakdown-fill.low { background: #ef4444; }

    .completion-msg {
      background: #f0fdf4; padding: 16px 20px; border-radius: 8px; margin-bottom: 24px;
      text-align: left;
    }
    .results-actions { display: flex; gap: 12px; justify-content: center; }

    /* Loading */
    .loading-container { display: flex; flex-direction: column; align-items: center; padding: 80px; }
    .spinner {
      width: 40px; height: 40px; border: 3px solid #e2e8f0;
      border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Responsive */
    @media (max-width: 768px) {
      .info-grid { grid-template-columns: repeat(2, 1fr); }
      .progress-body { flex-direction: column-reverse; }
      .nav-panel { width: 100%; position: static; }
      .nav-grid { grid-template-columns: repeat(8, 1fr); }
      .results-grid { grid-template-columns: 1fr; }
      .instructions-card, .question-card, .results-card { padding: 20px; }
    }
  `]
})
export class AssessmentPreviewComponent implements OnInit, OnDestroy {
  @Input() assessmentId!: number;
  @Output() onExit = new EventEmitter<void>();

  private destroy$ = new Subject<void>();
  private apiUrl = GlobalComponent.baseURL + '/user/assessment';

  loading = true;
  assessment: AssessmentDetail | null = null;
  currentScreen: PreviewScreen = 'instructions';

  // Question management
  allQuestions: PreviewQuestion[] = [];
  questionStates: QuestionState[] = [];
  currentGlobalIndex = 0;
  showSubmitConfirm = false;

  // Timer
  remainingSeconds = 0;
  timerWarning = false;
  timerDanger = false;
  startTime = 0;
  timeTaken = 0;

  // Results
  passed = false;
  scorePercent = 0;
  totalPointsEarned = 0;
  correctCount = 0;
  sectionBreakdown: { title: string; earned: number; total: number; percent: number }[] = [];

  get currentQuestion(): PreviewQuestion | null {
    return this.allQuestions[this.currentGlobalIndex] || null;
  }

  get currentSection(): PreviewSection | null {
    if (!this.currentQuestion || !this.assessment) return null;
    return this.assessment.sections.find(s =>
      s.questions.some(q => q.id === this.currentQuestion!.id)
    ) || null;
  }

  get currentQuestionState(): QuestionState | null {
    return this.questionStates[this.currentGlobalIndex] || null;
  }

  get answeredCount(): number { return this.questionStates.filter(q => q.answered).length; }
  get unansweredCount(): number { return this.allQuestions.length - this.answeredCount; }
  get flaggedCount(): number { return this.questionStates.filter(q => q.flagged).length; }
  get progressPercent(): number {
    return this.allQuestions.length ? (this.answeredCount / this.allQuestions.length) * 100 : 0;
  }

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    this.loadAssessment();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadAssessment(): void {
    const fd = new FormData();
    fd.append('action', 'get_assessment_preview');
    fd.append('assessment_id', this.assessmentId.toString());

    this.http.post<ApiResponse<AssessmentDetail>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.status === 'success') {
          this.assessment = res.data;
          this.buildQuestionList();
        }
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  buildQuestionList(): void {
    if (!this.assessment) return;
    this.allQuestions = [];
    let globalIdx = 0;

    for (let si = 0; si < this.assessment.sections.length; si++) {
      const section = this.assessment.sections[si];
      const questions = this.assessment.shuffle_questions
        ? this.shuffleArray([...section.questions]) : section.questions;

      for (let qi = 0; qi < questions.length; qi++) {
        const q = questions[qi];
        this.allQuestions.push(q);
        this.questionStates.push({
          questionId: q.id, sectionIndex: si, questionIndex: qi,
          globalIndex: globalIdx, answered: false, flagged: false, answer: null
        });
        globalIdx++;
      }
    }
  }

  startAssessment(): void {
    this.currentScreen = 'in-progress';
    this.startTime = Date.now();

    if (this.assessment?.time_limit_minutes) {
      this.remainingSeconds = this.assessment.time_limit_minutes * 60;
      this.startTimer();
    }
  }

  private startTimer(): void {
    interval(1000).pipe(takeUntil(this.destroy$)).subscribe(() => {
      if (this.currentScreen !== 'in-progress') return;
      this.remainingSeconds--;
      this.timerWarning = this.remainingSeconds <= 300 && this.remainingSeconds > 60;
      this.timerDanger = this.remainingSeconds <= 60;

      if (this.remainingSeconds <= 0) {
        this.remainingSeconds = 0;
        this.submitAssessment();
      }
    });
  }

  formatTime(totalSeconds: number): string {
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    if (h > 0) return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }

  // Answer handlers
  selectAnswer(optionId: any): void {
    if (this.currentQuestionState) {
      this.currentQuestionState.answer = optionId;
      this.currentQuestionState.answered = true;
    }
  }

  isOptionSelected(optionId: any): boolean {
    const ans = this.currentQuestionState?.answer;
    return Array.isArray(ans) ? ans.includes(optionId) : false;
  }

  toggleOption(optionId: any): void {
    if (!this.currentQuestionState) return;
    let ans = this.currentQuestionState.answer;
    if (!Array.isArray(ans)) ans = [];
    const idx = ans.indexOf(optionId);
    if (idx >= 0) ans.splice(idx, 1); else ans.push(optionId);
    this.currentQuestionState.answer = [...ans];
    this.currentQuestionState.answered = ans.length > 0;
  }

  updateTextAnswer(value: string): void {
    if (this.currentQuestionState) {
      this.currentQuestionState.answer = value;
      this.currentQuestionState.answered = !!value && value.toString().trim().length > 0;
    }
  }

  getMatchAnswer(index: number): string {
    const ans = this.currentQuestionState?.answer;
    return (ans && typeof ans === 'object' && ans[index]) || '';
  }

  setMatchAnswer(index: number, value: string): void {
    if (!this.currentQuestionState) return;
    if (!this.currentQuestionState.answer || typeof this.currentQuestionState.answer !== 'object') {
      this.currentQuestionState.answer = {};
    }
    this.currentQuestionState.answer[index] = value;
    this.currentQuestionState.answered = Object.values(this.currentQuestionState.answer).some((v: any) => !!v);
  }

  getMatchChoices(): string[] {
    if (!this.currentQuestion?.options) return [];
    return this.currentQuestion.options.map((o: any) => o.right || o.response || '');
  }

  getOrderItems(): any[] {
    if (!this.currentQuestionState?.answer && this.currentQuestion?.options) {
      this.currentQuestionState!.answer = [...this.currentQuestion.options];
      this.currentQuestionState!.answered = true;
    }
    return this.currentQuestionState?.answer || this.currentQuestion?.options || [];
  }

  moveOrderItem(index: number, direction: number): void {
    const items = this.getOrderItems();
    const newIdx = index + direction;
    if (newIdx < 0 || newIdx >= items.length) return;
    [items[index], items[newIdx]] = [items[newIdx], items[index]];
    this.currentQuestionState!.answer = [...items];
  }

  getWordCount(text: string): number {
    return text ? text.trim().split(/\s+/).filter(w => w.length > 0).length : 0;
  }

  // Navigation
  toggleFlag(): void {
    if (this.currentQuestionState) {
      this.currentQuestionState.flagged = !this.currentQuestionState.flagged;
    }
  }

  nextQuestion(): void {
    if (this.currentGlobalIndex < this.allQuestions.length - 1) this.currentGlobalIndex++;
  }

  previousQuestion(): void {
    if (this.currentGlobalIndex > 0) this.currentGlobalIndex--;
  }

  goToQuestion(index: number): void {
    this.currentGlobalIndex = index;
  }

  confirmSubmit(): void {
    this.showSubmitConfirm = true;
  }

  submitAssessment(): void {
    this.showSubmitConfirm = false;
    this.timeTaken = Math.round((Date.now() - this.startTime) / 1000);
    this.gradeAssessment();
    this.currentScreen = this.assessment?.show_results ? 'results' : 'review';
  }

  private gradeAssessment(): void {
    if (!this.assessment) return;
    this.totalPointsEarned = 0;
    this.correctCount = 0;

    for (const qs of this.questionStates) {
      const question = this.allQuestions[qs.globalIndex];
      if (!question) continue;

      const result = this.gradeQuestion(question, qs.answer);
      qs.correct = result.correct;
      qs.pointsEarned = result.points;
      this.totalPointsEarned += result.points;
      if (result.correct) this.correctCount++;
    }

    this.scorePercent = this.assessment.total_points > 0
      ? Math.round((this.totalPointsEarned / this.assessment.total_points) * 100) : 0;

    if (this.assessment.passing_score_type === 'percentage') {
      this.passed = this.scorePercent >= this.assessment.passing_score;
    } else {
      this.passed = this.totalPointsEarned >= this.assessment.passing_score;
    }

    // Section breakdown
    this.sectionBreakdown = this.assessment.sections.map(section => {
      let earned = 0, total = 0;
      for (const q of section.questions) {
        total += q.points;
        const state = this.questionStates.find(s => s.questionId === q.id);
        if (state) earned += state.pointsEarned || 0;
      }
      return {
        title: section.title, earned, total,
        percent: total > 0 ? Math.round((earned / total) * 100) : 0
      };
    });
  }

  private gradeQuestion(question: PreviewQuestion, answer: any): { correct: boolean; points: number } {
    if (!answer) return { correct: false, points: 0 };

    const type = question.question_type;
    let correct = false;

    if (type === 'multiple_choice' || type === 'true_false') {
      correct = answer == question.correct_answer;
    } else if (type === 'multiple_select') {
      const correctIds = Array.isArray(question.correct_answer) ? question.correct_answer : [];
      const answerIds = Array.isArray(answer) ? answer : [];
      correct = correctIds.length === answerIds.length && correctIds.every((id: any) => answerIds.includes(id));
    } else if (type === 'short_answer' || type === 'fill_in_blank') {
      const ca = (question.correct_answer || '').toString().trim().toLowerCase();
      correct = answer.toString().trim().toLowerCase() === ca;
    } else if (type === 'numeric') {
      correct = parseFloat(answer) === parseFloat(question.correct_answer);
    } else if (type === 'essay' || type === 'long_answer') {
      // Essays need manual grading - mark as answered but not auto-graded
      return { correct: false, points: 0 };
    } else if (type === 'matching') {
      correct = true; // Simplified - would need proper matching validation
    } else if (type === 'ordering') {
      correct = true; // Simplified - would need proper order validation
    }

    let points = correct ? question.points : 0;
    if (!correct && this.assessment?.negative_marking) {
      points = -(question.points * (this.assessment.negative_marking_penalty / 100));
    }

    return { correct, points };
  }

  getQuestionState(questionId: number): QuestionState | undefined {
    return this.questionStates.find(s => s.questionId === questionId);
  }

  formatQuestionType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  formatAnswer(question: PreviewQuestion, answer: any): string {
    if (!answer) return '';
    if (question.question_type === 'multiple_choice' || question.question_type === 'true_false') {
      const opt = question.options?.find((o: any) => o.id == answer);
      return opt ? (opt.text || opt.label) : answer.toString();
    }
    if (question.question_type === 'multiple_select' && Array.isArray(answer)) {
      return answer.map((id: any) => {
        const opt = question.options?.find((o: any) => o.id == id);
        return opt ? (opt.text || opt.label) : id;
      }).join(', ');
    }
    return answer.toString();
  }

  formatCorrectAnswer(question: PreviewQuestion): string {
    const ca = question.correct_answer;
    if (!ca) return '';
    if (question.question_type === 'multiple_choice' || question.question_type === 'true_false') {
      const opt = question.options?.find((o: any) => o.id == ca);
      return opt ? (opt.text || opt.label) : ca.toString();
    }
    if (Array.isArray(ca)) {
      return ca.map((id: any) => {
        const opt = question.options?.find((o: any) => o.id == id);
        return opt ? (opt.text || opt.label) : id;
      }).join(', ');
    }
    return ca.toString();
  }

  private shuffleArray<T>(arr: T[]): T[] {
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
  }
}
