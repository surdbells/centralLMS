// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 14
// Learner: Assessment Results Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, ActivatedRoute } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AssessmentsService } from '../../services/assessments.service';
import { TopComponent } from '../../partials/top/top.component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

interface AttemptResult {
  attempt_id: number;
  assessment_id: number;
  assessment_title: string;
  assessment_type: string;
  learner_id: number;
  learner_name: string;
  status: string;
  started_at: string;
  completed_at: string;
  duration_seconds: number;
  total_score: number;
  max_score: number;
  percentage: number;
  passed: boolean;
  passing_score: number;
  grade: string;
  attempt_number: number;
  max_attempts: number;
  remaining_attempts: number;
  show_correct_answers: boolean;
  show_explanations: boolean;
  show_score_breakdown: boolean;
  feedback_message: string;
  grading_status: string;
  has_manual_questions: boolean;
  pending_manual_count: number;
  certificate_id: number | null;
  certificate_number: string | null;
  points_earned: number;
  badges_earned: any[];
}

interface SectionResult {
  section_id: number;
  section_title: string;
  section_order: number;
  total_questions: number;
  answered_count: number;
  correct_count: number;
  partial_count: number;
  incorrect_count: number;
  skipped_count: number;
  section_score: number;
  section_max_score: number;
  section_percentage: number;
}

interface QuestionResult {
  answer_id: string;
  question_id: string;
  question_type: string;
  question_text: string;
  question_media: string | null;
  question_order: number;
  section_id: string;
  section_title: string;
  points_possible: number;
  points_earned: number;
  is_correct: boolean;
  is_partial: boolean;
  grading_status: string;
  user_answer: any;
  correct_answer: any;
  options: any[];
  matching_pairs: any[];
  ordering_items: any[];
  explanation: string | null;
  feedback: string | null;
  time_spent_seconds: number;
  flagged: boolean;
}

interface ScoreBreakdown {
  auto_graded_score: number;
  auto_graded_max: number;
  manual_graded_score: number;
  manual_graded_max: number;
  pending_score: number;
  total_questions: number;
  auto_graded_count: number;
  manual_graded_count: number;
  pending_count: number;
}

interface AttemptTimeline {
  event_type: string;
  event_data: any;
  created_at: string;
}

@Component({
  selector: 'app-assessment-results',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent],
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page results-page">
    <!-- ==========================================
         LOADING STATE
         ========================================== -->
    <div class="loading-container" *ngIf="loading">
      <div class="spinner-border text-secondary" role="status">
        <span class="visually-hidden">Loading results...</span>
      </div>
      <p class="mt-3 ui-muted">Loading your results...</p>
    </div>

    <!-- ==========================================
         ERROR STATE
         ========================================== -->
    <div class="ui-state error-container" *ngIf="error && !loading">
      <span class="ui-state__icon"><i class="bi bi-exclamation-triangle"></i></span>
      <h3>Error</h3>
      <p>{{ error }}</p>
      <button type="button" class="btn btn-outline-secondary" (click)="loadResults()">
        <i class="bi bi-arrow-clockwise me-1"></i> Retry
      </button>
    </div>

    <!-- ==========================================
         RESULTS CONTENT
         ========================================== -->
    <ng-container *ngIf="!loading && !error && result">

      <nav aria-label="breadcrumb" class="results-crumbs">
        <ol class="breadcrumb breadcrumb-sm mb-0">
          <li class="breadcrumb-item">
            <a href="javascript:void(0)" (click)="goToAssessments()">Assessments</a>
          </li>
          <li class="breadcrumb-item">
            <a href="javascript:void(0)" (click)="goToHistory()">My History</a>
          </li>
          <li class="breadcrumb-item active">Results</li>
        </ol>
      </nav>

      <!-- Result Header -->
      <header class="ui-page-header result-header" [class.passed]="result.passed" [class.failed]="!result.passed"
           [class.pending]="result.grading_status === 'pending'">
        <button type="button" class="ui-page-header__back" (click)="goToHistory()" aria-label="Back">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ result.assessment_title }}</h1>
          <div class="result-meta">
            <span class="badge" [ngClass]="getTypeBadgeClass(result.assessment_type)">
              {{ formatAssessmentType(result.assessment_type) }}
            </span>
            <span class="text-muted-light">
              <i class="bi bi-calendar3 me-1"></i>
              {{ formatDate(result.completed_at) }}
            </span>
            <span class="text-muted-light">
              <i class="bi bi-clock me-1"></i>
              {{ formatDuration(result.duration_seconds) }}
            </span>
            <span class="text-muted-light">
              Attempt {{ result.attempt_number }}<span *ngIf="result.max_attempts > 0"> of {{ result.max_attempts }}</span>
            </span>
          </div>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-outline-secondary" *ngIf="result.certificate_id"
                  (click)="viewCertificate()">
            <i class="bi bi-award me-1"></i> View Certificate
          </button>
          <button type="button" class="btn btn-primary"
                  *ngIf="!result.passed && result.remaining_attempts > 0"
                  (click)="retakeAssessment()">
            <i class="bi bi-arrow-clockwise me-1"></i> Retake
            ({{ result.remaining_attempts }} left)
          </button>
          <button type="button" class="btn btn-outline-secondary" (click)="printResults()">
            <i class="bi bi-printer me-1"></i> Print
          </button>
        </div>
      </header>

      <!-- Result Status Banner -->
      <section class="ui-section status-banner" [ngClass]="getStatusBannerClass()">
        <div class="score-display">
          <div class="score-circle" [class.passed]="result.passed"
               [class.failed]="!result.passed"
               [class.pending]="result.grading_status === 'pending'">
            <span class="score-value" *ngIf="result.grading_status !== 'pending'">
              {{ result.percentage | number:'1.0-0' }}%
            </span>
            <span class="score-value" *ngIf="result.grading_status === 'pending'">
              <i class="bi bi-hourglass-split"></i>
            </span>
            <span class="score-label">
              {{ result.grading_status === 'pending' ? 'Pending' : result.grade }}
            </span>
          </div>
        </div>
        <div class="status-copy">
          <span class="ui-pill status-pill">
            <tui-icon [icon]="getStatusIcon()"></tui-icon>
            {{ getStatusTitle() }}
          </span>
          <p class="mb-0" *ngIf="result.feedback_message">{{ result.feedback_message }}</p>
          <p class="mb-0" *ngIf="result.grading_status === 'pending' && result.pending_manual_count > 0">
            {{ result.pending_manual_count }} question(s) awaiting manual grading
          </p>
        </div>
      </section>

      <!-- Points & Badges Earned -->
      <div class="ui-insights rewards-bar" *ngIf="result.points_earned > 0 || result.badges_earned?.length">
        <span class="ui-pill ui-pill--warning reward-item" *ngIf="result.points_earned > 0">
          <i class="bi bi-star-fill"></i>
          <strong>+{{ result.points_earned }}</strong> points earned
        </span>
        <span class="ui-pill ui-pill--brand reward-item" *ngFor="let badge of result.badges_earned">
          <i class="bi bi-patch-check"></i>
          Badge: <strong>{{ badge.name }}</strong>
        </span>
      </div>

      <!-- Tab Navigation -->
      <nav class="ui-tabs result-tabs" role="tablist">
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'summary'"
           (click)="activeTab = 'summary'">
          <i class="bi bi-bar-chart"></i> Summary
        </button>
        <button type="button" role="tab" class="ui-tab" *ngIf="result.show_score_breakdown" [class.is-active]="activeTab === 'sections'"
           (click)="activeTab = 'sections'">
          <i class="bi bi-layers"></i> Sections
        </button>
        <button type="button" role="tab" class="ui-tab" *ngIf="result.show_correct_answers" [class.is-active]="activeTab === 'review'"
           (click)="activeTab = 'review'; loadQuestionResults()">
          <i class="bi bi-check2-square"></i> Question Review
          <span class="ui-tab__count" *ngIf="questionResults.length">
            {{ questionResults.length }}
          </span>
        </button>
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'timeline'"
           (click)="activeTab = 'timeline'; loadTimeline()">
          <i class="bi bi-activity"></i> Timeline
        </button>
      </nav>

      <!-- ==========================================
           TAB: SUMMARY
           ========================================== -->
      <div class="tab-content-area" *ngIf="activeTab === 'summary'">

        <!-- Score Overview Cards -->
        <div class="ui-kpi-grid ui-kpi-grid--4 summary-kpis">
          <div class="ui-kpi ui-kpi--brand">
            <span class="ui-kpi__label"><i class="bi bi-bullseye"></i> Score</span>
            <span class="ui-kpi__value">{{ result.total_score | number:'1.0-1' }}<small>/{{ result.max_score | number:'1.0-1' }}</small></span>
          </div>
          <div class="ui-kpi" [class.ui-kpi--success]="result.passed" [class.ui-kpi--danger]="!result.passed">
            <span class="ui-kpi__label"><i class="bi" [class.bi-check-circle]="result.passed" [class.bi-x-circle]="!result.passed"></i> Percentage</span>
            <span class="ui-kpi__value">{{ result.percentage | number:'1.0-0' }}<small>%</small></span>
            <span class="ui-kpi__sub">{{ result.passing_score }}% to pass</span>
          </div>
          <div class="ui-kpi ui-kpi--info">
            <span class="ui-kpi__label"><i class="bi bi-clock"></i> Time Taken</span>
            <span class="ui-kpi__value">{{ formatDuration(result.duration_seconds) }}</span>
          </div>
          <div class="ui-kpi ui-kpi--warning">
            <span class="ui-kpi__label"><i class="bi bi-award"></i> Grade</span>
            <span class="ui-kpi__value">{{ result.grade }}</span>
          </div>
        </div>

        <div class="ui-row">
        <!-- Score Breakdown -->
        <section class="ui-section" *ngIf="scoreBreakdown">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Score Breakdown</h2><p class="ui-section__subtitle">Auto-graded, manually graded and pending questions</p></div>
          </div>
          <div class="breakdown-list">
              <!-- Auto-Graded -->
                <div class="breakdown-item">
                  <div class="breakdown-header">
                    <span class="breakdown-label">Auto-Graded</span>
                    <span class="breakdown-count">{{ scoreBreakdown.auto_graded_count }} questions</span>
                  </div>
                  <div class="ui-bar tone-success" role="progressbar">
                    <span [style.width.%]="getBreakdownPercentage(scoreBreakdown.auto_graded_score, scoreBreakdown.auto_graded_max)"></span>
                  </div>
                  <span class="breakdown-score">
                    {{ scoreBreakdown.auto_graded_score | number:'1.0-1' }} / {{ scoreBreakdown.auto_graded_max | number:'1.0-1' }}
                  </span>
                </div>

              <!-- Manual-Graded -->
                <div class="breakdown-item" *ngIf="scoreBreakdown.manual_graded_count > 0">
                  <div class="breakdown-header">
                    <span class="breakdown-label">Manual-Graded</span>
                    <span class="breakdown-count">{{ scoreBreakdown.manual_graded_count }} questions</span>
                  </div>
                  <div class="ui-bar tone-info" role="progressbar">
                    <span [style.width.%]="getBreakdownPercentage(scoreBreakdown.manual_graded_score, scoreBreakdown.manual_graded_max)"></span>
                  </div>
                  <span class="breakdown-score">
                    {{ scoreBreakdown.manual_graded_score | number:'1.0-1' }} / {{ scoreBreakdown.manual_graded_max | number:'1.0-1' }}
                  </span>
                </div>

              <!-- Pending -->
                <div class="breakdown-item" *ngIf="scoreBreakdown.pending_count > 0">
                  <div class="breakdown-header">
                    <span class="breakdown-label">Pending Grading</span>
                    <span class="breakdown-count">{{ scoreBreakdown.pending_count }} questions</span>
                  </div>
                  <div class="ui-bar tone-warning" role="progressbar">
                    <span style="width: 100%"></span>
                  </div>
                  <span class="breakdown-score pending-note">
                    <i class="bi bi-hourglass-split me-1"></i> Awaiting review
                  </span>
                </div>
          </div>
        </section>

        <!-- Question Summary Donut -->
        <section class="ui-section" *ngIf="scoreBreakdown">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Answer Summary</h2><p class="ui-section__subtitle">Correct, partial, incorrect and skipped</p></div>
          </div>
            <div class="answer-summary-row">
                <div class="answer-summary-visual">
                  <div class="summary-ring">
                    <svg viewBox="0 0 120 120" class="ring-svg">
                      <circle cx="60" cy="60" r="52" fill="none" class="ring-track" stroke-width="12"/>
                      <circle cx="60" cy="60" r="52" fill="none" class="ring-correct" stroke-width="12"
                              [attr.stroke-dasharray]="getCorrectDash()" stroke-dashoffset="0"
                              transform="rotate(-90 60 60)" stroke-linecap="round"/>
                      <circle cx="60" cy="60" r="52" fill="none" class="ring-partial" stroke-width="12"
                              [attr.stroke-dasharray]="getPartialDash()" [attr.stroke-dashoffset]="getPartialOffset()"
                              transform="rotate(-90 60 60)" stroke-linecap="round"/>
                      <circle cx="60" cy="60" r="52" fill="none" class="ring-incorrect" stroke-width="12"
                              [attr.stroke-dasharray]="getIncorrectDash()" [attr.stroke-dashoffset]="getIncorrectOffset()"
                              transform="rotate(-90 60 60)" stroke-linecap="round"/>
                    </svg>
                    <div class="ring-center">
                      <span class="ring-value">{{ scoreBreakdown.total_questions }}</span>
                      <span class="ring-label">Questions</span>
                    </div>
                  </div>
                </div>
                <div class="summary-legend">
                  <div class="legend-item" *ngFor="let item of getAnswerSummary()">
                    <span class="legend-dot" [style.background]="item.color"></span>
                    <span class="legend-label">{{ item.label }}</span>
                    <span class="legend-value">{{ item.count }}</span>
                  </div>
                </div>
            </div>
        </section>
        </div>
      </div>

      <!-- ==========================================
           TAB: SECTIONS
           ========================================== -->
      <div class="tab-content-area" *ngIf="activeTab === 'sections'">
        <div class="section-results" *ngIf="sectionResults.length; else noSections">
          <section class="ui-section" *ngFor="let section of sectionResults; let i = index">
              <div class="ui-section__head">
                <div>
                  <h2 class="ui-section__title">
                    <span class="section-number">Section {{ section.section_order }}</span>
                    {{ section.section_title }}
                  </h2>
                  <p class="ui-section__subtitle">
                    {{ section.total_questions }} questions
                    · {{ section.answered_count }} answered
                  </p>
                </div>
                <div class="text-end">
                  <div class="section-score" [class.text-success]="section.section_percentage >= 70"
                       [class.text-warning]="section.section_percentage >= 50 && section.section_percentage < 70"
                       [class.text-danger]="section.section_percentage < 50">
                    {{ section.section_percentage | number:'1.0-0' }}%
                  </div>
                  <small class="ui-muted">
                    {{ section.section_score | number:'1.0-1' }} / {{ section.section_max_score | number:'1.0-1' }}
                  </small>
                </div>
              </div>

              <!-- Section Progress Bar -->
              <div class="progress section-progress mb-3">
                <div class="progress-bar seg-correct" role="progressbar"
                     [style.width.%]="(section.correct_count / section.total_questions) * 100"
                     [attr.title]="section.correct_count + ' correct'">
                  <span *ngIf="section.correct_count > 0">{{ section.correct_count }} ✓</span>
                </div>
                <div class="progress-bar seg-partial" role="progressbar"
                     [style.width.%]="(section.partial_count / section.total_questions) * 100"
                     [attr.title]="section.partial_count + ' partial'">
                  <span *ngIf="section.partial_count > 0">{{ section.partial_count }} ~</span>
                </div>
                <div class="progress-bar seg-incorrect" role="progressbar"
                     [style.width.%]="(section.incorrect_count / section.total_questions) * 100"
                     [attr.title]="section.incorrect_count + ' incorrect'">
                  <span *ngIf="section.incorrect_count > 0">{{ section.incorrect_count }} ✗</span>
                </div>
                <div class="progress-bar seg-skipped" role="progressbar"
                     [style.width.%]="(section.skipped_count / section.total_questions) * 100"
                     [attr.title]="section.skipped_count + ' skipped'">
                  <span *ngIf="section.skipped_count > 0">{{ section.skipped_count }} -</span>
                </div>
              </div>

              <!-- Section Stats -->
              <div class="section-stats">
                <span class="ui-pill ui-pill--success">
                  <i class="bi bi-check2"></i> {{ section.correct_count }} Correct
                </span>
                <span class="ui-pill ui-pill--warning" *ngIf="section.partial_count > 0">
                  <i class="bi bi-dash"></i> {{ section.partial_count }} Partial
                </span>
                <span class="ui-pill ui-pill--danger">
                  <i class="bi bi-x"></i> {{ section.incorrect_count }} Incorrect
                </span>
                <span class="ui-pill ui-pill--neutral" *ngIf="section.skipped_count > 0">
                  <i class="bi bi-dash-circle"></i> {{ section.skipped_count }} Skipped
                </span>
              </div>
          </section>
        </div>
        <ng-template #noSections>
          <div class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-layers"></i></span>
            <p>No section breakdown available</p>
          </div>
        </ng-template>
      </div>

      <!-- ==========================================
           TAB: QUESTION REVIEW
           ========================================== -->
      <section class="ui-section tab-content-area" *ngIf="activeTab === 'review'">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title">Question Review</h2><p class="ui-section__subtitle">Expand a question to see your answer</p></div>
        </div>

        <!-- Filter Bar -->
        <div class="ui-toolbar review-filter-bar">
          <div class="ui-filters">
            <button type="button" class="ui-filter" [class.is-active]="reviewFilter === 'all'"
                    (click)="reviewFilter = 'all'">
              All <span class="ui-filter__count">{{ questionResults.length }}</span>
            </button>
            <button type="button" class="ui-filter" [class.is-active]="reviewFilter === 'correct'"
                    (click)="reviewFilter = 'correct'">
              <i class="bi bi-check2"></i>
              Correct <span class="ui-filter__count">{{ getFilteredCount('correct') }}</span>
            </button>
            <button type="button" class="ui-filter" [class.is-active]="reviewFilter === 'partial'"
                    (click)="reviewFilter = 'partial'">
              <i class="bi bi-dash"></i>
              Partial <span class="ui-filter__count">{{ getFilteredCount('partial') }}</span>
            </button>
            <button type="button" class="ui-filter" [class.is-active]="reviewFilter === 'incorrect'"
                    (click)="reviewFilter = 'incorrect'">
              <i class="bi bi-x"></i>
              Incorrect <span class="ui-filter__count">{{ getFilteredCount('incorrect') }}</span>
            </button>
            <button type="button" class="ui-filter" [class.is-active]="reviewFilter === 'flagged'"
                    (click)="reviewFilter = 'flagged'"
                    *ngIf="getFlaggedCount() > 0">
              <i class="bi bi-flag"></i>
              Flagged <span class="ui-filter__count">{{ getFlaggedCount() }}</span>
            </button>
          </div>
        </div>

        <!-- Loading Questions -->
        <div class="text-center py-4" *ngIf="loadingQuestions">
          <div class="spinner-border spinner-border-sm text-primary"></div>
          <span class="ms-2 text-muted">Loading question details...</span>
        </div>

        <!-- Question List -->
        <div class="question-review-list" *ngIf="!loadingQuestions">
          <div class="question-review-item" *ngFor="let q of getFilteredQuestions(); let i = index"
               [class.correct]="q.is_correct && !q.is_partial"
               [class.partial]="q.is_partial"
               [class.incorrect]="!q.is_correct && !q.is_partial"
               [class.pending]="q.grading_status === 'pending'">

            <!-- Question Header -->
            <div class="question-header" (click)="toggleQuestion(q.answer_id)">
              <div class="d-flex align-items-center gap-2">
                <span class="question-number">Q{{ q.question_order }}</span>
                <span class="question-status-icon">
                  <tui-icon *ngIf="q.is_correct && !q.is_partial" icon="@tui.check-circle" class="text-success"></tui-icon>
                  <tui-icon *ngIf="q.is_partial" icon="@tui.minus-circle" class="text-warning"></tui-icon>
                  <tui-icon *ngIf="!q.is_correct && !q.is_partial && q.grading_status !== 'pending'" icon="@tui.x-circle" class="text-danger"></tui-icon>
                  <tui-icon *ngIf="q.grading_status === 'pending'" icon="@tui.clock" class="text-info"></tui-icon>
                </span>
                <span class="question-type-badge">{{ formatQuestionType(q.question_type) }}</span>
                <tui-icon *ngIf="q.flagged" icon="@tui.flag" class="text-warning"></tui-icon>
              </div>
              <div class="d-flex align-items-center gap-3">
                <span class="question-points">
                  {{ q.points_earned | number:'1.0-1' }}/{{ q.points_possible | number:'1.0-1' }} pts
                </span>
                <span class="question-time text-muted" *ngIf="q.time_spent_seconds > 0">
                  <tui-icon icon="@tui.clock" class="me-1"></tui-icon>
                  {{ formatDuration(q.time_spent_seconds) }}
                </span>
                <tui-icon [icon]="expandedQuestions.has(q.answer_id) ? '@tui.chevron-up' : '@tui.chevron-down'"
                          class="expand-icon"></tui-icon>
              </div>
            </div>

            <!-- Question Detail (Expandable) -->
            <div class="question-detail" *ngIf="expandedQuestions.has(q.answer_id)">

              <!-- Question Text -->
              <div class="question-text-area">
                <div [innerHTML]="q.question_text"></div>
                <img *ngIf="q.question_media" [src]="q.question_media" class="question-media" alt="Question media">
              </div>

              <!-- Your Answer -->
              <div class="answer-section your-answer-section">
                <h6 class="answer-section-title">
                  <tui-icon icon="@tui.user" class="me-1"></tui-icon> Your Answer
                </h6>
                <div class="answer-content" [ngSwitch]="q.question_type">

                  <!-- MCQ / True-False -->
                  <div *ngSwitchCase="'multiple_choice'">
                    <div class="option-item" *ngFor="let opt of q.options"
                         [class.selected]="isOptionSelected(q.user_answer, opt)"
                         [class.correct-option]="result.show_correct_answers && opt.is_correct"
                         [class.wrong-option]="isOptionSelected(q.user_answer, opt) && !opt.is_correct">
                      <span class="option-marker">
                        <tui-icon *ngIf="isOptionSelected(q.user_answer, opt) && opt.is_correct" icon="@tui.check" class="text-success"></tui-icon>
                        <tui-icon *ngIf="isOptionSelected(q.user_answer, opt) && !opt.is_correct" icon="@tui.x" class="text-danger"></tui-icon>
                        <tui-icon *ngIf="!isOptionSelected(q.user_answer, opt) && opt.is_correct && result.show_correct_answers" icon="@tui.check" class="text-success opacity-50"></tui-icon>
                      </span>
                      <span class="option-text">{{ opt.option_text }}</span>
                      <span class="option-feedback text-muted" *ngIf="opt.feedback && result.show_explanations">
                        {{ opt.feedback }}
                      </span>
                    </div>
                  </div>

                  <div *ngSwitchCase="'true_false'">
                    <div class="option-item" *ngFor="let opt of q.options"
                         [class.selected]="isOptionSelected(q.user_answer, opt)"
                         [class.correct-option]="result.show_correct_answers && opt.is_correct"
                         [class.wrong-option]="isOptionSelected(q.user_answer, opt) && !opt.is_correct">
                      <span class="option-marker">
                        <tui-icon *ngIf="isOptionSelected(q.user_answer, opt) && opt.is_correct" icon="@tui.check" class="text-success"></tui-icon>
                        <tui-icon *ngIf="isOptionSelected(q.user_answer, opt) && !opt.is_correct" icon="@tui.x" class="text-danger"></tui-icon>
                      </span>
                      <span class="option-text">{{ opt.option_text }}</span>
                    </div>
                  </div>

                  <!-- Multi-Select -->
                  <div *ngSwitchCase="'multi_select'">
                    <div class="option-item" *ngFor="let opt of q.options"
                         [class.selected]="isOptionInArray(q.user_answer, opt)"
                         [class.correct-option]="result.show_correct_answers && opt.is_correct"
                         [class.wrong-option]="isOptionInArray(q.user_answer, opt) && !opt.is_correct">
                      <span class="option-marker">
                        <tui-icon *ngIf="isOptionInArray(q.user_answer, opt) && opt.is_correct" icon="@tui.check" class="text-success"></tui-icon>
                        <tui-icon *ngIf="isOptionInArray(q.user_answer, opt) && !opt.is_correct" icon="@tui.x" class="text-danger"></tui-icon>
                        <tui-icon *ngIf="!isOptionInArray(q.user_answer, opt) && opt.is_correct && result.show_correct_answers" icon="@tui.check" class="text-success opacity-50"></tui-icon>
                      </span>
                      <span class="option-text">{{ opt.option_text }}</span>
                    </div>
                  </div>

                  <!-- Short Answer / Essay / Fill in Blank -->
                  <div *ngSwitchCase="'short_answer'">
                    <div class="text-answer-display">
                      <p>{{ q.user_answer || '(No answer provided)' }}</p>
                    </div>
                    <div class="correct-answer-display" *ngIf="result.show_correct_answers && q.correct_answer">
                      <strong>Accepted answer(s):</strong> {{ formatCorrectAnswer(q.correct_answer) }}
                    </div>
                  </div>

                  <div *ngSwitchCase="'essay'">
                    <div class="text-answer-display essay-display">
                      <div [innerHTML]="q.user_answer || '(No answer provided)'"></div>
                    </div>
                    <div class="grading-note" *ngIf="q.grading_status === 'pending'">
                      <tui-icon icon="@tui.info" class="me-1"></tui-icon>
                      This question is awaiting manual grading
                    </div>
                  </div>

                  <div *ngSwitchCase="'fill_in_blank'">
                    <div class="fill-blank-answers" *ngIf="q.user_answer">
                      <div class="blank-item" *ngFor="let blank of getBlankAnswers(q); let bi = index">
                        <span class="blank-number">Blank {{ bi + 1 }}:</span>
                        <span class="blank-user-answer" [class.text-success]="blank.is_correct"
                              [class.text-danger]="!blank.is_correct">
                          {{ blank.user_value || '(empty)' }}
                        </span>
                        <span class="blank-correct" *ngIf="result.show_correct_answers && !blank.is_correct">
                          → {{ blank.correct_value }}
                        </span>
                      </div>
                    </div>
                  </div>

                  <!-- Matching -->
                  <div *ngSwitchCase="'matching'">
                    <div class="matching-review">
                      <div class="match-pair" *ngFor="let pair of getMatchingReview(q)"
                           [class.correct-match]="pair.is_correct"
                           [class.wrong-match]="!pair.is_correct">
                        <span class="match-left">{{ pair.left }}</span>
                        <tui-icon [icon]="pair.is_correct ? '@tui.check' : '@tui.x'"
                                  [class.text-success]="pair.is_correct"
                                  [class.text-danger]="!pair.is_correct"
                                  class="match-arrow"></tui-icon>
                        <span class="match-right">{{ pair.user_right }}</span>
                        <span class="match-correct" *ngIf="!pair.is_correct && result.show_correct_answers">
                          (→ {{ pair.correct_right }})
                        </span>
                      </div>
                    </div>
                  </div>

                  <!-- Ordering -->
                  <div *ngSwitchCase="'ordering'">
                    <div class="ordering-review">
                      <div class="order-item" *ngFor="let item of getOrderingReview(q); let oi = index"
                           [class.correct-order]="item.is_correct"
                           [class.wrong-order]="!item.is_correct">
                        <span class="order-number">{{ oi + 1 }}.</span>
                        <span class="order-text">{{ item.text }}</span>
                        <span class="order-status">
                          <tui-icon *ngIf="item.is_correct" icon="@tui.check" class="text-success"></tui-icon>
                          <span *ngIf="!item.is_correct && result.show_correct_answers" class="text-danger">
                            (should be #{{ item.correct_position }})
                          </span>
                        </span>
                      </div>
                    </div>
                  </div>

                  <!-- Hotspot / File Upload / Numeric -->
                  <div *ngSwitchCase="'hotspot'">
                    <div class="text-answer-display">
                      <p>Selected area: {{ formatHotspotAnswer(q.user_answer) }}</p>
                    </div>
                  </div>

                  <div *ngSwitchCase="'file_upload'">
                    <div class="file-upload-answer" *ngIf="q.user_answer">
                      <tui-icon icon="@tui.file" class="me-2"></tui-icon>
                      <a [href]="q.user_answer.file_url" target="_blank">{{ q.user_answer.file_name }}</a>
                      <small class="text-muted ms-2">{{ formatFileSize(q.user_answer.file_size) }}</small>
                    </div>
                    <div class="grading-note" *ngIf="q.grading_status === 'pending'">
                      <tui-icon icon="@tui.info" class="me-1"></tui-icon>
                      This question is awaiting manual grading
                    </div>
                  </div>

                  <div *ngSwitchCase="'numeric'">
                    <div class="text-answer-display">
                      <p>Your answer: <strong>{{ q.user_answer }}</strong></p>
                    </div>
                    <div class="correct-answer-display" *ngIf="result.show_correct_answers && q.correct_answer">
                      <strong>Correct answer:</strong> {{ q.correct_answer.value }}
                      <span *ngIf="q.correct_answer.tolerance"> (± {{ q.correct_answer.tolerance }})</span>
                    </div>
                  </div>

                  <!-- Default -->
                  <div *ngSwitchDefault>
                    <div class="text-answer-display">
                      <p>{{ formatUserAnswer(q) || '(No answer provided)' }}</p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Explanation -->
              <div class="explanation-section" *ngIf="q.explanation && result.show_explanations">
                <h6 class="explanation-title">
                  <tui-icon icon="@tui.info" class="me-1"></tui-icon> Explanation
                </h6>
                <div class="explanation-content" [innerHTML]="q.explanation"></div>
              </div>

              <!-- Feedback -->
              <div class="feedback-section" *ngIf="q.feedback">
                <h6 class="feedback-title">
                  <tui-icon icon="@tui.message-circle" class="me-1"></tui-icon> Instructor Feedback
                </h6>
                <div class="feedback-content" [innerHTML]="q.feedback"></div>
              </div>
            </div>
          </div>

          <!-- No Questions Match Filter -->
          <div class="ui-state" *ngIf="getFilteredQuestions().length === 0 && questionResults.length > 0">
            <span class="ui-state__icon"><i class="bi bi-funnel"></i></span>
            <p>No questions match the selected filter</p>
          </div>
        </div>
      </section>

      <!-- ==========================================
           TAB: TIMELINE
           ========================================== -->
      <section class="ui-section tab-content-area" *ngIf="activeTab === 'timeline'">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title">Timeline</h2><p class="ui-section__subtitle">Everything that happened during the attempt</p></div>
        </div>
        <div class="text-center py-4" *ngIf="loadingTimeline">
          <div class="spinner-border spinner-border-sm text-secondary"></div>
          <span class="ms-2 ui-muted">Loading timeline...</span>
        </div>

        <div class="timeline-container" *ngIf="!loadingTimeline">
          <div class="timeline-item" *ngFor="let event of timeline"
               [ngClass]="'timeline-' + getTimelineCategory(event.event_type)">
            <div class="timeline-dot"></div>
            <div class="timeline-content">
              <div class="timeline-time">{{ formatTime(event.created_at) }}</div>
              <div class="timeline-event">
                <tui-icon [icon]="getTimelineIcon(event.event_type)" class="me-1"></tui-icon>
                {{ formatTimelineEvent(event) }}
              </div>
            </div>
          </div>

          <div class="ui-state" *ngIf="timeline.length === 0">
            <span class="ui-state__icon"><i class="bi bi-activity"></i></span>
            <p>No timeline events available</p>
          </div>
        </div>
      </section>

    </ng-container>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    /* Loading & Error */
    .loading-container { text-align: center; padding: 3rem 1rem; }

    /* Breadcrumb + header */
    .results-crumbs { margin-bottom: 10px; }
    .breadcrumb-sm { font-size: .8125rem; }
    .breadcrumb-sm a { color: var(--ui-text-muted); text-decoration: none; }
    .breadcrumb-sm a:hover { color: var(--brand-primary); }

    .result-meta {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px 14px;
      margin-top: 4px;
      font-size: .8125rem;
    }

    .text-muted-light { color: var(--ui-text-muted); }

    /* Status banner: score ring + verdict, soft tone by outcome */
    .status-banner { display: flex; align-items: center; gap: 20px; }
    .status-copy { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; font-size: .875rem; color: var(--ui-text-muted); min-width: 0; }
    .status-pill { height: 28px; font-size: .8125rem; font-weight: 600; }
    .status-banner.banner-passed { border-color: rgba(var(--ui-success-rgb), .3); background: rgba(var(--ui-success-rgb), .04); }
    .status-banner.banner-passed .status-pill { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .status-banner.banner-failed { border-color: rgba(var(--ui-danger-rgb), .3); background: rgba(var(--ui-danger-rgb), .03); }
    .status-banner.banner-failed .status-pill { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .status-banner.banner-pending { border-color: rgba(var(--ui-warning-rgb), .3); background: rgba(var(--ui-warning-rgb), .04); }
    .status-banner.banner-pending .status-pill { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }

    /* Score Circle */
    .score-display { display: inline-block; flex-shrink: 0; }

    .score-circle {
      width: 88px;
      height: 88px;
      border-radius: 50%;
      border: 4px solid var(--ui-danger);
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: var(--ui-surface);

      &.passed { border-color: var(--ui-success); }
      &.failed { border-color: var(--ui-danger); }
      &.pending { border-color: var(--ui-warning); }

      .score-value { font-size: 1.375rem; font-weight: 700; color: var(--ui-text); line-height: 1; font-variant-numeric: tabular-nums; }
      .score-label { font-size: .6875rem; color: var(--ui-text-muted); margin-top: 3px; }
    }

    /* Tabs / content */
    .tab-content-area { margin-bottom: 20px; }
    div.tab-content-area { margin-bottom: 0; }
    .summary-kpis { margin-bottom: 20px; }

    /* Breakdown */
    .breakdown-list { display: flex; flex-direction: column; gap: 12px; }
    .breakdown-item {
      padding: 14px 16px;
      border: 1px solid var(--ui-border);
      border-radius: var(--ui-radius-card);

      .breakdown-header { display: flex; justify-content: space-between; margin-bottom: 8px; }
      .breakdown-label { font-weight: 600; font-size: .875rem; color: var(--ui-text); }
      .breakdown-count { font-size: .75rem; color: var(--ui-text-muted); }
      .breakdown-score { display: block; margin-top: 8px; font-size: .8125rem; font-weight: 600; font-variant-numeric: tabular-nums; }
      .pending-note { color: var(--ui-warning-text); }
    }
    .ui-bar.tone-success > span { background: var(--ui-success); }
    .ui-bar.tone-info > span { background: var(--ui-info); }
    .ui-bar.tone-warning > span { background: var(--ui-warning); }

    /* Answer Summary Ring */
    .answer-summary-row { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
    .answer-summary-visual { display: flex; justify-content: center; }

    .summary-ring {
      position: relative;
      width: 150px;
      height: 150px;

      .ring-svg { width: 100%; height: 100%; }
      .ring-track { stroke: var(--ui-surface-2); }
      .ring-correct { stroke: var(--ui-success); }
      .ring-partial { stroke: var(--ui-warning); }
      .ring-incorrect { stroke: var(--ui-danger); }

      .ring-center {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;

        .ring-value { display: block; font-size: 1.5rem; font-weight: 700; color: var(--ui-text); }
        .ring-label { display: block; font-size: .75rem; color: var(--ui-text-muted); }
      }
    }

    .summary-legend {
      display: flex;
      flex-direction: column;
      gap: 10px;
      flex: 1;
      min-width: 160px;

      .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;

        .legend-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
        .legend-label { flex: 1; font-size: .8125rem; color: var(--ui-text); }
        .legend-value { font-weight: 600; font-size: .8125rem; font-variant-numeric: tabular-nums; }
      }
    }

    /* Section Results */
    .section-number {
      display: inline-block;
      background: rgba(var(--brand-primary-rgb), .1);
      color: var(--brand-primary);
      font-size: .6875rem;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 999px;
      margin-right: 6px;
      vertical-align: middle;
    }

    .section-score { font-size: 1.375rem; font-weight: 700; font-variant-numeric: tabular-nums; }

    .section-progress { height: 22px; border-radius: var(--ui-radius-ctl); background: var(--ui-surface-2); font-size: .6875rem; }
    .section-progress .seg-correct { background: var(--ui-success); }
    .section-progress .seg-partial { background: var(--ui-warning); }
    .section-progress .seg-incorrect { background: var(--ui-danger); }
    .section-progress .seg-skipped { background: var(--ui-text-subtle); }

    .section-stats { display: flex; gap: 8px; flex-wrap: wrap; }

    /* Review Filter */
    .review-filter-bar { margin-bottom: 16px; }

    /* Question Review Items */
    .question-review-item {
      border: 1px solid var(--ui-border);
      border-radius: 0.5rem;
      margin-bottom: 0.75rem;
      overflow: hidden;
      border-left: 4px solid var(--ui-border);

      &.correct { border-left-color: var(--ui-success); }
      &.partial { border-left-color: var(--ui-warning); }
      &.incorrect { border-left-color: var(--ui-danger); }
      &.pending { border-left-color: var(--ui-info); }
    }

    .question-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.75rem 1rem;
      cursor: pointer;
      background: #fff;
      transition: background 0.15s;

      &:hover { background: var(--ui-surface-2); }

      .question-number {
        font-weight: 700;
        color: var(--ui-text);
        min-width: 30px;
      }

      .question-type-badge {
        font-size: 0.7rem;
        padding: 0.15rem 0.5rem;
        background: var(--ui-border);
        border-radius: 0.25rem;
        color: var(--ui-text);
      }

      .question-points { font-weight: 600; font-size: 0.9rem; }
      .question-time { font-size: 0.8rem; }
      .expand-icon { color: var(--ui-text-muted); }
    }

    .question-detail { padding: 1rem; border-top: 1px solid var(--ui-border); background: var(--ui-surface-2); }

    .question-text-area {
      margin-bottom: 1rem;
      padding: 1rem;
      background: #fff;
      border-radius: 0.5rem;
      border: 1px solid var(--ui-border);
      font-size: 0.95rem;
    }

    .question-media { max-width: 100%; border-radius: 0.5rem; margin-top: 0.75rem; }

    .answer-section {
      margin-bottom: 1rem;

      .answer-section-title {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--ui-text);
        margin-bottom: 0.5rem;
        padding-bottom: 0.25rem;
        border-bottom: 1px solid var(--ui-border);
      }
    }

    /* Option Items */
    .option-item {
      display: flex;
      align-items: flex-start;
      gap: 0.5rem;
      padding: 0.5rem 0.75rem;
      border-radius: 0.375rem;
      margin-bottom: 0.25rem;
      font-size: 0.9rem;
      border: 1px solid transparent;

      &.selected { background: var(--ui-surface-2); border-color: var(--ui-border); }
      &.correct-option { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); }
      &.wrong-option { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); }

      .option-marker { flex-shrink: 0; width: 20px; }
      .option-text { flex: 1; }
      .option-feedback { display: block; font-size: 0.8rem; font-style: italic; margin-top: 0.25rem; }
    }

    /* Text Answers */
    .text-answer-display {
      padding: 0.75rem;
      background: #fff;
      border: 1px solid var(--ui-border);
      border-radius: 0.375rem;
      font-size: 0.9rem;
      margin-bottom: 0.5rem;

      &.essay-display { min-height: 100px; }
    }

    .correct-answer-display {
      padding: 0.5rem 0.75rem;
      background: rgba(var(--ui-success-rgb), .1);
      border: 1px solid rgba(var(--ui-success-rgb), .28);
      border-radius: 0.375rem;
      font-size: 0.85rem;
    }

    .grading-note {
      padding: 0.5rem 0.75rem;
      background: rgba(var(--ui-info-rgb), .08);
      border: 1px solid rgba(var(--ui-info-rgb), .25);
      border-radius: 0.375rem;
      font-size: 0.85rem;
      color: var(--ui-info-text);
    }

    /* Fill in Blank */
    .blank-item {
      display: flex;
      gap: 0.5rem;
      align-items: center;
      padding: 0.25rem 0;
      font-size: 0.9rem;

      .blank-number { font-weight: 600; color: var(--ui-text-muted); min-width: 60px; }
      .blank-user-answer { font-weight: 600; }
      .blank-correct { font-size: 0.85rem; color: var(--ui-success); }
    }

    /* Matching */
    .match-pair {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.5rem 0.75rem;
      border-radius: 0.375rem;
      margin-bottom: 0.25rem;
      font-size: 0.9rem;
      border: 1px solid var(--ui-border);

      &.correct-match { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); }
      &.wrong-match { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); }

      .match-left { font-weight: 600; min-width: 120px; }
      .match-arrow { flex-shrink: 0; }
      .match-right { flex: 1; }
      .match-correct { font-size: 0.8rem; color: var(--ui-success); }
    }

    /* Ordering */
    .order-item {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 0.75rem;
      border-radius: 0.375rem;
      margin-bottom: 0.25rem;
      font-size: 0.9rem;
      border: 1px solid var(--ui-border);

      &.correct-order { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); }
      &.wrong-order { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); }

      .order-number { font-weight: 700; color: var(--ui-text); min-width: 24px; }
      .order-text { flex: 1; }
    }

    /* File Upload Answer */
    .file-upload-answer {
      display: flex;
      align-items: center;
      padding: 0.75rem;
      background: var(--ui-surface-2);
      border-radius: 0.375rem;
      font-size: 0.9rem;
      margin-bottom: 0.5rem;

      a { color: var(--brand-primary); text-decoration: none; &:hover { text-decoration: underline; } }
    }

    /* Explanation & Feedback */
    .explanation-section, .feedback-section {
      margin-top: 1rem;
      padding: 0.75rem;
      border-radius: 0.375rem;
      font-size: 0.9rem;
    }

    .explanation-section {
      background: rgba(var(--ui-warning-rgb), .08);
      border: 1px solid var(--ui-warning);

      .explanation-title { color: var(--ui-warning-text); font-size: 0.85rem; margin-bottom: 0.5rem; }
      .explanation-content { color: var(--ui-warning-text); }
    }

    .feedback-section {
      background: rgba(var(--ui-info-rgb), .08);
      border: 1px solid rgba(var(--ui-info-rgb), .25);

      .feedback-title { color: var(--ui-info-text); font-size: 0.85rem; margin-bottom: 0.5rem; }
      .feedback-content { color: var(--ui-info-text); }
    }

    /* Timeline */
    .timeline-container { position: relative; padding-left: 2rem; }

    .timeline-item {
      position: relative;
      padding-bottom: 1.25rem;
      padding-left: 1.5rem;
      border-left: 2px solid var(--ui-border);

      &:last-child { border-left-color: transparent; }

      .timeline-dot {
        position: absolute;
        left: -7px;
        top: 4px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: var(--ui-text-muted);
        border: 2px solid #fff;
      }

      &.timeline-start .timeline-dot { background: var(--ui-success); }
      &.timeline-answer .timeline-dot { background: var(--ui-info); }
      &.timeline-navigation .timeline-dot { background: var(--ui-text-muted); }
      &.timeline-violation .timeline-dot { background: var(--ui-danger); }
      &.timeline-submit .timeline-dot { background: var(--brand-primary); }

      .timeline-time { font-size: 0.75rem; color: var(--ui-text-muted); margin-bottom: 0.25rem; }
      .timeline-event { font-size: 0.9rem; color: var(--ui-text); }
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: var(--ui-text-muted);

      .empty-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
      p { font-size: 1rem; margin-bottom: 0.5rem; }
    }

    /* Responsive */
    @media (max-width: 768px) {
      .score-circle { width: 72px; height: 72px; .score-value { font-size: 1.1rem; } }
      .status-banner { gap: 14px; }
      .question-header { flex-direction: column; gap: 0.5rem; align-items: flex-start; }
      .match-pair { flex-wrap: wrap; }
    }
  `]
})
export class AssessmentResultsComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private destroy$ = new Subject<void>();

  // State
  loading = true;
  error: string | null = null;
  result: AttemptResult | null = null;
  sectionResults: SectionResult[] = [];
  questionResults: QuestionResult[] = [];
  scoreBreakdown: ScoreBreakdown | null = null;
  timeline: AttemptTimeline[] = [];
  activeTab = 'summary';
  reviewFilter = 'all';
  expandedQuestions = new Set<string>();
  loadingQuestions = false;
  loadingTimeline = false;

  // Route params
  attemptId = '';

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.attemptId = String(params['attemptId'] || params['id'] || '');
      if (this.attemptId) {
        this.loadResults();
      } else {
        this.error = 'No attempt ID provided';
        this.loading = false;
      }
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadResults(): void {
    this.loading = true;
    this.error = null;

    this.assessments.attempt(this.attemptId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (raw) => {
        const a = raw as unknown as Record<string, unknown>;
        const rows = Array.isArray(a['answers']) ? (a['answers'] as Array<Record<string, unknown>>) : [];
        const graded = a['gradedAt'] != null;
        const pending = rows.some(r => r['needsMarker'] === true && r['isGraded'] !== true);

        this.result = {
          attempt_id: this.attemptId as unknown as number,
          assessment_id: a['assessmentId'] as unknown as number,
          assessment_title: String(a['assessmentTitle'] ?? 'Assessment'),
          assessment_type: 'quiz',
          learner_id: 0,
          learner_name: String(a['userName'] ?? ''),
          status: String(a['status'] ?? ''),
          started_at: String(a['startedAt'] ?? ''),
          completed_at: String(a['submittedAt'] ?? ''),
          duration_seconds: Number(a['timeSpentSeconds'] ?? 0),
          total_score: Number(a['rawScore'] ?? 0),
          max_score: Number(a['maxScore'] ?? 0),
          percentage: Number(a['percentage'] ?? 0),
          passed: a['passed'] === true,
          passing_score: 0,
          grade: '',
          attempt_number: Number(a['attemptNumber'] ?? 1),
          max_attempts: 0,
          remaining_attempts: 0,
          show_correct_answers: true,
          show_explanations: true,
          show_score_breakdown: true,
          feedback_message: '',
          grading_status: pending ? 'pending' : (graded ? 'graded' : 'graded'),
          has_manual_questions: pending,
          pending_manual_count: rows.filter(r => r['needsMarker'] === true && r['isGraded'] !== true).length,
          certificate_id: null,
          certificate_number: null,
          points_earned: Number(a['rawScore'] ?? 0),
          badges_earned: [],
        };
        this.questionResults = rows.map((r, i) => this.toQuestionResult(r, i));
        this.sectionResults = [];
        this.scoreBreakdown = null;
        this.loading = false;
      },
      error: () => {
        this.error = 'Network error. Please try again.';
        this.loading = false;
      },
    });
  }

  /** One API answer row -> the per-question review shape. */
  private toQuestionResult(r: Record<string, unknown>, index: number): QuestionResult {
    const q = (r['question'] ?? {}) as Record<string, unknown>;
    const opts = Array.isArray(q['options']) ? (q['options'] as Array<Record<string, unknown>>) : [];
    const correctIds = opts.filter(o => o['isCorrect'] === true).map(o => String(o['id']));
    return {
      answer_id: String(q['id'] ?? index),
      question_id: String(q['id'] ?? ''),
      question_type: this.resultType(String(q['type'] ?? '')),
      question_text: String(q['text'] ?? ''),
      question_media: null,
      question_order: index + 1,
      section_id: 'all',
      section_title: '',
      points_possible: Number(r['pointsPossible'] ?? q['points'] ?? 0),
      points_earned: Number(r['pointsEarned'] ?? 0),
      is_correct: r['isCorrect'] === true,
      is_partial: false,
      grading_status: r['isGraded'] === true ? 'graded' : (r['needsMarker'] === true ? 'pending' : 'graded'),
      user_answer: r['answer'] ?? null,
      correct_answer: correctIds,
      options: opts.map(o => ({ id: String(o['id']), option_text: String(o['text']), is_correct: o['isCorrect'] === true })),
      matching_pairs: [],
      ordering_items: [],
      explanation: (q['explanation'] as string) ?? null,
      feedback: (r['feedback'] as string) ?? null,
      time_spent_seconds: 0,
      flagged: false,
    };
  }

  // Questions and timeline arrive with the attempt; kept as no-ops so the
  // template's tab hooks still resolve.
  loadQuestionResults(): void { /* questions loaded with the result */ }
  loadTimeline(): void { /* no timeline endpoint yet */ }

  // ==========================================
  // NAVIGATION
  // ==========================================

  goToAssessments(): void {
    this.router.navigate(['/learner/assessments']);
  }

  goToHistory(): void {
    this.router.navigate(['/learner/assessments/history']);
  }

  viewCertificate(): void {
    if (this.result?.certificate_id) {
      this.router.navigate(['/learner/assessments/certificates', this.result.certificate_id]);
    }
  }

  retakeAssessment(): void {
    if (this.result?.assessment_id) {
      this.router.navigate(['/learner/assessments', this.result.assessment_id, 'lobby']);
    }
  }

  printResults(): void {
    window.print();
  }

  // ==========================================
  // FORMATTING HELPERS
  // ==========================================

  formatDate(date: string): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit'
    });
  }

  formatTime(date: string): string {
    if (!date) return '-';
    return new Date(date).toLocaleTimeString('en-US', {
      hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
  }

  formatDuration(seconds: number): string {
    if (!seconds || seconds <= 0) return '0s';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
  }

  formatAssessmentType(type: string): string {
    const map: { [key: string]: string } = {
      'exam': 'Exam', 'quiz': 'Quiz', 'practice': 'Practice',
      'survey': 'Survey', 'assignment': 'Assignment'
    };
    return map[type] || type;
  }

  formatQuestionType(type: string): string {
    return type.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  }

  formatCorrectAnswer(answer: any): string {
    if (Array.isArray(answer)) return answer.join(', ');
    if (typeof answer === 'object') return JSON.stringify(answer);
    return String(answer);
  }

  formatHotspotAnswer(answer: any): string {
    if (!answer) return 'No selection';
    if (typeof answer === 'object') {
      return `x: ${answer.x}, y: ${answer.y}`;
    }
    return String(answer);
  }

  formatFileSize(bytes: number): string {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }

  // ==========================================
  // STATUS HELPERS
  // ==========================================

  getTypeBadgeClass(type: string): string {
    const map: { [key: string]: string } = {
      'exam': 'bg-danger', 'quiz': 'bg-primary', 'practice': 'bg-info',
      'survey': 'bg-secondary', 'assignment': 'bg-warning text-dark'
    };
    return map[type] || 'bg-secondary';
  }

  getStatusBannerClass(): string {
    if (!this.result) return '';
    if (this.result.grading_status === 'pending') return 'banner-pending';
    return this.result.passed ? 'banner-passed' : 'banner-failed';
  }

  getStatusIcon(): string {
    if (!this.result) return '@tui.info';
    if (this.result.grading_status === 'pending') return '@tui.clock';
    return this.result.passed ? '@tui.check-circle' : '@tui.x-circle';
  }

  getStatusTitle(): string {
    if (!this.result) return '';
    if (this.result.grading_status === 'pending') return 'Results Pending';
    return this.result.passed ? 'Congratulations! You Passed!' : 'Unfortunately, You Did Not Pass';
  }

  getBreakdownPercentage(score: number, max: number): number {
    return max > 0 ? (score / max) * 100 : 0;
  }

  // ==========================================
  // ANSWER SUMMARY (SVG Ring)
  // ==========================================

  private readonly CIRCUMFERENCE = 2 * Math.PI * 52; // ~326.73

  private getAnswerCounts(): { correct: number; partial: number; incorrect: number; skipped: number } {
    let correct = 0, partial = 0, incorrect = 0, skipped = 0;
    for (const s of this.sectionResults) {
      correct += s.correct_count || 0;
      partial += s.partial_count || 0;
      incorrect += s.incorrect_count || 0;
      skipped += s.skipped_count || 0;
    }
    return { correct, partial, incorrect, skipped };
  }

  private getTotalForRing(): number {
    const c = this.getAnswerCounts();
    return c.correct + c.partial + c.incorrect + c.skipped || 1;
  }

  getCorrectDash(): string {
    const ratio = this.getAnswerCounts().correct / this.getTotalForRing();
    const len = ratio * this.CIRCUMFERENCE;
    return `${len} ${this.CIRCUMFERENCE - len}`;
  }

  getPartialDash(): string {
    const ratio = this.getAnswerCounts().partial / this.getTotalForRing();
    const len = ratio * this.CIRCUMFERENCE;
    return `${len} ${this.CIRCUMFERENCE - len}`;
  }

  getPartialOffset(): string {
    const correctRatio = this.getAnswerCounts().correct / this.getTotalForRing();
    return `${-correctRatio * this.CIRCUMFERENCE}`;
  }

  getIncorrectDash(): string {
    const ratio = this.getAnswerCounts().incorrect / this.getTotalForRing();
    const len = ratio * this.CIRCUMFERENCE;
    return `${len} ${this.CIRCUMFERENCE - len}`;
  }

  getIncorrectOffset(): string {
    const counts = this.getAnswerCounts();
    const total = this.getTotalForRing();
    const offset = (counts.correct + counts.partial) / total;
    return `${-offset * this.CIRCUMFERENCE}`;
  }

  getAnswerSummary(): { label: string; count: number; color: string }[] {
    const c = this.getAnswerCounts();
    return [
      { label: 'Correct', count: c.correct, color: 'var(--ui-success)' },
      { label: 'Partial Credit', count: c.partial, color: 'var(--ui-warning)' },
      { label: 'Incorrect', count: c.incorrect, color: 'var(--ui-danger)' },
      { label: 'Skipped', count: c.skipped, color: 'var(--ui-border-strong)' }
    ].filter(i => i.count > 0);
  }

  // ==========================================
  // QUESTION REVIEW HELPERS
  // ==========================================

  toggleQuestion(answerId: string): void {
    if (this.expandedQuestions.has(answerId)) {
      this.expandedQuestions.delete(answerId);
    } else {
      this.expandedQuestions.add(answerId);
    }
  }

  getFilteredQuestions(): QuestionResult[] {
    if (this.reviewFilter === 'all') return this.questionResults;
    if (this.reviewFilter === 'correct') return this.questionResults.filter(q => q.is_correct && !q.is_partial);
    if (this.reviewFilter === 'partial') return this.questionResults.filter(q => q.is_partial);
    if (this.reviewFilter === 'incorrect') return this.questionResults.filter(q => !q.is_correct && !q.is_partial);
    if (this.reviewFilter === 'flagged') return this.questionResults.filter(q => q.flagged);
    return this.questionResults;
  }

  getFilteredCount(filter: string): number {
    if (filter === 'correct') return this.questionResults.filter(q => q.is_correct && !q.is_partial).length;
    if (filter === 'partial') return this.questionResults.filter(q => q.is_partial).length;
    if (filter === 'incorrect') return this.questionResults.filter(q => !q.is_correct && !q.is_partial).length;
    return 0;
  }

  getFlaggedCount(): number {
    return this.questionResults.filter(q => q.flagged).length;
  }

  /** New-API question type -> the review template's own type names. */
  private resultType(apiType: string): string {
    return ({
      multiple_choice: 'multiple_choice',
      multiple_choice_single: 'multiple_choice',
      multiple_choice_multiple: 'multi_select',
      true_false: 'true_false',
      short_answer: 'short_answer',
      essay: 'essay',
      numeric: 'numeric',
      fill_in_blanks: 'fill_in_blank',
      matching: 'matching',
      ordering: 'ordering',
    } as Record<string, string>)[apiType] ?? apiType;
  }

  /** The option ids in a saved answer, whatever shape it arrived in. */
  private answerOptionIds(a: any): string[] {
    if (a && Array.isArray(a.optionIds)) return a.optionIds.map(String);
    if (Array.isArray(a)) return a.map(String);
    if (a !== null && a !== undefined && typeof a !== 'object') return [String(a)];
    return [];
  }

  private optionId(option: any): string {
    return String(option?.id ?? option?.option_id ?? '');
  }

  /** A human-readable rendering of any saved answer, for the fallback view. */
  formatUserAnswer(q: QuestionResult): string {
    const a: any = q.user_answer;
    if (a === null || a === undefined) return '';
    const ids = this.answerOptionIds(a);
    if (ids.length && q.options?.length) {
      return q.options
        .filter((o: any) => ids.includes(this.optionId(o)))
        .map((o: any) => o.option_text)
        .join(', ');
    }
    if (typeof a === 'object') {
      if (typeof a.text === 'string') return a.text;
      if (a.value !== undefined && a.value !== null) return String(a.value);
      if (Array.isArray(a.texts)) return a.texts.join(', ');
    }
    return typeof a === 'object' ? '' : String(a);
  }

  isOptionSelected(userAnswer: any, option: any): boolean {
    return this.answerOptionIds(userAnswer).includes(this.optionId(option));
  }

  isOptionInArray(userAnswer: any, option: any): boolean {
    return this.answerOptionIds(userAnswer).includes(this.optionId(option));
  }

  getBlankAnswers(q: QuestionResult): any[] {
    if (!q.user_answer || !q.correct_answer) return [];
    const blanks: any[] = [];
    const userAnswers = Array.isArray(q.user_answer) ? q.user_answer : [q.user_answer];
    const correctAnswers = Array.isArray(q.correct_answer) ? q.correct_answer : [q.correct_answer];

    for (let i = 0; i < Math.max(userAnswers.length, correctAnswers.length); i++) {
      blanks.push({
        user_value: userAnswers[i] || '',
        correct_value: correctAnswers[i] || '',
        is_correct: String(userAnswers[i] || '').toLowerCase().trim() === String(correctAnswers[i] || '').toLowerCase().trim()
      });
    }
    return blanks;
  }

  getMatchingReview(q: QuestionResult): any[] {
    if (!q.user_answer || !q.matching_pairs) return [];
    const review: any[] = [];
    const userMap = typeof q.user_answer === 'object' ? q.user_answer : {};

    for (const pair of q.matching_pairs) {
      const userRight = userMap[pair.left_id] || userMap[pair.left_text] || '(no match)';
      review.push({
        left: pair.left_text,
        user_right: this.getMatchText(q.matching_pairs, userRight),
        correct_right: pair.right_text,
        is_correct: String(userRight) === String(pair.right_id) || String(userRight) === String(pair.right_text)
      });
    }
    return review;
  }

  private getMatchText(pairs: any[], id: any): string {
    const found = pairs.find((p: any) => String(p.right_id) === String(id));
    return found ? found.right_text : String(id);
  }

  getOrderingReview(q: QuestionResult): any[] {
    if (!q.user_answer || !q.ordering_items) return [];
    const userOrder = Array.isArray(q.user_answer) ? q.user_answer : [];
    const review: any[] = [];

    for (let i = 0; i < userOrder.length; i++) {
      const itemId = userOrder[i];
      const item = q.ordering_items.find((o: any) => String(o.item_id) === String(itemId));
      const correctIndex = q.ordering_items.findIndex((o: any) => String(o.item_id) === String(itemId));

      review.push({
        text: item ? item.item_text : `Item ${itemId}`,
        is_correct: i === correctIndex,
        correct_position: correctIndex + 1
      });
    }
    return review;
  }

  // ==========================================
  // TIMELINE HELPERS
  // ==========================================

  getTimelineCategory(type: string): string {
    if (['started', 'resumed'].includes(type)) return 'start';
    if (['answer_saved', 'answer_changed', 'auto_saved'].includes(type)) return 'answer';
    if (['navigated', 'section_changed', 'flagged', 'unflagged'].includes(type)) return 'navigation';
    if (['violation', 'warning', 'tab_switch', 'fullscreen_exit'].includes(type)) return 'violation';
    if (['submitted', 'auto_submitted', 'completed'].includes(type)) return 'submit';
    return 'navigation';
  }

  getTimelineIcon(type: string): string {
    const map: { [key: string]: string } = {
      'started': '@tui.play', 'resumed': '@tui.play',
      'answer_saved': '@tui.edit-3', 'answer_changed': '@tui.edit',
      'auto_saved': '@tui.save', 'navigated': '@tui.arrow-right',
      'section_changed': '@tui.layers', 'flagged': '@tui.flag',
      'unflagged': '@tui.flag', 'violation': '@tui.alert-triangle',
      'warning': '@tui.alert-circle', 'tab_switch': '@tui.external-link',
      'fullscreen_exit': '@tui.minimize-2', 'submitted': '@tui.check-circle',
      'auto_submitted': '@tui.clock', 'completed': '@tui.check-circle'
    };
    return map[type] || '@tui.circle';
  }

  formatTimelineEvent(event: AttemptTimeline): string {
    const map: { [key: string]: string } = {
      'started': 'Assessment started',
      'resumed': 'Assessment resumed',
      'answer_saved': `Answered question ${event.event_data?.question_order || ''}`,
      'answer_changed': `Changed answer for question ${event.event_data?.question_order || ''}`,
      'auto_saved': 'Progress auto-saved',
      'navigated': `Navigated to question ${event.event_data?.question_order || ''}`,
      'section_changed': `Moved to section: ${event.event_data?.section_title || ''}`,
      'flagged': `Flagged question ${event.event_data?.question_order || ''}`,
      'unflagged': `Unflagged question ${event.event_data?.question_order || ''}`,
      'violation': `Proctoring violation: ${event.event_data?.violation_type || ''}`,
      'warning': `Warning issued: ${event.event_data?.warning_message || ''}`,
      'tab_switch': 'Switched away from assessment tab',
      'fullscreen_exit': 'Exited fullscreen mode',
      'submitted': 'Assessment submitted',
      'auto_submitted': 'Assessment auto-submitted (time expired)',
      'completed': 'Assessment completed'
    };
    return map[event.event_type] || event.event_type;
  }
}
