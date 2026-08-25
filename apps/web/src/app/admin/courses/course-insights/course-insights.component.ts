import {Component, OnInit, OnDestroy, Input, inject} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil } from 'rxjs';
import {
  CourseInsightsData,
  CourseInsightsResponse,
  CourseInsightsRequest,
  InsightsFilter,
  ModuleAnalytics,
  LearnerPerformance,
  AtRiskLearner,
  AssessmentDetail,
  TrendDataPoint
} from './course-insights.interfaces';
import {POLYMORPHEUS_CONTEXT} from '@taiga-ui/polymorpheus';
import {TuiDialogContext, TuiIcon} from '@taiga-ui/core';
import {GlobalComponent} from '../../../global-component';
interface CourseInsightsDialogData {
  token: string;
  userId: number;
  tenantId: number;
  courseId: number;
  courseTitle: string;
}
@Component({
  selector: 'app-course-insights',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  templateUrl: './course-insights.component.html',
  styleUrls: ['./course-insights.component.css']
})
export class CourseInsightsComponent implements OnInit, OnDestroy {

  // Inject dialog context
  public readonly context = inject<TuiDialogContext<void, CourseInsightsDialogData>>(
    POLYMORPHEUS_CONTEXT,
    { optional: true }
  );

  // Get data from dialog OR from @Input
  token: string = '';
  userId: number = 0;
  tenantId: number = 0;
  courseId: number = 0;
  courseTitle: string = "";

  private destroy$ = new Subject<void>();
  // State
  isLoading: boolean = true;
  error: string | null = null;
  insights: CourseInsightsData | null = null;

  // Filter
  filter: InsightsFilter = {
    date_range: 'last_30_days',
    learner_status: 'all'
  };

  // UI State
  activeTab: 'overview' | 'learners' | 'content' | 'assessments' | 'leaderboard' = 'overview';
  selectedModule: ModuleAnalytics | null = null;
  showExportMenu: boolean = false;

  // Chart data (for external chart library integration)
  enrollmentChartData: any[] = [];
  completionChartData: any[] = [];
  scoreDistributionData: any[] = [];

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    // If opened as dialog, get data from context
    if (this.context?.data) {
      this.token = this.context.data.token;
      this.userId = this.context.data.userId;
      this.tenantId = this.context.data.tenantId;
      this.courseId = this.context.data.courseId;
      this.courseTitle = this.context.data.courseTitle;
    }
    this.loadInsights();
  }
  // Close dialog method
  closeDialog(): void {
    this.context?.completeWith();
  }
  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadInsights(): void {
    this.isLoading = true;
    this.error = null;

    const request: CourseInsightsRequest = {
      token: this.token,
      user_id: this.userId,
      tenant_id: this.tenantId,
      course_id: this.courseId,
      period: this.getSelectedPeriod()
    };

    // Add date range based on filter
    const dateRange = this.getDateRange();
    if (dateRange) {
      request.date_from = dateRange.from;
      request.date_to = dateRange.to;
    }

    this.http.post<CourseInsightsResponse>(GlobalComponent.courseInsights, request)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          if (response.status === 'success') {
            this.insights = response.data;
            this.prepareChartData();
          } else {
            this.error = response.message;
          }
          this.isLoading = false;
        },
        error: (err) => {
          this.error = 'Failed to load course insights';
          this.isLoading = false;
          console.error('Insights error:', err);
        }
      });
  }

  private getSelectedPeriod(): 'daily' | 'weekly' | 'monthly' {
    switch (this.filter.date_range) {
      case 'last_7_days': return 'daily';
      case 'last_30_days': return 'daily';
      case 'last_90_days': return 'weekly';
      default: return 'monthly';
    }
  }

  private getDateRange(): { from: string; to: string } | null {
    const today = new Date();
    const to = today.toISOString().split('T')[0];
    let from: Date;

    switch (this.filter.date_range) {
      case 'last_7_days':
        from = new Date(today.setDate(today.getDate() - 7));
        break;
      case 'last_30_days':
        from = new Date(today.setDate(today.getDate() - 30));
        break;
      case 'last_90_days':
        from = new Date(today.setDate(today.getDate() - 90));
        break;
      case 'this_year':
        from = new Date(today.getFullYear(), 0, 1);
        break;
      case 'all_time':
        return null;
      default:
        return null;
    }

    return { from: from.toISOString().split('T')[0], to };
  }

  private prepareChartData(): void {
    if (!this.insights) return;

    // Enrollment trend data
    this.enrollmentChartData = this.insights.trends.data_points.map(point => ({
      label: point.label,
      enrollments: point.enrollments,
      completions: point.completions
    }));

    // Score distribution data
    this.scoreDistributionData = this.insights.assessments.score_distribution.map(dist => ({
      range: dist.range,
      count: dist.count,
      percentage: dist.percentage
    }));
  }

  // Tab navigation
  setActiveTab(tab: 'overview' | 'learners' | 'content' | 'assessments' | 'leaderboard'): void {
    this.activeTab = tab;
  }

  // Filter changes
  onFilterChange(): void {
    this.loadInsights();
  }

  // Module selection
  selectModule(module: ModuleAnalytics): void {
    this.selectedModule = this.selectedModule?.module_id === module.module_id ? null : module;
  }

  // Export functions
  toggleExportMenu(): void {
    this.showExportMenu = !this.showExportMenu;
  }

  exportToPDF(): void {
    // Implement PDF export
    console.log('Exporting to PDF...');
    this.showExportMenu = false;
  }

  exportToExcel(): void {
    // Implement Excel export
    console.log('Exporting to Excel...');
    this.showExportMenu = false;
  }

  exportToCSV(): void {
    // Implement CSV export
    console.log('Exporting to CSV...');
    this.showExportMenu = false;
  }

  // Utility functions
  formatDuration(minutes: number): string {
    if (minutes < 60) {
      return `${Math.round(minutes)}m`;
    }
    const hours = Math.floor(minutes / 60);
    const mins = Math.round(minutes % 60);
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }

  formatNumber(num: number): string {
    if (num >= 1000000) {
      return (num / 1000000).toFixed(1) + 'M';
    }
    if (num >= 1000) {
      return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
  }

  formatPercentage(value: number): string {
    return value.toFixed(1) + '%';
  }

  formatDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  formatRelativeTime(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return `${diffDays} days ago`;
    if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`;
    return `${Math.floor(diffDays / 30)} months ago`;
  }

  getProgressBarClass(progress: number): string {
    if (progress >= 80) return 'bg-success';
    if (progress >= 50) return 'bg-primary';
    if (progress >= 25) return 'bg-warning';
    return 'bg-danger';
  }

  getRiskBadgeClass(level: 'low' | 'medium' | 'high'): string {
    switch (level) {
      case 'high': return 'badge-risk-high';
      case 'medium': return 'badge-risk-medium';
      case 'low': return 'badge-risk-low';
    }
  }

  getScoreClass(score: number): string {
    if (score >= 80) return 'score-excellent';
    if (score >= 60) return 'score-good';
    if (score >= 40) return 'score-average';
    return 'score-poor';
  }

  getDifficultyClass(difficulty: 'easy' | 'medium' | 'hard'): string {
    switch (difficulty) {
      case 'easy': return 'difficulty-easy';
      case 'medium': return 'difficulty-medium';
      case 'hard': return 'difficulty-hard';
    }
  }

  getGrowthIcon(rate: number): string {
    return rate >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right';
  }

  getGrowthClass(rate: number): string {
    return rate >= 0 ? 'text-success' : 'text-danger';
  }

  getStatusIcon(status: string): string {
    switch (status) {
      case 'completed': return 'bi-check-circle-fill';
      case 'in_progress': return 'bi-play-circle-fill';
      case 'not_started': return 'bi-circle';
      case 'dropped': return 'bi-x-circle-fill';
      default: return 'bi-circle';
    }
  }

  getStatusClass(status: string): string {
    switch (status) {
      case 'completed': return 'status-completed';
      case 'in_progress': return 'status-progress';
      case 'not_started': return 'status-not-started';
      case 'dropped': return 'status-dropped';
      default: return '';
    }
  }

  getContentTypeIcon(type: string): string {
    switch (type) {
      case 'VIDEO': return 'bi-play-btn-fill';
      case 'AUDIO': return 'bi-music-note-beamed';
      case 'PDF': return 'bi-file-pdf-fill';
      case 'SCORM': return 'bi-box-fill';
      case 'TEXT': return 'bi-file-text-fill';
      case 'QUIZ': return 'bi-question-circle-fill';
      default: return 'bi-file-fill';
    }
  }

  // Funnel percentage calculation
  getFunnelPercentage(value: number, total: number): number {
    return total > 0 ? (value / total) * 100 : 0;
  }

  // Send reminder to at-risk learners
  sendReminder(learner: AtRiskLearner): void {
    console.log('Sending reminder to:', learner.email);
    // Implement reminder functionality
  }

  // View learner details
  viewLearnerDetails(userId: number): void {
    console.log('View learner:', userId);
    // Implement navigation to learner details
  }

  // Refresh data
  refreshData(): void {
    this.loadInsights();
  }

  // Track by functions for ngFor
  trackByModuleId(index: number, module: ModuleAnalytics): number {
    return module.module_id;
  }

  trackByUserId(index: number, learner: LearnerPerformance | AtRiskLearner): number {
    return learner.user_id;
  }

  trackByAssessmentId(index: number, assessment: AssessmentDetail): number {
    return assessment.assessment_id;
  }

  // Donut chart calculations
  calculateSegmentDash(segments: any[], segmentType: string): string {
    const segment = segments.find(s => s.segment === segmentType);
    if (!segment) return '0 251.2';
    const circumference = 2 * Math.PI * 40; // 251.2
    const dash = (segment.percentage / 100) * circumference;
    return `${dash} ${circumference}`;
  }

  calculateSegmentOffset(segments: any[], segmentType: string): string {
    const circumference = 2 * Math.PI * 40;
    const order = ['completed', 'in_progress', 'not_started', 'dropped'];
    const index = order.indexOf(segmentType);

    let offset = circumference * 0.25; // Start at top
    for (let i = 0; i < index; i++) {
      const seg = segments.find(s => s.segment === order[i]);
      if (seg) {
        offset -= (seg.percentage / 100) * circumference;
      }
    }
    return offset.toString();
  }

  // Interaction type helpers
  getInteractionIcon(type: string): string {
    switch (type) {
      case 'video_plays': return 'bi bi-play-btn-fill';
      case 'document_views': return 'bi bi-file-text-fill';
      case 'quiz_attempts': return 'bi bi-question-circle-fill';
      case 'notes_created': return 'bi bi-journal-text';
      case 'discussions': return 'bi bi-chat-dots-fill';
      default: return 'bi bi-circle-fill';
    }
  }

  formatInteractionType(type: string): string {
    return type.split('_').map(word =>
      word.charAt(0).toUpperCase() + word.slice(1)
    ).join(' ');
  }

  // Get tui-icon for lesson type
  getLessonIcon(type: string): string {
    switch (type?.toLowerCase()) {
      case 'video': return '@tui.play';
      case 'audio': return '@tui.music';
      case 'pdf': return '@tui.file-text';
      case 'scorm': return '@tui.box';
      case 'text': return '@tui.file';
      case 'quiz': return '@tui.help-circle';
      default: return '@tui.file';
    }
  }

  // Get tui-icon for interaction type
  getInteractionTuiIcon(type: string): string {
    switch (type) {
      case 'video_plays': return '@tui.play';
      case 'document_views': return '@tui.file-text';
      case 'quiz_attempts': return '@tui.list-checks';
      case 'notes_created': return '@tui.message-square-plus';
      case 'discussions': return '@tui.message-square-more';
      default: return '@tui.activity';
    }
  }

  // Score range class for distribution chart
  getScoreRangeClass(range: string): string {
    const start = parseInt(range.split('-')[0]);
    if (start >= 80) return 'score-81-100';
    if (start >= 60) return 'score-61-80';
    if (start >= 40) return 'score-41-60';
    if (start >= 20) return 'score-21-40';
    return 'score-0-20';
  }

  // Math helper for template
  Math = Math;

  // Get initials from full name
  getInitials(fullName: string): string {
    if (!fullName) return '?';
    const names = fullName.trim().split(' ');
    if (names.length === 1) return names[0].charAt(0).toUpperCase();
    return (names[0].charAt(0) + names[names.length - 1].charAt(0)).toUpperCase();
  }

  // Progress ring calculations for SVG
  getProgressDashArray(progress: number): string {
    const circumference = 2 * Math.PI * 54; // radius = 54
    return `${circumference} ${circumference}`;
  }

  getProgressDashOffset(progress: number): string {
    const circumference = 2 * Math.PI * 54;
    const offset = circumference - (progress / 100) * circumference;
    return `${offset}`;
  }
}
