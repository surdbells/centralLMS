import { Component, OnInit, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { AssessmentService, AttemptResult, AttemptDetail, QuestionAnswer } from '../../services/assessment.service';
import { CertificateService } from '../../services/certificate.service';
import { StatusBadgeComponent } from '../../shared/components/status-badge/status-badge.component';
import { ProgressBarComponent } from '../../shared/components/progress-bar/progress-bar.component';
import { QuestionRendererComponent } from '../../shared/components/question-renderer/question-renderer.component';
import { LoadingSpinnerComponent } from '../../shared/components/loading-spinner/loading-spinner.component';
import { TimePipe, ScorePipe } from '../../shared/pipes/pipes';

interface PerformanceBreakdown {
  bySection: { section_name: string; score: number; percentage: number }[];
  byType: { type: string; score: number; percentage: number }[];
  byDifficulty: { difficulty: string; score: number; percentage: number }[];
}

interface ProctoringViolation {
  violation_type: string;
  severity: 'low' | 'medium' | 'high';
  timestamp: string;
  description: string;
}

@Component({
  selector: 'app-attempt-results',
  standalone: true,
  imports: [
    CommonModule,
    StatusBadgeComponent,
    ProgressBarComponent,
    QuestionRendererComponent,
    LoadingSpinnerComponent,
    TimePipe,
    ScorePipe
  ],
  template: `
    <div class="attempt-results-container">
      <app-loading-spinner *ngIf="loading" type="pulse"></app-loading-spinner>

      <div *ngIf="!loading && attempt" class="results-content">
        <!-- Header with Back Button -->
        <div class="results-header">
          <button class="btn btn-secondary btn-sm" (click)="goBack()">
            <i class="bi bi-arrow-left"></i> Back to History
          </button>
          <div class="ms-auto d-flex gap-2">
            <button class="btn btn-primary btn-sm" (click)="downloadReport()">
              <i class="bi bi-file-pdf"></i> Download Report
            </button>
            <button
              *ngIf="attempt.passed && attempt.certificate_id"
              class="btn btn-success btn-sm"
              (click)="viewCertificate()">
              <i class="bi bi-award"></i> View Certificate
            </button>
          </div>
        </div>

        <!-- Assessment Info Banner -->
        <div class="assessment-banner">
          <h3 class="mb-1">{{ attempt.assessment_title }}</h3>
          <p class="text-muted mb-0">Attempt #{{ attempt.attempt_number }} • Completed {{ attempt.completed_at | date:'medium' }}</p>
        </div>

        <!-- Results Summary Card -->
        <div class="card shadow-sm mb-4">
          <div class="card-body">
            <div class="row g-4">
              <!-- Score Gauge -->
              <div class="col-md-4 text-center border-end">
                <div class="score-gauge">
                  <svg viewBox="0 0 200 200" class="gauge-svg">
                    <circle
                      cx="100"
                      cy="100"
                      r="85"
                      fill="none"
                      stroke="#e9ecef"
                      stroke-width="15"
                    />
                    <circle
                      cx="100"
                      cy="100"
                      r="85"
                      fill="none"
                      [attr.stroke]="getScoreColor(attempt.percentage)"
                      stroke-width="15"
                      stroke-linecap="round"
                      [attr.stroke-dasharray]="getGaugeDashArray()""
                      [attr.stroke-dashoffset]="getGaugeDashOffset(attempt.percentage)"
                      transform="rotate(-90 100 100)"
                    />
                  </svg>
                  <div class="gauge-content">
                    <div class="gauge-score">{{ attempt.percentage | number:'1.0-1' }}%</div>
                    <div class="gauge-label">{{ attempt.score }} / {{ attempt.total_points }}</div>
                  </div>
                </div>
                <app-status-badge
                  [status]="attempt.passed ? 'passed' : 'failed'"
                  [size]="'lg'"
                  class="mt-3">
                </app-status-badge>
              </div>

              <!-- Key Metrics -->
              <div class="col-md-8">
                <div class="row g-3">
                  <div class="col-6">
                    <div class="metric-card">
                      <div class="metric-icon">
                        <i class="bi bi-clock-history"></i>
                      </div>
                      <div class="metric-content">
                        <div class="metric-label">Time Taken</div>
                        <div class="metric-value">{{ attempt.time_taken | time }}</div>
                        <div class="metric-subtext text-muted" *ngIf="attempt.time_limit">
                          of {{ attempt.time_limit | time }} allowed
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-6">
                    <div class="metric-card">
                      <div class="metric-icon">
                        <i class="bi bi-check-circle"></i>
                      </div>
                      <div class="metric-content">
                        <div class="metric-label">Questions</div>
                        <div class="metric-value">{{ attempt.questions_correct }} / {{ attempt.total_questions }}</div>
                        <div class="metric-subtext text-muted">correct answers</div>
                      </div>
                    </div>
                  </div>

                  <div class="col-6">
                    <div class="metric-card">
                      <div class="metric-icon">
                        <i class="bi bi-trophy"></i>
                      </div>
                      <div class="metric-content">
                        <div class="metric-label">Passing Score</div>
                        <div class="metric-value">{{ attempt.passing_score }}%</div>
                        <div class="metric-subtext" [class.text-success]="attempt.passed" [class.text-danger]="!attempt.passed">
                          {{ attempt.passed ? 'Met requirement' : 'Not met' }}
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-6">
                    <div class="metric-card">
                      <div class="metric-icon">
                        <i class="bi bi-star"></i>
                      </div>
                      <div class="metric-content">
                        <div class="metric-label">Grade</div>
                        <div class="metric-value">{{ getLetterGrade(attempt.percentage) }}</div>
                        <div class="metric-subtext text-muted">{{ getGradeDescription(attempt.percentage) }}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Grading Status Banner -->
            <div *ngIf="attempt.pending_manual_grading" class="alert alert-warning mt-3 mb-0">
              <i class="bi bi-hourglass-split me-2"></i>
              <strong>Grading In Progress:</strong> Some questions require manual grading. Your final score may change once grading is complete.
            </div>
          </div>
        </div>

        <!-- Performance Breakdown Tabs -->
        <div class="card shadow-sm mb-4">
          <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button
                  class="nav-link active"
                  [class.active]="activeTab === 'overview'"
                  (click)="activeTab = 'overview'"
                  type="button">
                  Overview
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button
                  class="nav-link"
                  [class.active]="activeTab === 'questions'"
                  (click)="activeTab = 'questions'"
                  type="button">
                  Question Review
                </button>
              </li>
              <li class="nav-item" role="presentation" *ngIf="proctoringViolations.length > 0">
                <button
                  class="nav-link"
                  [class.active]="activeTab === 'proctoring'"
                  (click)="activeTab = 'proctoring'"
                  type="button">
                  Proctoring
                  <span class="badge bg-danger ms-2">{{ proctoringViolations.length }}</span>
                </button>
              </li>
            </ul>
          </div>
          <div class="card-body">
            <!-- Overview Tab -->
            <div *ngIf="activeTab === 'overview'" class="tab-content">
              <div class="row g-4">
                <!-- By Section -->
                <div class="col-md-4" *ngIf="performanceBreakdown.bySection.length > 0">
                  <h5 class="mb-3">Score by Section</h5>
                  <div class="breakdown-list">
                    <div *ngFor="let section of performanceBreakdown.bySection" class="breakdown-item">
                      <div class="d-flex justify-content-between mb-1">
                        <span class="breakdown-label">{{ section.section_name }}</span>
                        <span class="breakdown-score">{{ section.percentage | number:'1.0-1' }}%</span>
                      </div>
                      <app-progress-bar
                        [value]="section.percentage"
                        [max]="100"
                        [type]="getProgressType(section.percentage)"
                        [size]="'sm'">
                      </app-progress-bar>
                    </div>
                  </div>
                </div>

                <!-- By Question Type -->
                <div class="col-md-4" *ngIf="performanceBreakdown.byType.length > 0">
                  <h5 class="mb-3">Score by Question Type</h5>
                  <div class="breakdown-list">
                    <div *ngFor="let type of performanceBreakdown.byType" class="breakdown-item">
                      <div class="d-flex justify-content-between mb-1">
                        <span class="breakdown-label">{{ formatQuestionType(type.type) }}</span>
                        <span class="breakdown-score">{{ type.percentage | number:'1.0-1' }}%</span>
                      </div>
                      <app-progress-bar
                        [value]="type.percentage"
                        [max]="100"
                        [type]="getProgressType(type.percentage)"
                        [size]="'sm'">
                      </app-progress-bar>
                    </div>
                  </div>
                </div>

                <!-- By Difficulty -->
                <div class="col-md-4" *ngIf="performanceBreakdown.byDifficulty.length > 0">
                  <h5 class="mb-3">Score by Difficulty</h5>
                  <div class="breakdown-list">
                    <div *ngFor="let difficulty of performanceBreakdown.byDifficulty" class="breakdown-item">
                      <div class="d-flex justify-content-between mb-1">
                        <span class="breakdown-label">{{ difficulty.difficulty | titlecase }}</span>
                        <span class="breakdown-score">{{ difficulty.percentage | number:'1.0-1' }}%</span>
                      </div>
                      <app-progress-bar
                        [value]="difficulty.percentage"
                        [max]="100"
                        [type]="getProgressType(difficulty.percentage)"
                        [size]="'sm'">
                      </app-progress-bar>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Questions Review Tab -->
            <div *ngIf="activeTab === 'questions'" class="tab-content">
              <div *ngIf="!attempt.review_allowed" class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                Question review is not available for this assessment.
              </div>

              <div *ngIf="attempt.review_allowed" class="questions-review">
                <div *ngFor="let question of questions; let i = index" class="question-review-card mb-4">
                  <div class="question-header">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <div class="d-flex align-items-center gap-2">
                        <span class="question-number">Question {{ i + 1 }}</span>
                        <span class="badge" [class.bg-success]="question.is_correct" [class.bg-danger]="!question.is_correct">
                          {{ question.points_earned }} / {{ question.points }} pts
                        </span>
                        <span class="badge bg-secondary">{{ formatQuestionType(question.question_type) }}</span>
                        <span class="badge" [class]="'bg-' + getDifficultyColor(question.difficulty)">
                          {{ question.difficulty | titlecase }}
                        </span>
                      </div>
                      <div class="question-status">
                        <i class="bi" [class.bi-check-circle-fill]="question.is_correct"
                           [class.bi-x-circle-fill]="!question.is_correct"
                           [class.text-success]="question.is_correct"
                           [class.text-danger]="!question.is_correct"></i>
                      </div>
                    </div>
                  </div>

                  <app-question-renderer
                    [question]="question"
                    [mode]="'review'"
                    [showCorrectAnswer]="attempt.show_correct_answers"
                    [userAnswer]="question.user_answer"
                    [disabled]="true">
                  </app-question-renderer>

                  <!-- Feedback -->
                  <div *ngIf="question.feedback" class="question-feedback alert alert-info mt-3">
                    <strong><i class="bi bi-lightbulb me-2"></i>Feedback:</strong>
                    <div [innerHTML]="question.feedback"></div>
                  </div>

                  <!-- Grader Comments -->
                  <div *ngIf="question.grader_comments" class="question-feedback alert alert-warning mt-3">
                    <strong><i class="bi bi-person-check me-2"></i>Grader Comments:</strong>
                    <div>{{ question.grader_comments }}</div>
                  </div>

                  <!-- Rubric Scores -->
                  <div *ngIf="question.rubric_scores && question.rubric_scores.length > 0" class="rubric-scores mt-3">
                    <strong class="d-block mb-2">Rubric Scores:</strong>
                    <div class="table-responsive">
                      <table class="table table-sm table-bordered">
                        <thead>
                          <tr>
                            <th>Criteria</th>
                            <th>Score</th>
                            <th>Comment</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr *ngFor="let rubric of question.rubric_scores">
                            <td>{{ rubric.criterion_name }}</td>
                            <td>{{ rubric.score }} / {{ rubric.max_score }}</td>
                            <td>{{ rubric.comment }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Proctoring Tab -->
            <div *ngIf="activeTab === 'proctoring'" class="tab-content">
              <div class="proctoring-summary mb-4">
                <div class="row g-3">
                  <div class="col-md-3">
                    <div class="stat-card">
                      <div class="stat-label">Proctoring Level</div>
                      <div class="stat-value">{{ formatProctoringLevel(attempt.proctoring_level) }}</div>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="stat-card">
                      <div class="stat-label">Total Violations</div>
                      <div class="stat-value text-danger">{{ proctoringViolations.length }}</div>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="stat-card">
                      <div class="stat-label">Trust Score</div>
                      <div class="stat-value" [class]="getTrustScoreClass(attempt.trust_score)">
                        {{ attempt.trust_score | number:'1.0-0' }}%
                      </div>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="stat-card">
                      <div class="stat-label">Review Status</div>
                      <div class="stat-value">
                        <app-status-badge [status]="attempt.proctoring_status"></app-status-badge>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <h5 class="mb-3">Violation Timeline</h5>
              <div class="violations-list">
                <div *ngFor="let violation of proctoringViolations"
                     class="violation-item"
                     [class]="'severity-' + violation.severity">
                  <div class="violation-icon">
                    <i class="bi" [class]="getViolationIcon(violation.violation_type)"></i>
                  </div>
                  <div class="violation-content">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                      <strong>{{ formatViolationType(violation.violation_type) }}</strong>
                      <span class="badge" [class]="'bg-' + getSeverityColor(violation.severity)">
                        {{ violation.severity | titlecase }}
                      </span>
                    </div>
                    <p class="mb-1">{{ violation.description }}</p>
                    <small class="text-muted">
                      <i class="bi bi-clock me-1"></i>
                      {{ violation.timestamp | date:'medium' }}
                    </small>
                  </div>
                </div>
              </div>

              <div *ngIf="proctoringViolations.length === 0" class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                No violations detected during this attempt.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div *ngIf="!loading && error" class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-2"></i>
        {{ error }}
      </div>
    </div>
  `,
  styles: [`
    .attempt-results-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }

    .results-header {
      display: flex;
      align-items: center;
      margin-bottom: 20px;
    }

    .assessment-banner {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 30px;
      border-radius: 12px;
      margin-bottom: 20px;
    }

    .assessment-banner h3 {
      font-size: 28px;
      font-weight: 600;
      margin: 0;
    }

    .score-gauge {
      position: relative;
      width: 200px;
      height: 200px;
      margin: 0 auto;
    }

    .gauge-svg {
      width: 100%;
      height: 100%;
    }

    .gauge-content {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
    }

    .gauge-score {
      font-size: 48px;
      font-weight: 700;
      line-height: 1;
    }

    .gauge-label {
      font-size: 14px;
      color: #6c757d;
      margin-top: 5px;
    }

    .metric-card {
      display: flex;
      gap: 15px;
      padding: 15px;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      height: 100%;
    }

    .metric-icon {
      width: 48px;
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f8f9fa;
      border-radius: 8px;
      flex-shrink: 0;
    }

    .metric-icon i {
      font-size: 24px;
      color: #667eea;
    }

    .metric-content {
      flex: 1;
    }

    .metric-label {
      font-size: 12px;
      color: #6c757d;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 5px;
    }

    .metric-value {
      font-size: 24px;
      font-weight: 700;
      line-height: 1;
    }

    .metric-subtext {
      font-size: 13px;
      margin-top: 3px;
    }

    .breakdown-list {
      display: flex;
      flex-direction: column;
      gap: 15px;
    }

    .breakdown-item {
      padding: 12px;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      background: #f8f9fa;
    }

    .breakdown-label {
      font-weight: 500;
      font-size: 14px;
    }

    .breakdown-score {
      font-weight: 600;
      color: #667eea;
    }

    .question-review-card {
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 20px;
      background: white;
    }

    .question-number {
      font-weight: 600;
      font-size: 16px;
    }

    .question-feedback {
      border-left: 4px solid #0dcaf0;
    }

    .stat-card {
      padding: 20px;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      text-align: center;
      background: white;
    }

    .stat-label {
      font-size: 12px;
      color: #6c757d;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .stat-value {
      font-size: 28px;
      font-weight: 700;
    }

    .violations-list {
      display: flex;
      flex-direction: column;
      gap: 15px;
    }

    .violation-item {
      display: flex;
      gap: 15px;
      padding: 15px;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }

    .violation-item.severity-low {
      background: #fff3cd;
      border-color: #ffc107;
    }

    .violation-item.severity-medium {
      background: #ffe5d0;
      border-color: #fd7e14;
    }

    .violation-item.severity-high {
      background: #f8d7da;
      border-color: #dc3545;
    }

    .violation-icon {
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: white;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .violation-icon i {
      font-size: 20px;
    }

    .violation-content {
      flex: 1;
    }

    .nav-tabs .nav-link {
      color: #495057;
      border: none;
      border-bottom: 2px solid transparent;
    }

    .nav-tabs .nav-link.active {
      color: #667eea;
      border-bottom-color: #667eea;
      background: none;
    }
  `]
})
export class AttemptResultsComponent implements OnInit {
  @Input() attemptId?: number;

  loading = false;
  error = '';
  attempt: AttemptDetail | null = null;
  questions: QuestionAnswer[] = [];
  performanceBreakdown: PerformanceBreakdown = {
    bySection: [],
    byType: [],
    byDifficulty: []
  };
  proctoringViolations: ProctoringViolation[] = [];
  activeTab: 'overview' | 'questions' | 'proctoring' = 'overview';

  constructor(
    private assessmentService: AssessmentService,
    private certificateService: CertificateService,
    private route: ActivatedRoute,
    private router: Router
  ) {}

  ngOnInit() {
    const attemptId = this.attemptId || +this.route.snapshot.params['attemptId'];
    if (attemptId) {
      this.loadAttemptResults(attemptId);
    }
  }

  loadAttemptResults(attemptId: number) {
    this.loading = true;
    this.error = '';

    this.assessmentService.getAttemptDetails(attemptId).subscribe({
      next: (response) => {
        if (response.success) {
          this.attempt = response.data.attempt;
          this.questions = response.data.questions || [];
          this.performanceBreakdown = response.data.performance_breakdown || this.performanceBreakdown;
          this.proctoringViolations = response.data.violations || [];
        } else {
          this.error = response.message;
        }
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Failed to load attempt results';
        this.loading = false;
      }
    });
  }

  getScoreColor(percentage: number): string {
    if (percentage >= 90) return '#28a745';
    if (percentage >= 80) return '#20c997';
    if (percentage >= 70) return '#ffc107';
    if (percentage >= 60) return '#fd7e14';
    return '#dc3545';
  }

  getGaugeDashArray(): string {
    const circumference = 2 * Math.PI * 85;
    return `${circumference} ${circumference}`;
  }

  getGaugeDashOffset(percentage: number): number {
    const circumference = 2 * Math.PI * 85;
    return circumference - (percentage / 100) * circumference;
  }

  getLetterGrade(percentage: number): string {
    if (percentage >= 90) return 'A';
    if (percentage >= 80) return 'B';
    if (percentage >= 70) return 'C';
    if (percentage >= 60) return 'D';
    return 'F';
  }

  getGradeDescription(percentage: number): string {
    if (percentage >= 90) return 'Excellent';
    if (percentage >= 80) return 'Good';
    if (percentage >= 70) return 'Satisfactory';
    if (percentage >= 60) return 'Needs Improvement';
    return 'Unsatisfactory';
  }

  getProgressType(percentage: number): 'primary' | 'success' | 'warning' | 'danger' {
    if (percentage >= 80) return 'success';
    if (percentage >= 70) return 'primary';
    if (percentage >= 60) return 'warning';
    return 'danger';
  }

  formatQuestionType(type: string): string {
    const types: { [key: string]: string } = {
      'multiple_choice': 'Multiple Choice',
      'true_false': 'True/False',
      'short_answer': 'Short Answer',
      'essay': 'Essay',
      'matching': 'Matching',
      'ordering': 'Ordering',
      'fill_blank': 'Fill in the Blank',
      'multi_select': 'Multiple Select',
      'file_upload': 'File Upload',
      'coding': 'Coding',
      'hotspot': 'Image Hotspot'
    };
    return types[type] || type;
  }

  getDifficultyColor(difficulty: string): string {
    const colors: { [key: string]: string } = {
      'easy': 'success',
      'medium': 'warning',
      'hard': 'danger'
    };
    return colors[difficulty.toLowerCase()] || 'secondary';
  }

  formatProctoringLevel(level: string): string {
    const levels: { [key: string]: string } = {
      'none': 'No Proctoring',
      'basic': 'Basic',
      'standard': 'Standard',
      'advanced': 'Advanced'
    };
    return levels[level] || level;
  }

  getTrustScoreClass(score: number): string {
    if (score >= 80) return 'text-success';
    if (score >= 60) return 'text-warning';
    return 'text-danger';
  }

  getViolationIcon(type: string): string {
    const icons: { [key: string]: string } = {
      'face_not_detected': 'bi-person-x',
      'multiple_faces': 'bi-people',
      'looking_away': 'bi-eye-slash',
      'tab_switch': 'bi-window',
      'copy_paste': 'bi-clipboard',
      'unauthorized_device': 'bi-phone',
      'suspicious_behavior': 'bi-exclamation-triangle'
    };
    return icons[type] || 'bi-exclamation-circle';
  }

  formatViolationType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
  }

  getSeverityColor(severity: string): string {
    const colors: { [key: string]: string } = {
      'low': 'warning',
      'medium': 'orange',
      'high': 'danger'
    };
    return colors[severity] || 'secondary';
  }

  downloadReport() {
    if (!this.attempt) return;

    this.assessmentService.downloadAttemptReport(this.attempt.attempt_id).subscribe({
      next: (blob) => {
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `attempt-${this.attempt!.attempt_id}-report.pdf`;
        link.click();
        window.URL.revokeObjectURL(url);
      },
      error: () => {
        alert('Failed to download report');
      }
    });
  }

  viewCertificate() {
    if (!this.attempt?.certificate_id) return;
    this.router.navigate(['/learner/certificates', this.attempt.certificate_id]);
  }

  goBack() {
    this.router.navigate(['/learner/assessments/history']);
  }
}
