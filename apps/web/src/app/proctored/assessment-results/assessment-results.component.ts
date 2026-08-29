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

    <!-- ==========================================
         LOADING STATE
         ========================================== -->
    <div class="loading-container" *ngIf="loading">
      <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading results...</span>
      </div>
      <p class="mt-3 text-muted">Loading your results...</p>
    </div>

    <!-- ==========================================
         ERROR STATE
         ========================================== -->
    <div class="error-container" *ngIf="error && !loading">
      <div class="alert alert-danger d-flex align-items-center">
        <tui-icon icon="@tui.alert-triangle" class="me-2"></tui-icon>
        <div>
          <strong>Error:</strong> {{ error }}
          <button class="btn btn-sm btn-outline-danger ms-3" (click)="loadResults()">
            <tui-icon icon="@tui.refresh-cw" class="me-1"></tui-icon> Retry
          </button>
        </div>
      </div>
    </div>

    <!-- ==========================================
         RESULTS CONTENT
         ========================================== -->
    <ng-container *ngIf="!loading && !error && result">

      <!-- Result Header -->
      <div class="result-header" [class.passed]="result.passed" [class.failed]="!result.passed"
           [class.pending]="result.grading_status === 'pending'">
        <div class="container-fluid p-0">
          <div class="row align-items-center">
            <div class="col-md-8">
              <nav aria-label="breadcrumb" class="mb-2">
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
              <h4 class="mb-1">{{ result.assessment_title }}</h4>
              <div class="result-meta">
                <span class="badge me-2" [ngClass]="getTypeBadgeClass(result.assessment_type)">
                  {{ formatAssessmentType(result.assessment_type) }}
                </span>
                <span class="text-muted-light">
                  <tui-icon icon="@tui.calendar" class="me-1"></tui-icon>
                  {{ formatDate(result.completed_at) }}
                </span>
                <span class="text-muted-light ms-3">
                  <tui-icon icon="@tui.clock" class="me-1"></tui-icon>
                  {{ formatDuration(result.duration_seconds) }}
                </span>
                <span class="text-muted-light ms-3">
                  Attempt {{ result.attempt_number }}<span *ngIf="result.max_attempts > 0"> of {{ result.max_attempts }}</span>
                </span>
              </div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
              <div class="score-display">
                <div class="score-circle" [class.passed]="result.passed"
                     [class.failed]="!result.passed"
                     [class.pending]="result.grading_status === 'pending'">
                  <span class="score-value" *ngIf="result.grading_status !== 'pending'">
                    {{ result.percentage | number:'1.0-0' }}%
                  </span>
                  <span class="score-value" *ngIf="result.grading_status === 'pending'">
                    <tui-icon icon="@tui.clock"></tui-icon>
                  </span>
                  <span class="score-label">
                    {{ result.grading_status === 'pending' ? 'Pending' : result.grade }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Result Status Banner -->
      <div class="status-banner" [ngClass]="getStatusBannerClass()">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="d-flex align-items-center">
            <tui-icon [icon]="getStatusIcon()" class="me-2"></tui-icon>
            <div>
              <strong>{{ getStatusTitle() }}</strong>
              <p class="mb-0 small" *ngIf="result.feedback_message">{{ result.feedback_message }}</p>
              <p class="mb-0 small" *ngIf="result.grading_status === 'pending' && result.pending_manual_count > 0">
                {{ result.pending_manual_count }} question(s) awaiting manual grading
              </p>
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-light" *ngIf="result.certificate_id"
                    (click)="viewCertificate()">
              <tui-icon icon="@tui.award" class="me-1"></tui-icon> View Certificate
            </button>
            <button class="btn btn-sm btn-outline-light"
                    *ngIf="!result.passed && result.remaining_attempts > 0"
                    (click)="retakeAssessment()">
              <tui-icon icon="@tui.refresh-cw" class="me-1"></tui-icon> Retake
              ({{ result.remaining_attempts }} left)
            </button>
            <button class="btn btn-sm btn-outline-light" (click)="printResults()">
              <tui-icon icon="@tui.printer" class="me-1"></tui-icon> Print
            </button>
          </div>
        </div>
      </div>

      <!-- Points & Badges Earned -->
      <div class="rewards-bar" *ngIf="result.points_earned > 0 || result.badges_earned?.length">
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <span class="reward-item" *ngIf="result.points_earned > 0">
            <tui-icon icon="@tui.star" class="text-warning me-1"></tui-icon>
            <strong>+{{ result.points_earned }}</strong> points earned
          </span>
          <span class="reward-item" *ngFor="let badge of result.badges_earned">
            <tui-icon icon="@tui.award" class="text-info me-1"></tui-icon>
            Badge: <strong>{{ badge.name }}</strong>
          </span>
        </div>
      </div>

      <!-- Tab Navigation -->
      <ul class="nav nav-tabs result-tabs mt-4">
        <li class="nav-item">
          <a class="nav-link" [class.active]="activeTab === 'summary'"
             (click)="activeTab = 'summary'" href="javascript:void(0)">
            <tui-icon icon="@tui.bar-chart-2" class="me-1"></tui-icon> Summary
          </a>
        </li>
        <li class="nav-item" *ngIf="result.show_score_breakdown">
          <a class="nav-link" [class.active]="activeTab === 'sections'"
             (click)="activeTab = 'sections'" href="javascript:void(0)">
            <tui-icon icon="@tui.layers" class="me-1"></tui-icon> Sections
          </a>
        </li>
        <li class="nav-item" *ngIf="result.show_correct_answers">
          <a class="nav-link" [class.active]="activeTab === 'review'"
             (click)="activeTab = 'review'; loadQuestionResults()" href="javascript:void(0)">
            <tui-icon icon="@tui.check-square" class="me-1"></tui-icon> Question Review
            <span class="badge bg-secondary ms-1" *ngIf="questionResults.length">
              {{ questionResults.length }}
            </span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" [class.active]="activeTab === 'timeline'"
             (click)="activeTab = 'timeline'; loadTimeline()" href="javascript:void(0)">
            <tui-icon icon="@tui.activity" class="me-1"></tui-icon> Timeline
          </a>
        </li>
      </ul>

      <!-- ==========================================
           TAB: SUMMARY
           ========================================== -->
      <div class="tab-content-area" *ngIf="activeTab === 'summary'">

        <!-- Score Overview Cards -->
        <div class="row g-3 mb-4">
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-icon text-primary">
                <tui-icon icon="@tui.target"></tui-icon>
              </div>
              <div class="stat-value">{{ result.total_score | number:'1.0-1' }}/{{ result.max_score | number:'1.0-1' }}</div>
              <div class="stat-label">Score</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-icon" [class.text-success]="result.passed" [class.text-danger]="!result.passed">
                <tui-icon [icon]="result.passed ? '@tui.check-circle' : '@tui.x-circle'"></tui-icon>
              </div>
              <div class="stat-value">{{ result.percentage | number:'1.0-0' }}%</div>
              <div class="stat-label">Percentage ({{ result.passing_score }}% to pass)</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-icon text-info">
                <tui-icon icon="@tui.clock"></tui-icon>
              </div>
              <div class="stat-value">{{ formatDuration(result.duration_seconds) }}</div>
              <div class="stat-label">Time Taken</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-icon text-warning">
                <tui-icon icon="@tui.award"></tui-icon>
              </div>
              <div class="stat-value">{{ result.grade }}</div>
              <div class="stat-label">Grade</div>
            </div>
          </div>
        </div>

        <!-- Score Breakdown -->
        <div class="card mb-4" *ngIf="scoreBreakdown">
          <div class="card-header">
            <h6 class="mb-0">
              <tui-icon icon="@tui.pie-chart" class="me-2"></tui-icon> Score Breakdown
            </h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <!-- Auto-Graded -->
              <div class="col-md-4">
                <div class="breakdown-item">
                  <div class="breakdown-header">
                    <span class="breakdown-label">Auto-Graded</span>
                    <span class="breakdown-count">{{ scoreBreakdown.auto_graded_count }} questions</span>
                  </div>
                  <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-success" role="progressbar"
                         [style.width.%]="getBreakdownPercentage(scoreBreakdown.auto_graded_score, scoreBreakdown.auto_graded_max)">
                    </div>
                  </div>
                  <span class="breakdown-score">
                    {{ scoreBreakdown.auto_graded_score | number:'1.0-1' }} / {{ scoreBreakdown.auto_graded_max | number:'1.0-1' }}
                  </span>
                </div>
              </div>

              <!-- Manual-Graded -->
              <div class="col-md-4" *ngIf="scoreBreakdown.manual_graded_count > 0">
                <div class="breakdown-item">
                  <div class="breakdown-header">
                    <span class="breakdown-label">Manual-Graded</span>
                    <span class="breakdown-count">{{ scoreBreakdown.manual_graded_count }} questions</span>
                  </div>
                  <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-info" role="progressbar"
                         [style.width.%]="getBreakdownPercentage(scoreBreakdown.manual_graded_score, scoreBreakdown.manual_graded_max)">
                    </div>
                  </div>
                  <span class="breakdown-score">
                    {{ scoreBreakdown.manual_graded_score | number:'1.0-1' }} / {{ scoreBreakdown.manual_graded_max | number:'1.0-1' }}
                  </span>
                </div>
              </div>

              <!-- Pending -->
              <div class="col-md-4" *ngIf="scoreBreakdown.pending_count > 0">
                <div class="breakdown-item">
                  <div class="breakdown-header">
                    <span class="breakdown-label">Pending Grading</span>
                    <span class="breakdown-count">{{ scoreBreakdown.pending_count }} questions</span>
                  </div>
                  <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-warning" role="progressbar" style="width: 100%"></div>
                  </div>
                  <span class="breakdown-score text-warning">
                    <tui-icon icon="@tui.clock" class="me-1"></tui-icon> Awaiting review
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Question Summary Donut -->
        <div class="card mb-4" *ngIf="scoreBreakdown">
          <div class="card-header">
            <h6 class="mb-0">
              <tui-icon icon="@tui.check-square" class="me-2"></tui-icon> Answer Summary
            </h6>
          </div>
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-md-6">
                <div class="answer-summary-visual">
                  <div class="summary-ring">
                    <svg viewBox="0 0 120 120" class="ring-svg">
                      <circle cx="60" cy="60" r="52" fill="none" stroke="#e9ecef" stroke-width="12"/>
                      <circle cx="60" cy="60" r="52" fill="none" stroke="#28a745" stroke-width="12"
                              [attr.stroke-dasharray]="getCorrectDash()" stroke-dashoffset="0"
                              transform="rotate(-90 60 60)" stroke-linecap="round"/>
                      <circle cx="60" cy="60" r="52" fill="none" stroke="#ffc107" stroke-width="12"
                              [attr.stroke-dasharray]="getPartialDash()" [attr.stroke-dashoffset]="getPartialOffset()"
                              transform="rotate(-90 60 60)" stroke-linecap="round"/>
                      <circle cx="60" cy="60" r="52" fill="none" stroke="#dc3545" stroke-width="12"
                              [attr.stroke-dasharray]="getIncorrectDash()" [attr.stroke-dashoffset]="getIncorrectOffset()"
                              transform="rotate(-90 60 60)" stroke-linecap="round"/>
                    </svg>
                    <div class="ring-center">
                      <span class="ring-value">{{ scoreBreakdown.total_questions }}</span>
                      <span class="ring-label">Questions</span>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="summary-legend">
                  <div class="legend-item" *ngFor="let item of getAnswerSummary()">
                    <span class="legend-dot" [style.background]="item.color"></span>
                    <span class="legend-label">{{ item.label }}</span>
                    <span class="legend-value">{{ item.count }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ==========================================
           TAB: SECTIONS
           ========================================== -->
      <div class="tab-content-area" *ngIf="activeTab === 'sections'">
        <div class="section-results" *ngIf="sectionResults.length; else noSections">
          <div class="card mb-3" *ngFor="let section of sectionResults; let i = index">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                  <h6 class="mb-1">
                    <span class="section-number">Section {{ section.section_order }}</span>
                    {{ section.section_title }}
                  </h6>
                  <small class="text-muted">
                    {{ section.total_questions }} questions
                    · {{ section.answered_count }} answered
                  </small>
                </div>
                <div class="text-end">
                  <div class="section-score" [class.text-success]="section.section_percentage >= 70"
                       [class.text-warning]="section.section_percentage >= 50 && section.section_percentage < 70"
                       [class.text-danger]="section.section_percentage < 50">
                    {{ section.section_percentage | number:'1.0-0' }}%
                  </div>
                  <small class="text-muted">
                    {{ section.section_score | number:'1.0-1' }} / {{ section.section_max_score | number:'1.0-1' }}
                  </small>
                </div>
              </div>

              <!-- Section Progress Bar -->
              <div class="progress mb-3" style="height: 24px;">
                <div class="progress-bar bg-success" role="progressbar"
                     [style.width.%]="(section.correct_count / section.total_questions) * 100"
                     [attr.title]="section.correct_count + ' correct'">
                  <span *ngIf="section.correct_count > 0">{{ section.correct_count }} ✓</span>
                </div>
                <div class="progress-bar bg-warning" role="progressbar"
                     [style.width.%]="(section.partial_count / section.total_questions) * 100"
                     [attr.title]="section.partial_count + ' partial'">
                  <span *ngIf="section.partial_count > 0">{{ section.partial_count }} ~</span>
                </div>
                <div class="progress-bar bg-danger" role="progressbar"
                     [style.width.%]="(section.incorrect_count / section.total_questions) * 100"
                     [attr.title]="section.incorrect_count + ' incorrect'">
                  <span *ngIf="section.incorrect_count > 0">{{ section.incorrect_count }} ✗</span>
                </div>
                <div class="progress-bar bg-secondary" role="progressbar"
                     [style.width.%]="(section.skipped_count / section.total_questions) * 100"
                     [attr.title]="section.skipped_count + ' skipped'">
                  <span *ngIf="section.skipped_count > 0">{{ section.skipped_count }} -</span>
                </div>
              </div>

              <!-- Section Stats -->
              <div class="section-stats">
                <span class="stat-pill bg-success-subtle text-success">
                  <tui-icon icon="@tui.check" class="me-1"></tui-icon> {{ section.correct_count }} Correct
                </span>
                <span class="stat-pill bg-warning-subtle text-warning" *ngIf="section.partial_count > 0">
                  <tui-icon icon="@tui.minus" class="me-1"></tui-icon> {{ section.partial_count }} Partial
                </span>
                <span class="stat-pill bg-danger-subtle text-danger">
                  <tui-icon icon="@tui.x" class="me-1"></tui-icon> {{ section.incorrect_count }} Incorrect
                </span>
                <span class="stat-pill bg-secondary-subtle text-secondary" *ngIf="section.skipped_count > 0">
                  <tui-icon icon="@tui.minus-circle" class="me-1"></tui-icon> {{ section.skipped_count }} Skipped
                </span>
              </div>
            </div>
          </div>
        </div>
        <ng-template #noSections>
          <div class="empty-state">
            <tui-icon icon="@tui.layers" class="empty-icon"></tui-icon>
            <p>No section breakdown available</p>
          </div>
        </ng-template>
      </div>

      <!-- ==========================================
           TAB: QUESTION REVIEW
           ========================================== -->
      <div class="tab-content-area" *ngIf="activeTab === 'review'">

        <!-- Filter Bar -->
        <div class="review-filter-bar mb-3">
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm" [class.btn-primary]="reviewFilter === 'all'"
                    [class.btn-secondary]="reviewFilter !== 'all'"
                    (click)="reviewFilter = 'all'">
              All ({{ questionResults.length }})
            </button>
            <button class="btn btn-sm" [class.btn-success]="reviewFilter === 'correct'"
                    [class.btn-secondary]="reviewFilter !== 'correct'"
                    (click)="reviewFilter = 'correct'">
              <tui-icon icon="@tui.check" class="me-1"></tui-icon>
              Correct ({{ getFilteredCount('correct') }})
            </button>
            <button class="btn btn-sm" [class.btn-warning]="reviewFilter === 'partial'"
                    [class.btn-secondary]="reviewFilter !== 'partial'"
                    (click)="reviewFilter = 'partial'">
              <tui-icon icon="@tui.minus" class="me-1"></tui-icon>
              Partial ({{ getFilteredCount('partial') }})
            </button>
            <button class="btn btn-sm" [class.btn-danger]="reviewFilter === 'incorrect'"
                    [class.btn-secondary]="reviewFilter !== 'incorrect'"
                    (click)="reviewFilter = 'incorrect'">
              <tui-icon icon="@tui.x" class="me-1"></tui-icon>
              Incorrect ({{ getFilteredCount('incorrect') }})
            </button>
            <button class="btn btn-sm" [class.btn-info]="reviewFilter === 'flagged'"
                    [class.btn-secondary]="reviewFilter !== 'flagged'"
                    (click)="reviewFilter = 'flagged'"
                    *ngIf="getFlaggedCount() > 0">
              <tui-icon icon="@tui.flag" class="me-1"></tui-icon>
              Flagged ({{ getFlaggedCount() }})
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
                      <pre>{{ q.user_answer | json }}</pre>
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
          <div class="empty-state" *ngIf="getFilteredQuestions().length === 0 && questionResults.length > 0">
            <tui-icon icon="@tui.filter" class="empty-icon"></tui-icon>
            <p>No questions match the selected filter</p>
          </div>
        </div>
      </div>

      <!-- ==========================================
           TAB: TIMELINE
           ========================================== -->
      <div class="tab-content-area" *ngIf="activeTab === 'timeline'">
        <div class="text-center py-4" *ngIf="loadingTimeline">
          <div class="spinner-border spinner-border-sm text-primary"></div>
          <span class="ms-2 text-muted">Loading timeline...</span>
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

          <div class="empty-state" *ngIf="timeline.length === 0">
            <tui-icon icon="@tui.activity" class="empty-icon"></tui-icon>
            <p>No timeline events available</p>
          </div>
        </div>
      </div>

    </ng-container>
  `,
  styles: [`
    :host { display: block; }

    /* Loading & Error */
    .loading-container, .error-container {
      text-align: center;
      padding: 3rem 1rem;
    }

    /* Result Header */
    .result-header {
      background: #f8f9fa;
      border-radius: 0.5rem;
      padding: 1.5rem;
      margin-bottom: 0;
      border-bottom: 3px solid #e9ecef;

      &.passed { border-bottom-color: #28a745; }
      &.failed { border-bottom-color: #dc3545; }
      &.pending { border-bottom-color: #ffc107; }
    }

    .breadcrumb-sm { font-size: 0.8rem; }
    .breadcrumb-sm a { color: #6c757d; text-decoration: none; &:hover { color: #495057; } }

    .result-meta {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.25rem;
      font-size: 0.85rem;
    }

    .text-muted-light { color: #6c757d; }

    /* Score Circle */
    .score-display { display: inline-block; }

    .score-circle {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      border: 4px solid #dc3545;
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: #fff;

      &.passed { border-color: #28a745; }
      &.failed { border-color: #dc3545; }
      &.pending { border-color: #ffc107; }

      .score-value { font-size: 1.5rem; font-weight: 700; color: #212529; line-height: 1; }
      .score-label { font-size: 0.7rem; color: #6c757d; margin-top: 2px; }
    }

    /* Status Banner */
    .status-banner {
      padding: 1rem 1.5rem;
      border-radius: 0 0 0.5rem 0.5rem;
      color: #fff;
      margin-bottom: 1rem;

      &.banner-passed { background: #28a745; }
      &.banner-failed { background: #dc3545; }
      &.banner-pending { background: #ffc107; color: #212529; }

      .btn-outline-light { border-color: rgba(255,255,255,0.5); &:hover { background: rgba(255,255,255,0.15); } }
    }

    /* Rewards Bar */
    .rewards-bar {
      background: #fff8e6;
      border: 1px solid #ffc107;
      border-radius: 0.5rem;
      padding: 0.75rem 1rem;
      margin-bottom: 1rem;
    }

    .reward-item { font-size: 0.9rem; }

    /* Tabs */
    .result-tabs {
      .nav-link {
        color: #6c757d;
        font-size: 0.9rem;
        padding: 0.5rem 1rem;
        &.active { color: #c9a227; border-color: #dee2e6 #dee2e6 #fff; font-weight: 600; }
        &:hover:not(.active) { color: #495057; }
      }
    }

    .tab-content-area { padding: 1.5rem 0; }

    /* Stat Cards */
    .stat-card {
      background: #fff;
      border: 1px solid #e9ecef;
      border-radius: 0.5rem;
      padding: 1.25rem;
      text-align: center;

      .stat-icon { font-size: 1.5rem; margin-bottom: 0.5rem; }
      .stat-value { font-size: 1.25rem; font-weight: 700; color: #212529; }
      .stat-label { font-size: 0.75rem; color: #6c757d; margin-top: 0.25rem; }
    }

    /* Breakdown */
    .breakdown-item {
      padding: 1rem;
      background: #f8f9fa;
      border-radius: 0.5rem;

      .breakdown-header { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
      .breakdown-label { font-weight: 600; font-size: 0.9rem; }
      .breakdown-count { font-size: 0.8rem; color: #6c757d; }
      .breakdown-score { font-size: 0.9rem; font-weight: 600; }
    }

    /* Answer Summary Ring */
    .answer-summary-visual { display: flex; justify-content: center; padding: 1rem; }

    .summary-ring {
      position: relative;
      width: 160px;
      height: 160px;

      .ring-svg { width: 100%; height: 100%; }

      .ring-center {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;

        .ring-value { display: block; font-size: 1.5rem; font-weight: 700; color: #212529; }
        .ring-label { display: block; font-size: 0.75rem; color: #6c757d; }
      }
    }

    .summary-legend {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      justify-content: center;
      height: 100%;

      .legend-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;

        .legend-dot {
          width: 12px;
          height: 12px;
          border-radius: 50%;
          flex-shrink: 0;
        }

        .legend-label { flex: 1; font-size: 0.9rem; color: #495057; }
        .legend-value { font-weight: 600; font-size: 0.9rem; }
      }
    }

    /* Section Results */
    .section-number {
      display: inline-block;
      background: #e9ecef;
      color: #495057;
      font-size: 0.75rem;
      padding: 0.1rem 0.5rem;
      border-radius: 0.25rem;
      margin-right: 0.5rem;
    }

    .section-score { font-size: 1.5rem; font-weight: 700; }

    .section-stats { display: flex; gap: 0.5rem; flex-wrap: wrap; }

    .stat-pill {
      display: inline-flex;
      align-items: center;
      font-size: 0.8rem;
      padding: 0.25rem 0.75rem;
      border-radius: 1rem;
    }

    /* Review Filter */
    .review-filter-bar { padding: 0.5rem 0; }

    /* Question Review Items */
    .question-review-item {
      border: 1px solid #e9ecef;
      border-radius: 0.5rem;
      margin-bottom: 0.75rem;
      overflow: hidden;
      border-left: 4px solid #e9ecef;

      &.correct { border-left-color: #28a745; }
      &.partial { border-left-color: #ffc107; }
      &.incorrect { border-left-color: #dc3545; }
      &.pending { border-left-color: #17a2b8; }
    }

    .question-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.75rem 1rem;
      cursor: pointer;
      background: #fff;
      transition: background 0.15s;

      &:hover { background: #f8f9fa; }

      .question-number {
        font-weight: 700;
        color: #495057;
        min-width: 30px;
      }

      .question-type-badge {
        font-size: 0.7rem;
        padding: 0.15rem 0.5rem;
        background: #e9ecef;
        border-radius: 0.25rem;
        color: #495057;
      }

      .question-points { font-weight: 600; font-size: 0.9rem; }
      .question-time { font-size: 0.8rem; }
      .expand-icon { color: #6c757d; }
    }

    .question-detail { padding: 1rem; border-top: 1px solid #e9ecef; background: #fafbfc; }

    .question-text-area {
      margin-bottom: 1rem;
      padding: 1rem;
      background: #fff;
      border-radius: 0.5rem;
      border: 1px solid #e9ecef;
      font-size: 0.95rem;
    }

    .question-media { max-width: 100%; border-radius: 0.5rem; margin-top: 0.75rem; }

    .answer-section {
      margin-bottom: 1rem;

      .answer-section-title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.5rem;
        padding-bottom: 0.25rem;
        border-bottom: 1px solid #e9ecef;
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

      &.selected { background: #f8f9fa; border-color: #dee2e6; }
      &.correct-option { background: #d4edda; border-color: #c3e6cb; }
      &.wrong-option { background: #f8d7da; border-color: #f5c6cb; }

      .option-marker { flex-shrink: 0; width: 20px; }
      .option-text { flex: 1; }
      .option-feedback { display: block; font-size: 0.8rem; font-style: italic; margin-top: 0.25rem; }
    }

    /* Text Answers */
    .text-answer-display {
      padding: 0.75rem;
      background: #fff;
      border: 1px solid #dee2e6;
      border-radius: 0.375rem;
      font-size: 0.9rem;
      margin-bottom: 0.5rem;

      &.essay-display { min-height: 100px; }
    }

    .correct-answer-display {
      padding: 0.5rem 0.75rem;
      background: #d4edda;
      border: 1px solid #c3e6cb;
      border-radius: 0.375rem;
      font-size: 0.85rem;
    }

    .grading-note {
      padding: 0.5rem 0.75rem;
      background: #d1ecf1;
      border: 1px solid #bee5eb;
      border-radius: 0.375rem;
      font-size: 0.85rem;
      color: #0c5460;
    }

    /* Fill in Blank */
    .blank-item {
      display: flex;
      gap: 0.5rem;
      align-items: center;
      padding: 0.25rem 0;
      font-size: 0.9rem;

      .blank-number { font-weight: 600; color: #6c757d; min-width: 60px; }
      .blank-user-answer { font-weight: 600; }
      .blank-correct { font-size: 0.85rem; color: #28a745; }
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
      border: 1px solid #e9ecef;

      &.correct-match { background: #d4edda; border-color: #c3e6cb; }
      &.wrong-match { background: #f8d7da; border-color: #f5c6cb; }

      .match-left { font-weight: 600; min-width: 120px; }
      .match-arrow { flex-shrink: 0; }
      .match-right { flex: 1; }
      .match-correct { font-size: 0.8rem; color: #28a745; }
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
      border: 1px solid #e9ecef;

      &.correct-order { background: #d4edda; border-color: #c3e6cb; }
      &.wrong-order { background: #f8d7da; border-color: #f5c6cb; }

      .order-number { font-weight: 700; color: #495057; min-width: 24px; }
      .order-text { flex: 1; }
    }

    /* File Upload Answer */
    .file-upload-answer {
      display: flex;
      align-items: center;
      padding: 0.75rem;
      background: #f8f9fa;
      border-radius: 0.375rem;
      font-size: 0.9rem;
      margin-bottom: 0.5rem;

      a { color: #c9a227; text-decoration: none; &:hover { text-decoration: underline; } }
    }

    /* Explanation & Feedback */
    .explanation-section, .feedback-section {
      margin-top: 1rem;
      padding: 0.75rem;
      border-radius: 0.375rem;
      font-size: 0.9rem;
    }

    .explanation-section {
      background: #fff3cd;
      border: 1px solid #ffc107;

      .explanation-title { color: #856404; font-size: 0.85rem; margin-bottom: 0.5rem; }
      .explanation-content { color: #856404; }
    }

    .feedback-section {
      background: #d1ecf1;
      border: 1px solid #bee5eb;

      .feedback-title { color: #0c5460; font-size: 0.85rem; margin-bottom: 0.5rem; }
      .feedback-content { color: #0c5460; }
    }

    /* Timeline */
    .timeline-container { position: relative; padding-left: 2rem; }

    .timeline-item {
      position: relative;
      padding-bottom: 1.25rem;
      padding-left: 1.5rem;
      border-left: 2px solid #e9ecef;

      &:last-child { border-left-color: transparent; }

      .timeline-dot {
        position: absolute;
        left: -7px;
        top: 4px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #6c757d;
        border: 2px solid #fff;
      }

      &.timeline-start .timeline-dot { background: #28a745; }
      &.timeline-answer .timeline-dot { background: #17a2b8; }
      &.timeline-navigation .timeline-dot { background: #6c757d; }
      &.timeline-violation .timeline-dot { background: #dc3545; }
      &.timeline-submit .timeline-dot { background: #c9a227; }

      .timeline-time { font-size: 0.75rem; color: #6c757d; margin-bottom: 0.25rem; }
      .timeline-event { font-size: 0.9rem; color: #212529; }
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: #6c757d;

      .empty-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
      p { font-size: 1rem; margin-bottom: 0.5rem; }
    }

    /* Responsive */
    @media (max-width: 768px) {
      .result-header { padding: 1rem; }
      .score-circle { width: 80px; height: 80px; .score-value { font-size: 1.2rem; } }
      .question-header { flex-direction: column; gap: 0.5rem; align-items: flex-start; }
      .match-pair { flex-wrap: wrap; }
      .stat-card { padding: 0.75rem; .stat-value { font-size: 1rem; } }
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
      question_type: String(q['type'] ?? ''),
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
      { label: 'Correct', count: c.correct, color: '#28a745' },
      { label: 'Partial Credit', count: c.partial, color: '#ffc107' },
      { label: 'Incorrect', count: c.incorrect, color: '#dc3545' },
      { label: 'Skipped', count: c.skipped, color: '#adb5bd' }
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

  isOptionSelected(userAnswer: any, option: any): boolean {
    if (!userAnswer) return false;
    return String(userAnswer) === String(option.option_id);
  }

  isOptionInArray(userAnswer: any, option: any): boolean {
    if (!userAnswer || !Array.isArray(userAnswer)) return false;
    return userAnswer.map(String).includes(String(option.option_id));
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
