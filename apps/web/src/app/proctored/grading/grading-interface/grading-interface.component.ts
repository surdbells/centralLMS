// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 11
// Admin: Grading Interface Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import { AssessmentsService } from '../../../services/assessments.service';

// ==========================================
// INTERFACES
// ==========================================

interface GradingDetails {
  queue_id: string;    // attempt id
  attempt_id: string;
  answer_id: string;   // question id -- an answer is (attemptId, questionId) now
  assessment_id: string;
  assessment_title: string;
  question_id: string;
  question_text: string;
  question_type: string;
  question_image: string | null;
  section_title: string;
  max_points: number;
  learner_id: string;
  learner_name: string;
  learner_email: string;
  attempt_number: number;
  submitted_at: string;
  answer_text: string;
  answer_data: any;
  file_urls: string[];
  has_rubric: boolean;
  rubric_id: string | null;
  rubric: RubricDetail | null;
  existing_grade: ExistingGrade | null;
  existing_comments: FeedbackComment[];
  grading_history: GradingHistoryItem[];
  question_context: QuestionContext;
}

interface RubricDetail {
  rubric_id: number;
  name: string;
  rubric_type: string;
  total_points: number;
  criteria: RubricCriterion[];
}

interface RubricCriterion {
  criterion_id: number;
  title: string;
  description: string;
  max_points: number;
  weight: number;
  sort_order: number;
  levels: RubricLevel[];
}

interface RubricLevel {
  level_id: number;
  label: string;
  description: string;
  points: number;
  sort_order: number;
}

interface ExistingGrade {
  score: number;
  graded_by_name: string;
  graded_at: string;
  rubric_scores: { criterion_id: number; level_id: number; points: number; comment: string }[];
}

interface FeedbackComment {
  comment_id: number;
  comment_type: string;
  comment_text: string;
  created_by_name: string;
  created_at: string;
  selection_start: number | null;
  selection_end: number | null;
}

interface GradingHistoryItem {
  action: string;
  old_value: string | null;
  new_value: string | null;
  performed_by_name: string;
  performed_at: string;
}

interface QuestionContext {
  correct_answer: string | null;
  answer_key: string | null;
  explanation: string | null;
  rubric_criteria_hints: string | null;
  class_avg_score: number | null;
  total_attempts: number;
}

interface QuickFeedback {
  feedback_id: number;
  title: string;
  content: string;
  category: string;
  usage_count: number;
}

@Component({
  selector: 'app-grading-interface',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="grading-interface" *ngIf="!loading && details">
      <!-- ==========================================
           TOP BAR
           ========================================== -->
      <div class="grading-topbar">
        <button class="btn btn-sm btn-secondary" (click)="goBack()">
          <tui-icon icon="@tui.arrow-left"></tui-icon> Back to Queue
        </button>
        <div class="topbar-info">
          <span class="badge bg-secondary">{{ details.assessment_title }}</span>
          <span class="badge bg-light text-dark">
            {{ details.learner_name }} · Attempt #{{ details.attempt_number }}
          </span>
        </div>
        <div class="topbar-actions">
          <button class="btn btn-sm btn-secondary" (click)="navigateItem('prev')" [disabled]="!hasPrevItem">
            <tui-icon icon="@tui.chevron-left"></tui-icon> Previous
          </button>
          <button class="btn btn-sm btn-secondary" (click)="navigateItem('next')" [disabled]="!hasNextItem">
            Next <tui-icon icon="@tui.chevron-right"></tui-icon>
          </button>
        </div>
      </div>

      <!-- ==========================================
           MAIN LAYOUT (2-column)
           ========================================== -->
      <div class="grading-layout">
        <!-- LEFT: Question & Answer -->
        <div class="grading-left">
          <!-- Question Card -->
          <div class="grade-card question-card">
            <div class="card-header">
              <h5>
                <tui-icon icon="@tui.help-circle"></tui-icon> Question
                <span class="badge badge-qtype ms-2">{{ formatQuestionType(details.question_type) }}</span>
              </h5>
              <span class="points-badge">{{ details.max_points }} pts</span>
            </div>
            <div class="card-body">
              <div class="question-text" [innerHTML]="details.question_text"></div>
              <img *ngIf="details.question_image" [src]="details.question_image" class="question-image" alt="Question image">
              <div class="question-context" *ngIf="details.question_context.correct_answer || details.question_context.explanation">
                <button class="btn btn-sm btn-outline-info" (click)="showContext = !showContext">
                  <tui-icon icon="@tui.info"></tui-icon>
                  {{ showContext ? 'Hide' : 'Show' }} Answer Key / Explanation
                </button>
                <div class="context-content" *ngIf="showContext">
                  <div class="context-item" *ngIf="details.question_context.correct_answer">
                    <strong>Expected Answer:</strong>
                    <div [innerHTML]="details.question_context.correct_answer"></div>
                  </div>
                  <div class="context-item" *ngIf="details.question_context.explanation">
                    <strong>Explanation:</strong>
                    <div>{{ details.question_context.explanation }}</div>
                  </div>
                  <div class="context-item" *ngIf="details.question_context.class_avg_score !== null">
                    <strong>Class Average:</strong>
                    <span>{{ details.question_context.class_avg_score | number:'1.1-1' }} / {{ details.max_points }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Answer Card -->
          <div class="grade-card answer-card">
            <div class="card-header">
              <h5><tui-icon icon="@tui.message-square"></tui-icon> Learner's Answer</h5>
              <span class="text-muted small">Submitted {{ formatDatetime(details.submitted_at) }}</span>
            </div>
            <div class="card-body">
              <!-- Essay -->
              <div *ngIf="details.question_type === 'essay'" class="answer-essay" [innerHTML]="details.answer_text"></div>

              <!-- Short Answer -->
              <div *ngIf="details.question_type === 'short_answer'" class="answer-short">
                <pre>{{ details.answer_text }}</pre>
              </div>

              <!-- Fill in Blank -->
              <div *ngIf="details.question_type === 'fill_blank'" class="answer-blanks">
                <div class="blank-item" *ngFor="let blank of parseAnswerData(); let i = index">
                  <span class="blank-label">Blank {{ i + 1 }}:</span>
                  <span class="blank-value">{{ blank }}</span>
                </div>
              </div>

              <!-- File Upload -->
              <div *ngIf="details.question_type === 'file_upload'" class="answer-files">
                <div class="file-item" *ngFor="let url of details.file_urls">
                  <tui-icon icon="@tui.file"></tui-icon>
                  <a [href]="url" target="_blank">{{ getFileName(url) }}</a>
                  <button class="btn btn-sm btn-primary ms-2" (click)="previewFile(url)">
                    <tui-icon icon="@tui.eye"></tui-icon>
                  </button>
                </div>
              </div>

              <!-- Matching -->
              <div *ngIf="details.question_type === 'matching'" class="answer-matching">
                <table class="table table-sm">
                  <thead><tr><th>Item</th><th>Matched To</th></tr></thead>
                  <tbody>
                    <tr *ngFor="let pair of parseAnswerData()">
                      <td>{{ pair.item }}</td><td>{{ pair.match }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Ordering -->
              <div *ngIf="details.question_type === 'ordering'" class="answer-ordering">
                <ol>
                  <li *ngFor="let item of parseAnswerData()">{{ item }}</li>
                </ol>
              </div>

              <!-- Generic / Other -->
              <div *ngIf="!['essay','short_answer','fill_blank','file_upload','matching','ordering'].includes(details.question_type)"
                   class="answer-generic">
                <pre>{{ details.answer_text || (details.answer_data | json) }}</pre>
              </div>
            </div>
          </div>

          <!-- Feedback Comments -->
          <div class="grade-card feedback-card">
            <div class="card-header">
              <h5><tui-icon icon="@tui.message-circle"></tui-icon> Feedback</h5>
            </div>
            <div class="card-body">
              <!-- Existing Comments -->
              <div class="comment-list" *ngIf="details.existing_comments.length > 0">
                <div class="comment-item" *ngFor="let c of details.existing_comments"
                     [ngClass]="'comment-' + c.comment_type">
                  <div class="comment-header">
                    <span class="comment-type-badge" [ngClass]="'type-' + c.comment_type">
                      <tui-icon [icon]="getCommentIcon(c.comment_type)"></tui-icon>
                      {{ formatCommentType(c.comment_type) }}
                    </span>
                    <span class="comment-meta">{{ c.created_by_name }} · {{ formatDatetime(c.created_at) }}</span>
                  </div>
                  <div class="comment-body">{{ c.comment_text }}</div>
                </div>
              </div>

              <!-- Feedback for the learner, saved together with the grade -->
              <div class="add-comment">
                <textarea class="form-control" rows="3" placeholder="Feedback for the learner (saved with the grade)..."
                          [(ngModel)]="newComment.text"></textarea>
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT: Grading Panel -->
        <div class="grading-right">
          <!-- Score Entry -->
          <div class="grade-card score-card">
            <div class="card-header">
              <h5><tui-icon icon="@tui.award"></tui-icon> Score</h5>
            </div>
            <div class="card-body">
              <!-- Simple Point Grading -->
              <div class="simple-grading" *ngIf="!details.has_rubric || gradingMode === 'simple'">
                <div class="score-input-group">
                  <input type="number" class="form-control score-input" [(ngModel)]="gradeForm.score"
                         [min]="0" [max]="details.max_points" step="0.5" (input)="validateScore()">
                  <span class="score-divider">/</span>
                  <span class="score-max">{{ details.max_points }}</span>
                </div>
                <div class="score-percentage">
                  {{ getScorePercentage() | number:'1.0-0' }}%
                </div>
                <div class="score-quick-btns">
                  <button class="btn btn-sm btn-success" (click)="setScore(details.max_points)">Full</button>
                  <button class="btn btn-sm btn-outline-warning" (click)="setScore(details.max_points * 0.75)">75%</button>
                  <button class="btn btn-sm btn-secondary" (click)="setScore(details.max_points * 0.5)">50%</button>
                  <button class="btn btn-sm btn-outline-danger" (click)="setScore(0)">Zero</button>
                </div>
              </div>

              <!-- Rubric Mode Toggle -->
              <div class="grading-mode-toggle" *ngIf="details.has_rubric">
                <button class="btn btn-sm" [ngClass]="gradingMode === 'rubric' ? 'btn-primary' : 'btn-secondary'"
                        (click)="gradingMode = 'rubric'">
                  <tui-icon icon="@tui.list"></tui-icon> Rubric
                </button>
                <button class="btn btn-sm" [ngClass]="gradingMode === 'simple' ? 'btn-primary' : 'btn-secondary'"
                        (click)="gradingMode = 'simple'">
                  <tui-icon icon="@tui.hash"></tui-icon> Simple
                </button>
              </div>

              <!-- Rubric Grading -->
              <div class="rubric-grading" *ngIf="details.has_rubric && gradingMode === 'rubric' && details.rubric">
                <div class="rubric-header">
                  <h6>{{ details.rubric.name }}</h6>
                  <span class="badge bg-secondary">{{ details.rubric.rubric_type }}</span>
                </div>
                <div class="criterion-list">
                  <div class="criterion-item" *ngFor="let c of details.rubric.criteria; let ci = index">
                    <div class="criterion-header">
                      <span class="criterion-title">{{ c.title }}</span>
                      <span class="criterion-weight" *ngIf="c.weight">Weight: {{ c.weight }}%</span>
                      <span class="criterion-points">{{ getRubricCriterionScore(c.criterion_id) | number:'1.1-1' }} / {{ c.max_points }}</span>
                    </div>
                    <p class="criterion-desc" *ngIf="c.description">{{ c.description }}</p>
                    <div class="level-options">
                      <button *ngFor="let lvl of c.levels" class="level-btn"
                              [class.selected]="isLevelSelected(c.criterion_id, lvl.level_id)"
                              (click)="selectLevel(c.criterion_id, lvl.level_id, lvl.points)">
                        <span class="level-label">{{ lvl.label }}</span>
                        <span class="level-points">{{ lvl.points }} pts</span>
                        <span class="level-desc" *ngIf="lvl.description">{{ lvl.description }}</span>
                      </button>
                    </div>
                    <input type="text" class="form-control form-control-sm mt-1"
                           placeholder="Comment for this criterion..."
                           [(ngModel)]="rubricComments[c.criterion_id]">
                  </div>
                </div>
                <div class="rubric-total">
                  <strong>Total Score:</strong>
                  <span class="rubric-total-score">{{ getRubricTotalScore() | number:'1.1-1' }} / {{ details.rubric.total_points }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Grade Adjustment -->
          <div class="grade-card adjustment-card">
            <div class="card-header">
              <h5><tui-icon icon="@tui.sliders"></tui-icon> Adjustments</h5>
              <button class="btn btn-sm btn-secondary" (click)="showAdjustment = !showAdjustment">
                <tui-icon [icon]="showAdjustment ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
              </button>
            </div>
            <div class="card-body" *ngIf="showAdjustment">
              <div class="adjustment-form">
                <select class="form-select form-select-sm" [(ngModel)]="adjustment.type">
                  <option value="">No Adjustment</option>
                  <option value="bonus">Bonus Points</option>
                  <option value="penalty">Penalty</option>
                  <option value="override">Override Score</option>
                </select>
                <input *ngIf="adjustment.type" type="number" class="form-control form-control-sm mt-1"
                       [(ngModel)]="adjustment.value" placeholder="Points" step="0.5">
                <input *ngIf="adjustment.type" type="text" class="form-control form-control-sm mt-1"
                       [(ngModel)]="adjustment.reason" placeholder="Reason for adjustment">
              </div>
            </div>
          </div>

          <!-- Grading History -->
          <div class="grade-card history-card" *ngIf="details.grading_history.length > 0">
            <div class="card-header">
              <h5><tui-icon icon="@tui.clock"></tui-icon> History</h5>
              <button class="btn btn-sm btn-secondary" (click)="showHistory = !showHistory">
                <tui-icon [icon]="showHistory ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
              </button>
            </div>
            <div class="card-body" *ngIf="showHistory">
              <div class="history-list">
                <div class="history-item" *ngFor="let h of details.grading_history">
                  <div class="history-action">{{ formatHistoryAction(h.action) }}</div>
                  <div class="history-detail" *ngIf="h.old_value || h.new_value">
                    <span class="old-val" *ngIf="h.old_value">{{ h.old_value }}</span>
                    <tui-icon icon="@tui.arrow-right" *ngIf="h.old_value && h.new_value"></tui-icon>
                    <span class="new-val" *ngIf="h.new_value">{{ h.new_value }}</span>
                  </div>
                  <div class="history-meta">{{ h.performed_by_name }} · {{ formatDatetime(h.performed_at) }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Submit Actions -->
          <div class="grading-submit">
            <button class="btn btn-lg btn-success w-100" (click)="submitGrade()" [disabled]="saving">
              <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span>
              <tui-icon icon="@tui.check" *ngIf="!saving"></tui-icon>
              {{ details.existing_grade ? 'Update Grade' : 'Submit Grade' }}
            </button>
            <div class="submit-secondary">
              <button class="btn btn-primary btn-sm" (click)="submitAndNext()" [disabled]="saving || !hasNextItem">
                Submit & Next <tui-icon icon="@tui.chevron-right"></tui-icon>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div class="loading-state" *ngIf="loading">
      <div class="spinner-border text-primary"></div>
      <p>Loading grading details...</p>
    </div>
  `,
  styles: [`
    .grading-interface { display: flex; flex-direction: column; height: 100vh; }

    /* Top Bar */
    .grading-topbar {
      display: flex; justify-content: space-between; align-items: center;
      padding: 0.75rem 1.25rem; background: #fff; border-bottom: 1px solid #dee2e6;
    }
    .topbar-info { display: flex; gap: 0.5rem; }
    .topbar-actions { display: flex; gap: 0.25rem; }

    /* Layout */
    .grading-layout { display: grid; grid-template-columns: 1fr 380px; gap: 1.25rem; padding: 1.25rem; flex: 1; overflow: auto; }

    /* Cards */
    .grade-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; margin-bottom: 1rem; }
    .card-header {
      display: flex; justify-content: space-between; align-items: center;
      padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; background: #f8f9fa;
    }
    .card-header h5 { margin: 0; font-size: 0.9375rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
    .card-body { padding: 1rem; }
    .points-badge { background: #0d6efd; color: #fff; padding: 0.25rem 0.625rem; border-radius: 1rem; font-size: 0.8125rem; font-weight: 600; }

    /* Question */
    .question-text { font-size: 0.9375rem; line-height: 1.6; margin-bottom: 0.75rem; }
    .question-image { max-width: 100%; border-radius: 0.375rem; margin: 0.75rem 0; }
    .context-content { background: #e8f4f8; border-radius: 0.375rem; padding: 0.75rem; margin-top: 0.5rem; font-size: 0.8125rem; }
    .context-item { margin-bottom: 0.5rem; }
    .context-item:last-child { margin-bottom: 0; }

    /* Answer */
    .answer-essay { line-height: 1.7; font-size: 0.9375rem; white-space: pre-wrap; }
    .answer-short pre { background: #f8f9fa; padding: 0.75rem; border-radius: 0.375rem; margin: 0; white-space: pre-wrap; font-size: 0.875rem; }
    .blank-item { display: flex; gap: 0.5rem; margin-bottom: 0.375rem; }
    .blank-label { font-weight: 600; font-size: 0.8125rem; color: #6c757d; min-width: 60px; }
    .blank-value { background: #fff3cd; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem; }
    .file-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0; border-bottom: 1px solid #e9ecef; }
    .file-item:last-child { border-bottom: none; }

    /* Comments */
    .comment-list { margin-bottom: 1rem; }
    .comment-item { padding: 0.625rem; border: 1px solid #e9ecef; border-radius: 0.375rem; margin-bottom: 0.5rem; border-left: 3px solid #6c757d; }
    .comment-general { border-left-color: #6c757d; }
    .comment-inline { border-left-color: #0d6efd; }
    .comment-correction { border-left-color: #dc3545; }
    .comment-praise { border-left-color: #28a745; }
    .comment-suggestion { border-left-color: #ffc107; }
    .comment-highlight { border-left-color: #17a2b8; }
    .comment-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.375rem; }
    .comment-type-badge { font-size: 0.75rem; display: flex; align-items: center; gap: 0.25rem; font-weight: 500; }
    .type-general { color: #6c757d; }
    .type-correction { color: #dc3545; }
    .type-praise { color: #28a745; }
    .type-suggestion { color: #ffc107; }
    .type-highlight { color: #17a2b8; }
    .type-inline { color: #0d6efd; }
    .comment-meta { font-size: 0.6875rem; color: #adb5bd; }
    .comment-body { font-size: 0.8125rem; line-height: 1.5; }

    /* Add Comment */
    .add-comment { border-top: 1px solid #e9ecef; padding-top: 0.75rem; }
    .comment-type-select { display: flex; gap: 0.25rem; margin-bottom: 0.5rem; flex-wrap: wrap; }
    .comment-type-select .btn { font-size: 0.75rem; padding: 0.2rem 0.5rem; }
    .comment-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem; position: relative; }
    .quick-feedback-dropdown {
      position: absolute; bottom: 100%; right: 0; width: 300px; background: #fff;
      border: 1px solid #dee2e6; border-radius: 0.375rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      max-height: 200px; overflow-y: auto; margin-bottom: 0.25rem; z-index: 10;
    }
    .qf-item { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #e9ecef; font-size: 0.8125rem; }
    .qf-item:hover { background: #f8f9fa; }
    .qf-item strong { display: block; font-size: 0.75rem; }
    .qf-item span { color: #6c757d; font-size: 0.75rem; }

    /* Score */
    .score-input-group { display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-bottom: 0.5rem; }
    .score-input { width: 100px; font-size: 1.5rem; font-weight: 700; text-align: center; }
    .score-divider { font-size: 1.5rem; color: #6c757d; }
    .score-max { font-size: 1.5rem; font-weight: 600; color: #6c757d; }
    .score-percentage { text-align: center; font-size: 0.875rem; color: #6c757d; margin-bottom: 0.75rem; }
    .score-quick-btns { display: flex; gap: 0.375rem; justify-content: center; }

    /* Grading Mode Toggle */
    .grading-mode-toggle { display: flex; gap: 0.375rem; margin-bottom: 0.75rem; }

    /* Rubric */
    .rubric-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
    .rubric-header h6 { margin: 0; font-weight: 600; }
    .criterion-item { margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e9ecef; }
    .criterion-item:last-child { border-bottom: none; }
    .criterion-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem; }
    .criterion-title { font-weight: 600; font-size: 0.875rem; }
    .criterion-weight { font-size: 0.6875rem; color: #6c757d; }
    .criterion-points { font-size: 0.8125rem; font-weight: 600; color: #0d6efd; }
    .criterion-desc { font-size: 0.75rem; color: #6c757d; margin: 0.125rem 0 0.5rem; }
    .level-options { display: flex; flex-direction: column; gap: 0.375rem; }
    .level-btn {
      display: flex; flex-wrap: wrap; gap: 0.25rem; align-items: center; padding: 0.5rem 0.75rem;
      border: 1px solid #dee2e6; border-radius: 0.375rem; background: #fff; cursor: pointer;
      text-align: left; transition: all 0.2s;
    }
    .level-btn:hover { border-color: #0d6efd; background: #f8f9fa; }
    .level-btn.selected { border-color: #0d6efd; background: #e8f0fe; }
    .level-label { font-weight: 500; font-size: 0.8125rem; }
    .level-points { font-size: 0.75rem; color: #0d6efd; font-weight: 600; margin-left: auto; }
    .level-desc { width: 100%; font-size: 0.6875rem; color: #6c757d; }
    .rubric-total {
      display: flex; justify-content: space-between; align-items: center;
      background: #f8f9fa; padding: 0.75rem; border-radius: 0.375rem; margin-top: 0.5rem;
    }
    .rubric-total-score { font-size: 1.125rem; font-weight: 700; color: #0d6efd; }

    /* Adjustment */
    .adjustment-form { display: flex; flex-direction: column; gap: 0.375rem; }

    /* History */
    .history-list { max-height: 200px; overflow-y: auto; }
    .history-item { padding: 0.5rem 0; border-bottom: 1px solid #e9ecef; font-size: 0.8125rem; }
    .history-item:last-child { border-bottom: none; }
    .history-action { font-weight: 500; }
    .history-detail { display: flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; margin: 0.125rem 0; }
    .old-val { color: #dc3545; text-decoration: line-through; }
    .new-val { color: #28a745; }
    .history-meta { font-size: 0.6875rem; color: #adb5bd; }

    /* Submit */
    .grading-submit { position: sticky; bottom: 0; background: #fff; padding: 0.75rem 0 0; }
    .submit-secondary { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
    .submit-secondary .btn { flex: 1; }

    /* Loading */
    .loading-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 50vh; }

    .badge-qtype { font-size: 0.6875rem; }

    /* Responsive */
    @media (max-width: 992px) {
      .grading-layout { grid-template-columns: 1fr; }
      .grading-right { order: -1; }
    }
  `]
})
export class GradingInterfaceComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroy$ = new Subject<void>();

  // The whole attempt is marked here now, one answer at a time.
  private attempt: any = null;
  private answers: any[] = [];
  private currentIndex = 0;

  // Data
  details: GradingDetails | null = null;
  quickFeedbackList: QuickFeedback[] = [];

  // State
  loading = true;
  saving = false;
  showContext = false;
  showAdjustment = false;
  showHistory = false;
  showQuickFeedback = false;
  gradingMode: 'simple' | 'rubric' = 'simple';
  hasPrevItem = false;
  hasNextItem = false;

  // Grade Form
  gradeForm = { score: 0 };
  rubricScores: Record<number, { level_id: number; points: number }> = {};
  rubricComments: Record<number, string> = {};
  adjustment = { type: '', value: 0, reason: '' };

  // Comment
  newComment = { type: 'general', text: '' };
  commentTypes = [
    { value: 'general', label: 'General', icon: '@tui.message-square' },
    { value: 'praise', label: 'Praise', icon: '@tui.thumbs-up' },
    { value: 'correction', label: 'Correction', icon: '@tui.x-circle' },
    { value: 'suggestion', label: 'Suggestion', icon: '@tui.lightbulb' },
    { value: 'highlight', label: 'Highlight', icon: '@tui.bookmark' },
  ];

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      const attemptId = (params['attemptId'] || params['id'] || '').toString();
      if (attemptId) this.loadAttempt(attemptId);
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  /**
   * The whole attempt loads here now, and its answers are marked one at a time
   * -- there is no single-answer queue item to fetch. It opens on the first
   * answer still awaiting a marker.
   */
  loadAttempt(attemptId: string): void {
    this.loading = true;
    this.assessments.attempt(attemptId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (raw) => {
        this.attempt = raw as any;
        this.answers = (this.attempt.answers || []);
        const firstUngraded = this.answers.findIndex((a) => a.needsMarker && !a.isGraded);
        this.showAnswer(firstUngraded >= 0 ? firstUngraded : 0);
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  /** Populates the single-answer view from the attempt's answer at `index`. */
  private showAnswer(index: number): void {
    const a = this.attempt;
    const ans = this.answers[index];
    if (!a || !ans) { this.details = null; return; }
    const q = ans.question || {};
    this.currentIndex = index;
    this.hasPrevItem = index > 0;
    this.hasNextItem = index < this.answers.length - 1;
    this.gradingMode = 'simple';

    this.details = {
      queue_id: a.id, attempt_id: a.id, answer_id: q.id,
      assessment_id: a.assessmentId, assessment_title: a.assessmentTitle ?? '',
      question_id: q.id, question_text: q.text ?? '',
      question_type: this.mapType(q.type),
      question_image: q.mediaUrl ?? null, section_title: '',
      max_points: ans.pointsPossible ?? q.points ?? 0,
      learner_id: a.userId ?? '', learner_name: a.userName ?? 'Learner', learner_email: '',
      attempt_number: a.attemptNumber ?? 1,
      submitted_at: a.submittedAt ?? '',
      answer_text: this.extractAnswerText(ans.answer),
      answer_data: ans.answer,
      file_urls: [],
      has_rubric: false, rubric_id: null, rubric: null,
      existing_grade: ans.isGraded ? ({ score: ans.pointsEarned ?? 0, feedback: ans.feedback ?? '', rubric_scores: [] } as unknown as ExistingGrade) : null,
      existing_comments: [], grading_history: [],
      question_context: ({
        correct_answer: this.deriveCorrectAnswer(q),
        explanation: q.explanation ?? '',
        class_avg_score: null,
        rubric_criteria_hints: null,
      } as unknown as QuestionContext),
    };
    this.gradeForm.score = ans.isGraded ? (ans.pointsEarned ?? 0) : 0;
    this.newComment.text = ans.feedback ?? '';
  }

  /** New-API question type -> the type strings this screen renders answers by. */
  private mapType(t: string): string {
    switch (t) {
      case 'long_answer': return 'essay';
      case 'fill_in_blanks': return 'fill_blank';
      case 'multiple_choice_single':
      case 'multiple_choice_multiple':
      case 'true_false':
      case 'numeric': return 'other';
      default: return t; // short_answer, matching, ordering
    }
  }

  private extractAnswerText(answer: any): string {
    if (!answer) return '';
    if (typeof answer.text === 'string') return answer.text;
    if (Array.isArray(answer.texts)) return answer.texts.join(' | ');
    if (answer.value !== undefined && answer.value !== null) return String(answer.value);
    return '';
  }

  private deriveCorrectAnswer(q: any): string {
    if (!q) return '';
    if (q.modelAnswer) return q.modelAnswer;
    if (q.numericAnswer !== undefined && q.numericAnswer !== null) return String(q.numericAnswer);
    const correct = (q.options || []).filter((o: any) => o.isCorrect).map((o: any) => o.text);
    return correct.join(', ');
  }

  // ==========================================
  // GRADING ACTIONS
  // ==========================================

  /**
   * Marks the current answer. The new API grades one answer by (attempt,
   * question) with a single points value and an optional feedback note, so
   * rubric scoring is unavailable; a bonus/penalty/override adjustment is
   * folded into that value here rather than sent as its own record.
   */
  submitGrade(): void {
    if (!this.details) return;
    this.saving = true;
    const score = this.adjustedScore();

    this.assessments.grade(this.details.attempt_id, this.details.answer_id, {
      pointsEarned: score,
      feedback: this.newComment.text?.trim() || undefined,
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.answers[this.currentIndex].isGraded = true;
        this.answers[this.currentIndex].pointsEarned = score;
        this.answers[this.currentIndex].feedback = this.newComment.text?.trim() || null;
        this.adjustment = { type: '', value: 0, reason: '' };
        this.advance();
      },
      error: () => { this.saving = false; }
    });
  }

  /** The score after any bonus/penalty/override, clamped to the question's max. */
  private adjustedScore(): number {
    let score = this.gradeForm.score;
    if (this.adjustment.type === 'bonus') score += this.adjustment.value;
    else if (this.adjustment.type === 'penalty') score -= this.adjustment.value;
    else if (this.adjustment.type === 'override') score = this.adjustment.value;
    return Math.max(0, Math.min(score, this.details?.max_points ?? score));
  }

  /** After marking, move to the next answer still awaiting one, or finish. */
  private advance(): void {
    const next = this.answers.findIndex((a, i) => i > this.currentIndex && a.needsMarker && !a.isGraded);
    const anyLeft = this.answers.findIndex((a) => a.needsMarker && !a.isGraded);
    if (next >= 0) this.showAnswer(next);
    else if (anyLeft >= 0) this.showAnswer(anyLeft);
    else this.goBack();
  }

  submitAndNext(): void {
    this.submitGrade();
  }

  addComment(): void {
    // The new API has no separate feedback-comment resource; the note is saved
    // with the grade, so this leaves the text in place for submitGrade to send.
  }

  insertQuickFeedback(qf: QuickFeedback): void {
    this.newComment.text += (this.newComment.text ? '\n' : '') + qf.content;
    this.showQuickFeedback = false;
  }

  // ==========================================
  // RUBRIC HELPERS
  // ==========================================

  selectLevel(criterionId: number, levelId: number, points: number): void {
    this.rubricScores[criterionId] = { level_id: levelId, points };
  }

  isLevelSelected(criterionId: number, levelId: number): boolean {
    return this.rubricScores[criterionId]?.level_id === levelId;
  }

  getRubricCriterionScore(criterionId: number): number {
    return this.rubricScores[criterionId]?.points || 0;
  }

  getRubricTotalScore(): number {
    return Object.values(this.rubricScores).reduce((sum, s) => sum + s.points, 0);
  }

  // ==========================================
  // SCORE HELPERS
  // ==========================================

  validateScore(): void {
    if (!this.details) return;
    if (this.gradeForm.score < 0) this.gradeForm.score = 0;
    if (this.gradeForm.score > this.details.max_points) this.gradeForm.score = this.details.max_points;
  }

  setScore(score: number): void {
    this.gradeForm.score = Math.round(score * 2) / 2;
  }

  getScorePercentage(): number {
    if (!this.details || this.details.max_points === 0) return 0;
    return (this.gradeForm.score / this.details.max_points) * 100;
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  goBack(): void { this.router.navigate(['/admin/grading']); }

  navigateItem(direction: 'prev' | 'next'): void {
    // Prev/next moves between this attempt's answers, held in memory -- there is
    // no cross-attempt queue cursor on the new API.
    const target = direction === 'next' ? this.currentIndex + 1 : this.currentIndex - 1;
    if (target >= 0 && target < this.answers.length) this.showAnswer(target);
  }

  // ==========================================
  // FORMAT HELPERS
  // ==========================================

  formatQuestionType(type: string): string {
    const labels: Record<string, string> = {
      essay: 'Essay', short_answer: 'Short Answer', file_upload: 'File Upload',
      fill_blank: 'Fill in Blank', matching: 'Matching', ordering: 'Ordering'
    };
    return labels[type] || type;
  }

  formatDatetime(dateStr: string): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });
  }

  formatCommentType(type: string): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
  }

  getCommentIcon(type: string): string {
    const icons: Record<string, string> = {
      general: '@tui.message-square', praise: '@tui.thumbs-up', correction: '@tui.x-circle',
      suggestion: '@tui.lightbulb', highlight: '@tui.bookmark', inline: '@tui.type'
    };
    return icons[type] || '@tui.message-square';
  }

  formatHistoryAction(action: string): string {
    return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  parseAnswerData(): any {
    if (!this.details) return [];
    try {
      return typeof this.details.answer_data === 'string' ?
        JSON.parse(this.details.answer_data) : (this.details.answer_data || []);
    } catch { return []; }
  }

  getFileName(url: string): string {
    return url.split('/').pop() || 'File';
  }

  previewFile(url: string): void {
    window.open(url, '_blank');
  }
}
