import { Component, OnInit, ViewChild, ElementRef, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { AssessmentService, AssessmentHistory, PerformanceStats, PerformanceTrend } from '../../services/assessment.service';
import { StatusBadgeComponent } from '../../../shared/components/status-badge/status-badge.component';
import { LoadingSpinnerComponent } from '../../../shared/components/loading-spinner/loading-spinner.component';
import { PaginationComponent } from '../../../shared/components/pagination/pagination.component';
import { TimePipe } from '../../../shared/pipes/pipes';

interface FilterOptions {
  status: 'all' | 'passed' | 'failed';
  dateRange: 'all' | 'week' | 'month' | 'quarter' | 'year';
  assessmentId?: number;
}

interface ChartData {
  labels: string[];
  values: number[];
  type: 'line' | 'bar';
}

@Component({
  selector: 'app-performance-history',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    StatusBadgeComponent,
    LoadingSpinnerComponent,
    PaginationComponent,
    TimePipe
  ],
  template: `
    <div class="performance-history-container">
      <!-- Header -->
      <div class="page-header">
        <div>
          <h2 class="mb-1">Assessment History</h2>
          <p class="text-muted mb-0">Track your performance and progress over time</p>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="stats-grid mb-4">
        <div class="stat-card">
          <div class="stat-icon bg-primary-soft">
            <i class="bi bi-journal-text"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Total Assessments</div>
            <div class="stat-value">{{ stats?.total_assessments || 0 }}</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-success-soft">
            <i class="bi bi-check-circle"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Pass Rate</div>
            <div class="stat-value">{{ stats?.pass_rate || 0 }}%</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-info-soft">
            <i class="bi bi-graph-up"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Average Score</div>
            <div class="stat-value">{{ stats?.average_score || 0 }}%</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-warning-soft">
            <i class="bi bi-clock-history"></i>
          </div>
          <div class="stat-content">
            <div class="stat-label">Total Time</div>
            <div class="stat-value">{{ stats?.total_time || 0 | time }}</div>
          </div>
        </div>
      </div>

      <!-- Performance Trends Chart -->
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Performance Trends</h5>
            <div class="btn-group btn-group-sm" role="group">
              <button
                type="button"
                class="btn"
                [class.btn-primary]="chartType === 'score'"
                [class.btn-primary]="chartType !== 'score'"
                (click)="switchChart('score')">
                Score
              </button>
              <button
                type="button"
                class="btn"
                [class.btn-primary]="chartType === 'time'"
                [class.btn-primary]="chartType !== 'time'"
                (click)="switchChart('time')">
                Time
              </button>
            </div>
          </div>
        </div>
        <div class="card-body">
          <app-loading-spinner *ngIf="loadingChart" type="dots"></app-loading-spinner>

          <div *ngIf="!loadingChart && chartData" class="chart-container">
            <canvas #performanceChart></canvas>
          </div>

          <div *ngIf="!loadingChart && !chartData" class="text-center text-muted py-5">
            <i class="bi bi-graph-up" style="font-size: 48px;"></i>
            <p class="mt-3">No performance data available yet</p>
          </div>
        </div>
      </div>

      <!-- Filters and Search -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select class="form-select" [(ngModel)]="filters.status" (change)="applyFilters()">
                <option value="all">All Attempts</option>
                <option value="passed">Passed Only</option>
                <option value="failed">Failed Only</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Date Range</label>
              <select class="form-select" [(ngModel)]="filters.dateRange" (change)="applyFilters()">
                <option value="all">All Time</option>
                <option value="week">Last Week</option>
                <option value="month">Last Month</option>
                <option value="quarter">Last Quarter</option>
                <option value="year">Last Year</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Assessment</label>
              <select class="form-select" [(ngModel)]="filters.assessmentId" (change)="applyFilters()">
                <option [value]="undefined">All Assessments</option>
                <option *ngFor="let assessment of availableAssessments" [value]="assessment.id">
                  {{ assessment.title }}
                </option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">&nbsp;</label>
              <button class="btn btn-secondary w-100" (click)="resetFilters()">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- History Table -->
      <div class="card shadow-sm">
        <div class="card-header">
          <h5 class="mb-0">Assessment Attempts</h5>
        </div>
        <div class="card-body p-0">
          <app-loading-spinner *ngIf="loadingHistory" type="pulse"></app-loading-spinner>

          <div *ngIf="!loadingHistory" class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>Assessment</th>
                  <th>Attempt</th>
                  <th>Score</th>
                  <th>Status</th>
                  <th>Time Taken</th>
                  <th>Completed</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let attempt of paginatedHistory">
                  <td>
                    <div class="assessment-cell">
                      <div class="assessment-title">{{ attempt.assessment_title }}</div>
                      <div class="assessment-meta">
                        <span class="badge badge-sm bg-secondary">{{ attempt.assessment_type | titlecase }}</span>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-light text-dark">
                      #{{ attempt.attempt_number }}
                    </span>
                  </td>
                  <td>
                    <div class="score-cell">
                      <div class="score-value" [style.color]="getScoreColor(attempt.percentage)">
                        {{ attempt.percentage | number:'1.0-1' }}%
                      </div>
                      <div class="score-points">{{ attempt.score }} / {{ attempt.total_points }}</div>
                    </div>
                  </td>
                  <td>
                    <app-status-badge [status]="attempt.passed ? 'passed' : 'failed'"></app-status-badge>
                  </td>
                  <td>{{ attempt.time_taken | time }}</td>
                  <td>
                    <div class="date-cell">
                      {{ attempt.completed_at | date:'short' }}
                    </div>
                  </td>
                  <td>
                    <div class="btn-group btn-group-sm" role="group">
                      <button
                        class="btn btn-primary"
                        (click)="viewResults(attempt.attempt_id)"
                        title="View Results">
                        <i class="bi bi-eye"></i>
                      </button>
                      <button
                        *ngIf="attempt.certificate_id"
                        class="btn btn-success"
                        (click)="viewCertificate(attempt.certificate_id)"
                        title="View Certificate">
                        <i class="bi bi-award"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>

            <div *ngIf="filteredHistory.length === 0" class="text-center py-5 text-muted">
              <i class="bi bi-inbox" style="font-size: 48px;"></i>
              <p class="mt-3">No assessment attempts found</p>
            </div>
          </div>
        </div>
        <div class="card-footer" *ngIf="filteredHistory.length > 0">
          <app-pagination
            [currentPage]="currentPage"
            [totalItems]="filteredHistory.length"
            [itemsPerPage]="itemsPerPage"
            (pageChange)="onPageChange($event)">
          </app-pagination>
        </div>
      </div>

      <!-- Comparative Analytics -->
      <div class="row g-4 mt-4" *ngIf="comparativeStats">
        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-header">
              <h5 class="mb-0">Your Best Subjects</h5>
            </div>
            <div class="card-body">
              <div *ngFor="let subject of comparativeStats.best_subjects" class="subject-row mb-3">
                <div class="d-flex justify-content-between mb-1">
                  <span class="subject-name">{{ subject.name }}</span>
                  <span class="subject-score">{{ subject.average_score }}%</span>
                </div>
                <div class="progress" style="height: 8px;">
                  <div
                    class="progress-bar bg-success"
                    [style.width.%]="subject.average_score">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-header">
              <h5 class="mb-0">Areas for Improvement</h5>
            </div>
            <div class="card-body">
              <div *ngFor="let area of comparativeStats.weak_areas" class="subject-row mb-3">
                <div class="d-flex justify-content-between mb-1">
                  <span class="subject-name">{{ area.name }}</span>
                  <span class="subject-score">{{ area.average_score }}%</span>
                </div>
                <div class="progress" style="height: 8px;">
                  <div
                    class="progress-bar bg-warning"
                    [style.width.%]="area.average_score">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-12" *ngIf="comparativeStats.peer_comparison">
          <div class="card shadow-sm">
            <div class="card-header">
              <h5 class="mb-0">Peer Comparison</h5>
            </div>
            <div class="card-body">
              <div class="comparison-grid">
                <div class="comparison-item">
                  <div class="comparison-label">Your Average</div>
                  <div class="comparison-value your-score">
                    {{ comparativeStats.peer_comparison.your_average }}%
                  </div>
                </div>
                <div class="comparison-item">
                  <div class="comparison-label">Peer Average</div>
                  <div class="comparison-value peer-score">
                    {{ comparativeStats.peer_comparison.peer_average }}%
                  </div>
                </div>
                <div class="comparison-item">
                  <div class="comparison-label">Percentile Rank</div>
                  <div class="comparison-value rank">
                    {{ comparativeStats.peer_comparison.percentile }}th
                  </div>
                </div>
                <div class="comparison-item">
                  <div class="comparison-label">Performance</div>
                  <div class="comparison-badge">
                    <span
                      class="badge"
                      [class.bg-success]="comparativeStats.peer_comparison.your_average > comparativeStats.peer_comparison.peer_average"
                      [class.bg-warning]="comparativeStats.peer_comparison.your_average <= comparativeStats.peer_comparison.peer_average">
                      {{ comparativeStats.peer_comparison.your_average > comparativeStats.peer_comparison.peer_average ? 'Above Average' : 'Below Average' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .performance-history-container {
      padding: 20px;
      max-width: 1600px;
      margin: 0 auto;
    }

    .page-header {
      margin-bottom: 30px;
    }

    .page-header h2 {
      font-size: 28px;
      font-weight: 600;
      color: #212529;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
    }

    .stat-card {
      display: flex;
      gap: 15px;
      padding: 20px;
      background: white;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      transition: transform 0.2s;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    }

    .stat-icon {
      width: 56px;
      height: 56px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      flex-shrink: 0;
    }

    .stat-icon i {
      font-size: 28px;
    }

    .bg-primary-soft {
      background: rgba(102, 126, 234, 0.1);
      color: #667eea;
    }

    .bg-success-soft {
      background: rgba(40, 167, 69, 0.1);
      color: #28a745;
    }

    .bg-info-soft {
      background: rgba(13, 202, 240, 0.1);
      color: #0dcaf0;
    }

    .bg-warning-soft {
      background: rgba(255, 193, 7, 0.1);
      color: #ffc107;
    }

    .stat-content {
      flex: 1;
    }

    .stat-label {
      font-size: 13px;
      color: #6c757d;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 5px;
    }

    .stat-value {
      font-size: 32px;
      font-weight: 700;
      line-height: 1;
      color: #212529;
    }

    .chart-container {
      position: relative;
      height: 350px;
    }

    .assessment-cell {
      min-width: 200px;
    }

    .assessment-title {
      font-weight: 500;
      margin-bottom: 3px;
    }

    .assessment-meta {
      font-size: 12px;
    }

    .badge-sm {
      font-size: 11px;
      padding: 2px 6px;
    }

    .score-cell {
      text-align: center;
    }

    .score-value {
      font-size: 18px;
      font-weight: 700;
    }

    .score-points {
      font-size: 12px;
      color: #6c757d;
    }

    .date-cell {
      white-space: nowrap;
    }

    .subject-row {
      padding: 12px;
      background: #f8f9fa;
      border-radius: 6px;
    }

    .subject-name {
      font-weight: 500;
    }

    .subject-score {
      font-weight: 600;
      color: #667eea;
    }

    .comparison-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
    }

    .comparison-item {
      text-align: center;
      padding: 20px;
      background: #f8f9fa;
      border-radius: 8px;
    }

    .comparison-label {
      font-size: 13px;
      color: #6c757d;
      text-transform: uppercase;
      margin-bottom: 10px;
    }

    .comparison-value {
      font-size: 32px;
      font-weight: 700;
    }

    .your-score {
      color: #667eea;
    }

    .peer-score {
      color: #6c757d;
    }

    .rank {
      color: #28a745;
    }

    .comparison-badge {
      margin-top: 10px;
    }
  `]
})
export class PerformanceHistoryComponent implements OnInit, AfterViewInit {
  @ViewChild('performanceChart') chartCanvas?: ElementRef<HTMLCanvasElement>;

  loading = false;
  loadingHistory = false;
  loadingChart = false;

  stats: PerformanceStats | null = null;
  history: AssessmentHistory[] = [];
  filteredHistory: AssessmentHistory[] = [];
  paginatedHistory: AssessmentHistory[] = [];
  availableAssessments: { id: number; title: string }[] = [];
  comparativeStats: any = null;

  filters: FilterOptions = {
    status: 'all',
    dateRange: 'all'
  };

  // Pagination
  currentPage = 1;
  itemsPerPage = 10;

  // Chart
  chartType: 'score' | 'time' = 'score';
  chartData: ChartData | null = null;
  chartInstance: any = null;

  constructor(
    private assessmentService: AssessmentService,
    private router: Router
  ) {}

  ngOnInit() {
    this.loadPerformanceStats();
    this.loadAssessmentHistory();
    this.loadComparativeStats();
  }

  ngAfterViewInit() {
    this.loadPerformanceTrends();
  }

  loadPerformanceStats() {
    this.assessmentService.getPerformanceStats().subscribe({
      next: (response) => {
        if (response.success) {
          this.stats = response.data;
        }
      }
    });
  }

  loadAssessmentHistory() {
    this.loadingHistory = true;

    this.assessmentService.getAssessmentHistory().subscribe({
      next: (response) => {
        if (response.success) {
          this.history = response.data.attempts;
          this.availableAssessments = response.data.assessments;
          this.applyFilters();
        }
        this.loadingHistory = false;
      },
      error: () => {
        this.loadingHistory = false;
      }
    });
  }

  loadPerformanceTrends() {
    this.loadingChart = true;

    this.assessmentService.getPerformanceTrends(this.chartType).subscribe({
      next: (response) => {
        if (response.success) {
          this.chartData = {
            labels: response.data.labels,
            values: response.data.values,
            type: 'line'
          };
          this.renderChart();
        }
        this.loadingChart = false;
      },
      error: () => {
        this.loadingChart = false;
      }
    });
  }

  loadComparativeStats() {
    this.assessmentService.getComparativeStats().subscribe({
      next: (response) => {
        if (response.success) {
          this.comparativeStats = response.data;
        }
      }
    });
  }

  applyFilters() {
    let filtered = [...this.history];

    // Status filter
    if (this.filters.status !== 'all') {
      filtered = filtered.filter(attempt =>
        this.filters.status === 'passed' ? attempt.passed : !attempt.passed
      );
    }

    // Date range filter
    if (this.filters.dateRange !== 'all') {
      const now = new Date();
      const cutoff = new Date();

      switch (this.filters.dateRange) {
        case 'week':
          cutoff.setDate(now.getDate() - 7);
          break;
        case 'month':
          cutoff.setMonth(now.getMonth() - 1);
          break;
        case 'quarter':
          cutoff.setMonth(now.getMonth() - 3);
          break;
        case 'year':
          cutoff.setFullYear(now.getFullYear() - 1);
          break;
      }

      filtered = filtered.filter(attempt =>
        new Date(attempt.completed_at) >= cutoff
      );
    }

    // Assessment filter
    if (this.filters.assessmentId) {
      filtered = filtered.filter(attempt =>
        attempt.assessment_id === this.filters.assessmentId
      );
    }

    this.filteredHistory = filtered;
    this.currentPage = 1;
    this.updatePaginatedHistory();
  }

  resetFilters() {
    this.filters = {
      status: 'all',
      dateRange: 'all'
    };
    this.applyFilters();
  }

  updatePaginatedHistory() {
    const start = (this.currentPage - 1) * this.itemsPerPage;
    const end = start + this.itemsPerPage;
    this.paginatedHistory = this.filteredHistory.slice(start, end);
  }

  onPageChange(page: number) {
    this.currentPage = page;
    this.updatePaginatedHistory();
  }

  switchChart(type: 'score' | 'time') {
    if (this.chartType === type) return;
    this.chartType = type;
    this.loadPerformanceTrends();
  }

  renderChart() {
    // Simple canvas-based chart rendering
    // In production, you'd use a library like Chart.js
    if (!this.chartCanvas || !this.chartData) return;

    const canvas = this.chartCanvas.nativeElement;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // Set canvas size
    canvas.width = canvas.offsetWidth;
    canvas.height = 350;

    // Clear canvas
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Draw chart (simplified implementation)
    this.drawLineChart(ctx, canvas, this.chartData);
  }

  drawLineChart(ctx: CanvasRenderingContext2D, canvas: HTMLCanvasElement, data: ChartData) {
    const padding = 40;
    const width = canvas.width - padding * 2;
    const height = canvas.height - padding * 2;
    const maxValue = Math.max(...data.values);
    const step = width / (data.values.length - 1);

    // Draw axes
    ctx.strokeStyle = '#dee2e6';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, height + padding);
    ctx.lineTo(width + padding, height + padding);
    ctx.stroke();

    // Draw line
    ctx.strokeStyle = '#667eea';
    ctx.lineWidth = 3;
    ctx.beginPath();

    data.values.forEach((value, index) => {
      const x = padding + index * step;
      const y = height + padding - (value / maxValue) * height;

      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });

    ctx.stroke();

    // Draw points
    data.values.forEach((value, index) => {
      const x = padding + index * step;
      const y = height + padding - (value / maxValue) * height;

      ctx.fillStyle = '#667eea';
      ctx.beginPath();
      ctx.arc(x, y, 4, 0, Math.PI * 2);
      ctx.fill();
    });

    // Draw labels
    ctx.fillStyle = '#6c757d';
    ctx.font = '12px sans-serif';
    ctx.textAlign = 'center';

    data.labels.forEach((label, index) => {
      const x = padding + index * step;
      ctx.fillText(label, x, height + padding + 20);
    });
  }

  getScoreColor(percentage: number): string {
    if (percentage >= 90) return '#28a745';
    if (percentage >= 80) return '#20c997';
    if (percentage >= 70) return '#ffc107';
    if (percentage >= 60) return '#fd7e14';
    return '#dc3545';
  }

  viewResults(attemptId: number) {
    this.router.navigate(['/learner/assessments/results', attemptId]);
  }

  viewCertificate(certificateId: number) {
    this.router.navigate(['/learner/certificates', certificateId]);
  }
}
