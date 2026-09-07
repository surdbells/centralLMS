// course-player/components/quiz/quiz.component.ts

import { Component, Input, Output, EventEmitter, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { interval, Subscription, Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  CoursePlayerService,
  QuizResult,
  QuizData,
  QuizQuestion,
  QuizQuestionOption
} from '../../services/course-player.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

@Component({
  selector: 'app-quiz',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe],
  template: `
    <div class="quiz-container">
      <!-- Loading State -->
      <div class="quiz-loading" *ngIf="loading">
        <div class="spinner-border text-primary" role="status"></div>
        <p>{{ 'quiz.loading' | translate }}</p>
      </div>

      <!-- Error State -->
      <div class="quiz-error" *ngIf="error">
        <i class="bi bi-exclamation-triangle"></i>
        <p>{{ error }}</p>
        <button class="btn btn-primary" (click)="loadQuizData()">{{ 'common.try_again' | translate }}</button>
      </div>

      <!-- Cannot Attempt State -->
      <div class="quiz-locked" *ngIf="!loading && !error && quizData && !quizData.can_attempt">
        <i class="bi bi-lock"></i>
        <h4>{{ 'quiz.max_attempts_reached' | translate }}</h4>
        <p>{{ 'quiz.max_attempts_message' | translate:{ count: quizData.assessment.max_attempts } }}</p>
        <div class="best-score">
          <span>{{ 'quiz.best_score' | translate }}</span>
          <strong>{{ quizData.best_score }}%</strong>
        </div>
        <button class="btn btn-secondary" (click)="onClose.emit()">{{ 'common.close' | translate }}</button>
      </div>

      <!-- Quiz Intro -->
      <div class="quiz-intro" *ngIf="!loading && !error && quizData && quizData.can_attempt && !quizStarted && !isCompleted">
        <div class="intro-header">
          <i class="bi bi-journal-check"></i>
          <h3>{{ quizData.assessment.title }}</h3>
          <span class="badge" [class.bg-primary]="quizData.assessment.assessment_type === 'quiz'"
                [class.bg-danger]="quizData.assessment.assessment_type === 'exam'"
                [class.bg-secondary]="quizData.assessment.assessment_type === 'assignment'">
            {{ quizData.assessment.assessment_type | titlecase }}
          </span>
        </div>

        <p class="intro-description" *ngIf="quizData.assessment.description">
          {{ quizData.assessment.description }}
        </p>

        <div class="intro-stats">
          <div class="stat-item">
            <i class="bi bi-question-circle"></i>
            <span class="stat-value">{{ quizData.assessment.total_questions }}</span>
            <span class="stat-label">{{ 'quiz.questions' | translate }}</span>
          </div>
          <div class="stat-item" *ngIf="quizData.assessment.time_limit_minutes > 0">
            <i class="bi bi-clock"></i>
            <span class="stat-value">{{ quizData.assessment.time_limit_minutes }}</span>
            <span class="stat-label">{{ 'quiz.minutes' | translate }}</span>
          </div>
          <div class="stat-item">
            <i class="bi bi-award"></i>
            <span class="stat-value">{{ quizData.assessment.pass_percentage }}%</span>
            <span class="stat-label">{{ 'quiz.to_pass' | translate }}</span>
          </div>
          <div class="stat-item">
            <i class="bi bi-arrow-repeat"></i>
            <span class="stat-value">{{ attemptsUsed }}/{{ quizData.assessment.max_attempts || '∞' }}</span>
            <span class="stat-label">{{ 'quiz.attempts' | translate }}</span>
          </div>
        </div>

        <div class="intro-notes" *ngIf="quizData.assessment.time_limit_minutes > 0">
          <i class="bi bi-info-circle"></i>
          <span>{{ 'quiz.timed_notice' | translate }}</span>
        </div>

        <div class="intro-actions">
          <button class="btn btn-secondary" (click)="onClose.emit()">{{ 'common.cancel' | translate }}</button>
          <button class="btn btn-primary btn-lg" (click)="startQuiz()">
            <i class="bi bi-play-fill me-2"></i>{{ 'quiz.start_assessment' | translate }}
          </button>
        </div>
      </div>

      <!-- Quiz In Progress -->
      <ng-container *ngIf="quizStarted && !isCompleted">
        <!-- Quiz Header -->
        <div class="quiz-header">
          <div class="quiz-info">
            <h4>{{ quizData?.assessment?.title }}</h4>
            <div class="quiz-meta">
              <span><i class="bi bi-question-circle me-1"></i>{{ 'quiz.n_questions' | translate:{ count: questions.length } }}</span>
              <span><i class="bi bi-award me-1"></i>{{ 'quiz.percent_to_pass' | translate:{ percent: quizData?.assessment?.pass_percentage } }}</span>
            </div>
          </div>

          <div class="quiz-timer" *ngIf="hasTimeLimit">
            <i class="bi bi-clock"></i>
            <span [class.text-danger]="timeRemaining < 60">{{ formatTime(timeRemaining) }}</span>
          </div>
        </div>

        <!-- Quiz Progress -->
        <div class="quiz-progress">
          <div class="progress">
            <div class="progress-bar" [style.width.%]="((currentQuestionIndex + 1) / questions.length) * 100"></div>
          </div>
          <span class="progress-text">{{ 'quiz.question_of' | translate:{ current: currentQuestionIndex + 1, total: questions.length } }}</span>
        </div>

        <!-- Quiz Content -->
        <div class="quiz-content" *ngIf="currentQuestion">
          <div class="question-card">
            <div class="question-header">
              <span class="question-number">{{ 'quiz.question_n' | translate:{ n: currentQuestionIndex + 1 } }}</span>
              <span class="question-points">{{ 'quiz.n_pts' | translate:{ count: currentQuestion.points } }}</span>
            </div>
            <div class="question-text">{{ currentQuestion.question_text }}</div>

            <!-- Single Choice -->
            <div class="options-list" *ngIf="currentQuestion.question_type === 'single_choice'">
              <label *ngFor="let option of currentQuestion.options; let i = index"
                     class="option-item"
                     [class.selected]="answers[currentQuestion.question_id] === option.option_id">
                <input type="radio"
                       [name]="'q_' + currentQuestion.question_id"
                       [value]="option.option_id"
                       [(ngModel)]="answers[currentQuestion.question_id]">
                <span class="option-marker">{{ getOptionLetter(i) }}</span>
                <span class="option-text">{{ option.option_text }}</span>
              </label>
            </div>

            <!-- Multiple Choice -->
            <div class="options-list" *ngIf="currentQuestion.question_type === 'multiple_choice'">
              <label *ngFor="let option of currentQuestion.options; let i = index"
                     class="option-item checkbox"
                     [class.selected]="isOptionSelected(currentQuestion.question_id, option.option_id)">
                <input type="checkbox"
                       [checked]="isOptionSelected(currentQuestion.question_id, option.option_id)"
                       (change)="toggleMultipleOption(currentQuestion.question_id, option.option_id)">
                <span class="option-marker">{{ getOptionLetter(i) }}</span>
                <span class="option-text">{{ option.option_text }}</span>
              </label>
            </div>

            <!-- True/False -->
            <div class="options-list true-false" *ngIf="currentQuestion.question_type === 'true_false'">
              <label class="option-item" [class.selected]="answers[currentQuestion.question_id] === 'true'">
                <input type="radio"
                       [name]="'q_' + currentQuestion.question_id"
                       value="true"
                       [(ngModel)]="answers[currentQuestion.question_id]">
                <span class="option-marker"><i class="bi bi-check-lg"></i></span>
                <span class="option-text">{{ 'quiz.true' | translate }}</span>
              </label>
              <label class="option-item" [class.selected]="answers[currentQuestion.question_id] === 'false'">
                <input type="radio"
                       [name]="'q_' + currentQuestion.question_id"
                       value="false"
                       [(ngModel)]="answers[currentQuestion.question_id]">
                <span class="option-marker"><i class="bi bi-x-lg"></i></span>
                <span class="option-text">{{ 'quiz.false' | translate }}</span>
              </label>
            </div>

            <!-- Short Answer -->
            <div class="text-answer" *ngIf="currentQuestion.question_type === 'short_answer'">
              <input type="text"
                     class="form-control"
                     placeholder="{{ 'quiz.short_answer_placeholder' | translate }}"
                     [(ngModel)]="answers[currentQuestion.question_id]">
            </div>

            <!-- Essay -->
            <div class="text-answer" *ngIf="currentQuestion.question_type === 'essay'">
              <textarea class="form-control"
                        rows="6"
                        placeholder="{{ 'quiz.essay_placeholder' | translate }}"
                        [(ngModel)]="answers[currentQuestion.question_id]"></textarea>
              <small class="char-count" *ngIf="answers[currentQuestion.question_id]">
                {{ 'quiz.n_characters' | translate:{ count: answers[currentQuestion.question_id].length } }}
              </small>
            </div>

            <!-- Fill in the Blank -->
            <div class="text-answer" *ngIf="currentQuestion.question_type === 'fill_blank'">
              <input type="text"
                     class="form-control"
                     placeholder="{{ 'quiz.fill_blank_placeholder' | translate }}"
                     [(ngModel)]="answers[currentQuestion.question_id]">
            </div>
          </div>
        </div>

        <!-- Quiz Navigation -->
        <div class="quiz-navigation">
          <button class="btn btn-secondary"
                  [disabled]="currentQuestionIndex === 0"
                  (click)="previousQuestion()">
            <i class="bi bi-chevron-left me-1"></i>{{ 'common.previous' | translate }}
          </button>

          <div class="question-dots">
            <button *ngFor="let q of questions; let i = index"
                    class="dot"
                    [class.active]="i === currentQuestionIndex"
                    [class.answered]="answers[q.question_id] !== undefined && answers[q.question_id] !== ''"
                    [class.flagged]="flaggedQuestions.has(q.question_id)"
                    (click)="goToQuestion(i)"
                    [title]="'quiz.question_n' | translate:{ n: i + 1 }">
            </button>
          </div>

          <button class="btn btn-primary"
                  *ngIf="currentQuestionIndex < questions.length - 1"
                  (click)="nextQuestion()">
            {{ 'common.next' | translate }}<i class="bi bi-chevron-right ms-1"></i>
          </button>

          <button class="btn btn-success"
                  *ngIf="currentQuestionIndex === questions.length - 1"
                  (click)="confirmSubmit()">
            <i class="bi bi-check-circle me-1"></i>{{ 'quiz.submit' | translate }}
          </button>
        </div>

        <!-- Flag for Review -->
        <div class="quiz-tools">
          <button class="btn btn-sm"
                  [class.btn-warning]="flaggedQuestions.has(currentQuestion?.question_id!)"
                  [class.btn-outline-warning]="!flaggedQuestions.has(currentQuestion?.question_id!)"
                  (click)="toggleFlag(currentQuestion?.question_id!)">
            <i class="bi bi-flag me-1"></i>
            {{ (flaggedQuestions.has(currentQuestion?.question_id!) ? 'quiz.unflag' : 'quiz.flag_for_review') | translate }}
          </button>
        </div>
      </ng-container>

      <!-- Submit Confirmation -->
      <div class="submit-confirm-overlay" *ngIf="showSubmitConfirm" (click)="showSubmitConfirm = false">
        <div class="submit-confirm-dialog" (click)="$event.stopPropagation()">
          <h4>{{ 'quiz.submit_confirm_title' | translate }}</h4>
          <p>{{ 'quiz.answered_count' | translate:{ answered: getAnsweredCount(), total: questions.length } }}</p>
          <div class="unanswered-warning" *ngIf="getUnansweredCount() > 0">
            <i class="bi bi-exclamation-triangle"></i>
            {{ 'quiz.unanswered_warning' | translate:{ count: getUnansweredCount() } }}
          </div>
          <div class="flagged-warning" *ngIf="flaggedQuestions.size > 0">
            <i class="bi bi-flag"></i>
            {{ 'quiz.flagged_warning' | translate:{ count: flaggedQuestions.size } }}
          </div>
          <div class="confirm-actions">
            <button class="btn btn-secondary" (click)="showSubmitConfirm = false">{{ 'quiz.review_answers' | translate }}</button>
            <button class="btn btn-success" (click)="submitQuiz()" [disabled]="submitting">
              <span class="spinner-border spinner-border-sm me-1" *ngIf="submitting"></span>
              {{ 'quiz.submit' | translate }}
            </button>
          </div>
        </div>
      </div>

      <!-- Quiz Results -->
      <div class="quiz-results" *ngIf="isCompleted && result">
        <div class="result-icon" [class.passed]="result.passed" [class.failed]="!result.passed">
          <i class="bi" [class.bi-trophy-fill]="result.passed" [class.bi-x-circle-fill]="!result.passed"></i>
        </div>

        <h3>{{ (result.passed ? 'quiz.congratulations' : 'quiz.keep_learning') | translate }}</h3>
        <p class="result-message">
          {{ (result.passed ? 'quiz.passed_message' : 'quiz.failed_message') | translate }}
        </p>

        <div class="result-score">
          <div class="score-circle" [class.passed]="result.passed">
            <span class="score-value">{{ result.percentage | number:'1.0-0' }}%</span>
            <span class="score-label">{{ 'quiz.score' | translate }}</span>
          </div>
        </div>

        <div class="result-details">
          <div class="detail-item">
            <span class="detail-label">{{ 'quiz.correct_answers' | translate }}</span>
            <span class="detail-value">{{ getCorrectCount() }}/{{ questions.length }}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">{{ 'quiz.points_earned' | translate }}</span>
            <span class="detail-value">{{ result.score | number:'1.0-2' }}/{{ result.max_score | number:'1.0-2' }}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">{{ 'quiz.time_taken' | translate }}</span>
            <span class="detail-value">{{ formatTime(timeTaken) }}</span>
          </div>
          <div class="detail-item" *ngIf="result.points_earned > 0">
            <span class="detail-label">{{ 'quiz.bonus_points' | translate }}</span>
            <span class="detail-value text-warning">+{{ result.points_earned }}</span>
          </div>
        </div>

        <!-- Review Answers -->
        <div class="review-section" *ngIf="showReview && result.show_correct_answers">
          <h5>{{ 'quiz.review_answers' | translate }}</h5>
          <div class="review-item" *ngFor="let r of result.results; let i = index">
            <div class="review-question">
              <span class="review-number">{{ i + 1 }}.</span>
              <span class="review-status">
                <i class="bi" [class.bi-check-circle-fill]="r.is_correct" [class.text-success]="r.is_correct"
                   [class.bi-x-circle-fill]="!r.is_correct" [class.text-danger]="!r.is_correct"></i>
              </span>
              <span class="review-text">{{ questions[i].question_text }}</span>
            </div>
            <div class="review-answer" *ngIf="!r.is_correct">
              <div class="your-answer">
                <strong>{{ 'quiz.your_answer' | translate }}</strong> {{ getAnswerText(questions[i], r.user_answer) }}
              </div>
              <div class="correct-answer">
                <strong>{{ 'quiz.correct' | translate }}</strong> {{ getAnswerText(questions[i], r.correct_answer) }}
              </div>
            </div>
            <div class="review-explanation" *ngIf="r.explanation">
              <i class="bi bi-lightbulb"></i> {{ r.explanation }}
            </div>
          </div>
        </div>

        <div class="result-actions">
          <button class="btn btn-secondary"
                  *ngIf="result.show_correct_answers"
                  (click)="showReview = !showReview">
            <i class="bi bi-eye me-1"></i>{{ (showReview ? 'quiz.hide_answers' : 'quiz.review_answers') | translate }}
          </button>

          <button class="btn btn-warning"
                  *ngIf="!result.passed && canRetry()"
                  (click)="retryQuiz()">
            <i class="bi bi-arrow-repeat me-1"></i>{{ 'quiz.try_again_attempts' | translate:{ count: getRemainingAttempts() } }}
          </button>

          <button class="btn btn-success" (click)="onComplete.emit(result)">
            <i class="bi bi-check me-1"></i>{{ 'common.continue' | translate }}
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .quiz-container {
      max-width: 800px;
      margin: 0 auto;
      padding: 1.5rem;
      min-height: 400px;
      color: #212529;
      background: #f8f9fa;
    }

    .quiz-loading, .quiz-error, .quiz-locked {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 3rem;
      text-align: center;
    }

    .quiz-loading i, .quiz-error i, .quiz-locked i {
      font-size: 3rem;
      margin-bottom: 1rem;
      color: #6c757d;
    }

    .quiz-loading p, .quiz-error p, .quiz-locked p {
      color: #6c757d;
      margin-bottom: 1rem;
    }

    .quiz-error i { color: #dc3545; }
    .quiz-locked i { color: #ffc107; }

    .best-score {
      background: #fff;
      border: 1px solid #e9ecef;
      padding: 1rem 2rem;
      border-radius: 0.5rem;
      margin-bottom: 1rem;
    }

    .best-score span { color: #6c757d; margin-right: 0.5rem; }
    .best-score strong { font-size: 1.5rem; color: #303234; }

    .quiz-intro {
      text-align: center;
    }

    .intro-header {
      margin-bottom: 1.5rem;
    }

    .intro-header i {
      font-size: 3rem;
      color: #303234;
      display: block;
      margin-bottom: 0.5rem;
    }

    .intro-header h3 {
      margin-bottom: 0.5rem;
      color: #212529;
    }

    .intro-header .badge { font-size: 0.75rem; }

    .intro-description {
      color: #6c757d;
      margin-bottom: 2rem;
      max-width: 500px;
      margin-left: auto;
      margin-right: auto;
    }

    .intro-stats {
      display: flex;
      justify-content: center;
      gap: 2rem;
      margin-bottom: 2rem;
      flex-wrap: wrap;
    }

    .stat-item {
      text-align: center;
      background: #fff;
      padding: 1rem 1.5rem;
      border-radius: 0.5rem;
      border: 1px solid #e9ecef;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .stat-item i {
      font-size: 1.5rem;
      color: #303234;
      display: block;
      margin-bottom: 0.25rem;
    }

    .stat-value {
      font-size: 1.5rem;
      font-weight: 600;
      display: block;
      color: #212529;
    }

    .stat-label {
      font-size: 0.75rem;
      color: #6c757d;
    }

    .intro-notes {
      background: #fff8e6;
      border: 1px solid #ffc107;
      padding: 0.75rem 1rem;
      border-radius: 0.5rem;
      margin-bottom: 2rem;
      font-size: 0.875rem;
      color: #856404;
    }

    .intro-notes i {
      color: #ffc107;
      margin-right: 0.5rem;
    }

    .intro-actions {
      display: flex;
      justify-content: center;
      gap: 1rem;
    }

    .quiz-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1.5rem;
    }

    .quiz-header h4 {
      margin-bottom: 0.5rem;
      color: #212529;
    }

    .quiz-meta {
      display: flex;
      gap: 1rem;
      font-size: 0.875rem;
      color: #6c757d;
    }

    .quiz-timer {
      background: #fff;
      border: 1px solid #e9ecef;
      padding: 0.5rem 1rem;
      border-radius: 0.5rem;
      font-size: 1.25rem;
      font-weight: 600;
      color: #212529;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .quiz-timer i {
      margin-right: 0.5rem;
      color: #303234;
    }

    .quiz-timer .text-danger {
      color: #dc3545 !important;
      animation: pulse 1s infinite;
    }

    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }

    .quiz-progress {
      margin-bottom: 1.5rem;
    }

    .quiz-progress .progress {
      height: 6px;
      background: #e9ecef;
      border-radius: 3px;
      overflow: hidden;
      margin-bottom: 0.5rem;
    }

    .quiz-progress .progress-bar {
      background: #303234;
      transition: width 0.3s ease;
    }

    .progress-text {
      font-size: 0.875rem;
      color: #6c757d;
    }

    .question-card {
      background: #fff;
      border: 1px solid #e9ecef;
      border-radius: 0.75rem;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .question-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: 1rem;
    }

    .question-number {
      font-size: 0.75rem;
      text-transform: uppercase;
      color: #303234;
      font-weight: 600;
    }

    .question-points {
      font-size: 0.75rem;
      color: #6c757d;
      background: #f8f9fa;
      padding: 0.125rem 0.5rem;
      border-radius: 10px;
    }

    .question-text {
      font-size: 1.125rem;
      line-height: 1.6;
      margin-bottom: 1.5rem;
      color: #212529;
    }

    .options-list {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .options-list.true-false {
      flex-direction: row;
      gap: 1rem;
    }

    .options-list.true-false .option-item {
      flex: 1;
      justify-content: center;
    }

    .option-item {
      display: flex;
      align-items: center;
      padding: 1rem;
      background: #f8f9fa;
      border: 2px solid #e9ecef;
      border-radius: 0.5rem;
      cursor: pointer;
      transition: all 0.2s ease;
      color: #212529;
    }

    .option-item input { display: none; }

    .option-marker {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: #e9ecef;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 1rem;
      font-weight: 600;
      font-size: 0.875rem;
      flex-shrink: 0;
      color: #6c757d;
    }

    .option-text { flex: 1; }

    .option-item:hover {
      background: #fff;
      border-color: #303234;
    }

    .option-item.selected {
      background: #fff8e6;
      border-color: #303234;
    }

    .option-item.selected .option-marker {
      background: #303234;
      color: #fff;
    }

    .option-item.checkbox .option-marker { border-radius: 4px; }

    .text-answer .form-control {
      background: #fff;
      border: 1px solid #ced4da;
      color: #212529;
    }

    .text-answer .form-control:focus {
      background: #fff;
      border-color: #303234;
      box-shadow: 0 0 0 3px rgba(201,162,39,0.2);
    }

    .text-answer .form-control::placeholder { color: #adb5bd; }

    .char-count {
      display: block;
      text-align: right;
      margin-top: 0.5rem;
      color: #6c757d;
      font-size: 0.75rem;
    }

    .quiz-navigation {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .question-dots {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
      justify-content: center;
    }

    .dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #dee2e6;
      border: none;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .dot:hover { background: #adb5bd; }
    .dot.active { background: #303234; transform: scale(1.2); }
    .dot.answered { background: #28a745; }
    .dot.flagged { background: #ffc107; box-shadow: 0 0 0 2px rgba(255,193,7,0.3); }

    .quiz-tools { text-align: center; }

    .submit-confirm-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0,0,0,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }

    .submit-confirm-dialog {
      background: #fff;
      padding: 2rem;
      border-radius: 0.75rem;
      text-align: center;
      max-width: 400px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    }

    .submit-confirm-dialog h4 {
      margin-bottom: 1rem;
      color: #212529;
    }

    .submit-confirm-dialog p {
      color: #6c757d;
      margin-bottom: 1rem;
    }

    .unanswered-warning, .flagged-warning {
      background: #fff8e6;
      border: 1px solid #ffc107;
      padding: 0.75rem;
      border-radius: 0.5rem;
      margin-bottom: 1rem;
      font-size: 0.875rem;
      color: #856404;
    }

    .unanswered-warning i, .flagged-warning i {
      color: #ffc107;
      margin-right: 0.5rem;
    }

    .confirm-actions {
      display: flex;
      gap: 1rem;
      justify-content: center;
    }

    .quiz-results {
      text-align: center;
      padding: 2rem 0;
    }

    .result-icon {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1.5rem;
    }

    .result-icon i { font-size: 2.5rem; }

    .result-icon.passed { background: rgba(40,167,69,0.15); }
    .result-icon.passed i { color: #28a745; }
    .result-icon.failed { background: rgba(220,53,69,0.15); }
    .result-icon.failed i { color: #dc3545; }

    .quiz-results h3 {
      margin-bottom: 0.5rem;
      color: #212529;
    }

    .result-message {
      color: #6c757d;
      margin-bottom: 2rem;
    }

    .result-score {
      margin-bottom: 2rem;
    }

    .score-circle {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      background: #f8f9fa;
      border: 4px solid #dc3545;
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    .score-circle.passed { border-color: #28a745; }

    .score-value {
      font-size: 2rem;
      font-weight: 700;
      color: #212529;
    }

    .score-label {
      font-size: 0.75rem;
      color: #6c757d;
    }

    .result-details {
      display: flex;
      justify-content: center;
      gap: 2rem;
      margin-bottom: 2rem;
      flex-wrap: wrap;
    }

    .detail-item {
      text-align: center;
      background: #fff;
      padding: 1rem;
      border-radius: 0.5rem;
      border: 1px solid #e9ecef;
      min-width: 100px;
    }

    .detail-label {
      display: block;
      font-size: 0.75rem;
      color: #6c757d;
    }

    .detail-value {
      font-size: 1.25rem;
      font-weight: 600;
      color: #212529;
    }

    .review-section {
      text-align: left;
      margin: 2rem 0;
      padding: 1rem;
      background: #fff;
      border: 1px solid #e9ecef;
      border-radius: 0.5rem;
      max-height: 400px;
      overflow-y: auto;
    }

    .review-section h5 {
      margin-bottom: 1rem;
      color: #212529;
    }

    .review-item {
      padding: 0.75rem 0;
      border-bottom: 1px solid #e9ecef;
    }

    .review-item:last-child { border-bottom: none; }

    .review-question {
      display: flex;
      gap: 0.5rem;
    }

    .review-number {
      color: #6c757d;
      flex-shrink: 0;
    }

    .review-status { flex-shrink: 0; }

    .review-text {
      flex: 1;
      color: #212529;
    }

    .review-answer {
      margin-top: 0.5rem;
      padding-left: 2rem;
      font-size: 0.875rem;
    }

    .your-answer {
      color: #dc3545;
      margin-bottom: 0.25rem;
    }

    .correct-answer { color: #28a745; }

    .review-explanation {
      margin-top: 0.5rem;
      padding: 0.5rem 0.75rem 0.5rem 2rem;
      font-size: 0.875rem;
      color: #6c757d;
      background: #fff8e6;
      border-radius: 0.25rem;
      border: 1px solid #ffc107;
    }

    .review-explanation i {
      color: #303234;
      margin-right: 0.5rem;
    }

    .result-actions {
      display: flex;
      justify-content: center;
      gap: 1rem;
      flex-wrap: wrap;
    }
  `]
})
export class QuizComponent implements OnInit, OnDestroy {
  @Input() assessmentId!: string;
  /**
   * Kept because the player binds it, and unused.
   *
   * An attempt belongs to a person and an assessment; which enrolment
   * they happened to be sitting in when they took it is not the server's
   * business, and passing it let a client claim an attempt against
   * somebody else's enrolment.
   */
  @Input() enrollmentId: string | null = null;

  @Output() onComplete = new EventEmitter<QuizResult>();
  @Output() onClose = new EventEmitter<void>();

  private destroy$ = new Subject<void>();

  quizData: QuizData | null = null;
  questions: QuizQuestion[] = [];

  loading = false;
  error: string | null = null;
  quizStarted = false;
  isCompleted = false;
  submitting = false;
  showSubmitConfirm = false;
  showReview = false;

  attemptId: string | null = null;
  currentQuestionIndex = 0;
  answers: Record<string, any> = {};
  flaggedQuestions = new Set<string>();

  timeRemaining = 0;
  timeTaken = 0;
  hasTimeLimit = false;
  startTime: Date | null = null;
  timerSubscription: Subscription | null = null;

  result: QuizResult | null = null;
  attemptsUsed = 0;

  constructor(private playerService: CoursePlayerService, private i18n: I18nService) {}

  ngOnInit(): void {
    this.loadQuizData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    this.stopTimer();
  }

  get currentQuestion(): QuizQuestion | null {
    return this.questions[this.currentQuestionIndex] || null;
  }

  loadQuizData(): void {
    this.loading = true;
    this.error = null;

    this.playerService.loadQuiz(this.assessmentId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.quizData = data;
          this.attemptsUsed = data.attempts_used;
          this.loading = false;
        },
        error: (err) => {
          this.error = this.i18n.t('quiz.error_load');
          this.loading = false;
        }
      });
  }

  startQuiz(): void {
    if (!this.quizData) return;

    this.loading = true;

    this.playerService.startQuizAttempt(this.assessmentId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (attemptData) => {
          this.attemptId = attemptData.attempt_id;
          this.questions = attemptData.questions;
          this.quizStarted = true;
          this.startTime = new Date();
          this.loading = false;

          if (this.quizData!.assessment.time_limit_minutes > 0) {
            this.hasTimeLimit = true;
            this.timeRemaining = this.quizData!.assessment.time_limit_minutes * 60;
            this.startTimer();
          }
        },
        error: (err) => {
          this.error = this.i18n.t('quiz.error_start');
          this.loading = false;
        }
      });
  }

  startTimer(): void {
    this.timerSubscription = interval(1000)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.timeRemaining--;
        this.timeTaken++;

        if (this.timeRemaining <= 0) {
          this.submitQuiz();
        }
      });
  }

  stopTimer(): void {
    if (this.timerSubscription) {
      this.timerSubscription.unsubscribe();
      this.timerSubscription = null;
    }
  }

  nextQuestion(): void {
    if (this.currentQuestionIndex < this.questions.length - 1) {
      this.currentQuestionIndex++;
    }
  }

  previousQuestion(): void {
    if (this.currentQuestionIndex > 0) {
      this.currentQuestionIndex--;
    }
  }

  goToQuestion(index: number): void {
    this.currentQuestionIndex = index;
  }

  isOptionSelected(questionId: string, optionId: string): boolean {
    const answer = this.answers[questionId];
    if (Array.isArray(answer)) {
      return answer.includes(optionId);
    }
    return false;
  }

  toggleMultipleOption(questionId: string, optionId: string): void {
    if (!this.answers[questionId]) {
      this.answers[questionId] = [];
    }

    const index = this.answers[questionId].indexOf(optionId);
    if (index > -1) {
      this.answers[questionId].splice(index, 1);
    } else {
      this.answers[questionId].push(optionId);
    }
  }

  toggleFlag(questionId: string): void {
    if (this.flaggedQuestions.has(questionId)) {
      this.flaggedQuestions.delete(questionId);
    } else {
      this.flaggedQuestions.add(questionId);
    }
  }

  confirmSubmit(): void {
    this.showSubmitConfirm = true;
  }

  submitQuiz(): void {
    this.stopTimer();
    this.showSubmitConfirm = false;
    this.submitting = true;

    if (!this.startTime) {
      this.timeTaken = 0;
    } else {
      this.timeTaken = Math.floor((new Date().getTime() - this.startTime.getTime()) / 1000);
    }

    this.playerService.submitQuiz(
      this.attemptId!,
      Object.entries(this.answers).map(([question_id, answer]) => ({ question_id, answer })),
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.result = result;
          this.isCompleted = true;
          this.submitting = false;
          this.attemptsUsed = result.attempts_used;
        },
        error: (err) => {
          this.submitting = false;
          this.error = this.i18n.t('quiz.error_submit');
        }
      });
  }

  canRetry(): boolean {
    if (!this.quizData) return false;
    const maxAttempts = this.quizData.assessment.max_attempts;
    return maxAttempts === 0 || this.attemptsUsed < maxAttempts;
  }

  getRemainingAttempts(): number | string {
    if (!this.quizData) return 0;
    const maxAttempts = this.quizData.assessment.max_attempts;
    if (maxAttempts === 0) return '∞';
    return maxAttempts - this.attemptsUsed;
  }

  retryQuiz(): void {
    this.answers = {};
    this.currentQuestionIndex = 0;
    this.isCompleted = false;
    this.quizStarted = false;
    this.result = null;
    this.showReview = false;
    this.flaggedQuestions.clear();
    this.attemptId = null;
    this.timeTaken = 0;
    this.timeRemaining = 0;
    this.hasTimeLimit = false;
    this.loadQuizData();
  }

  getOptionLetter(index: number): string {
    return String.fromCharCode(65 + index);
  }

  getAnsweredCount(): number {
    return Object.keys(this.answers).filter(k => {
      const val = this.answers[+k];
      return val !== undefined && val !== '' && (!Array.isArray(val) || val.length > 0);
    }).length;
  }

  getUnansweredCount(): number {
    return this.questions.length - this.getAnsweredCount();
  }

  getCorrectCount(): number {
    if (!this.result) return 0;
    return this.result.results.filter(r => r.is_correct).length;
  }

  getAnswerText(question: QuizQuestion | undefined, answer: any): string {
    if (!question) return String(answer);

    if (question.question_type === 'true_false') {
      return answer === 'true' || answer === true ? this.i18n.t('quiz.true') : this.i18n.t('quiz.false');
    }

    if (question.question_type === 'single_choice') {
      const option = question.options.find(o => o.option_id === answer);
      return option?.option_text || String(answer);
    }

    if (question.question_type === 'multiple_choice' && Array.isArray(answer)) {
      return answer.map(a => {
        const option = question.options.find(o => o.option_id === a);
        return option?.option_text || String(a);
      }).join(', ');
    }

    return String(answer);
  }

  formatTime(seconds: number): string {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  }
}
