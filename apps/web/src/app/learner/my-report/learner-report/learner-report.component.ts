// learner-report/learner-report.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  LearnerReportService,
  LearningOverview,
  CourseProgress,
  DailyActivity,
  MonthlyActivity,
  CategoryBreakdown,
  QuizAttempt,
  Certificate,
  RecentActivity
} from './learner-report.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

type TabType = 'overview' | 'courses' | 'activity' | 'quizzes' | 'certificates';
type ActivityPeriod = 'daily' | 'weekly' | 'monthly';

@Component({
  selector: 'app-learner-report',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe],
  templateUrl: './learner-report.component.html',
  styleUrls: ['./learner-report.component.scss']
})
export class LearnerReportComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Active tab
  activeTab: TabType = 'overview';

  // Data
  overview: LearningOverview | null = null;
  courseProgress: CourseProgress[] = [];
  dailyActivity: DailyActivity[] = [];
  monthlyActivity: MonthlyActivity[] = [];
  categoryBreakdown: CategoryBreakdown[] = [];
  quizAttempts: QuizAttempt[] = [];
  certificates: Certificate[] = [];
  recentActivities: RecentActivity[] = [];

  // Activity chart settings
  activityPeriod: ActivityPeriod = 'daily';
  activityDays = 30;

  // Loading states
  loading = false;
  loadingCourses = false;
  loadingActivity = false;
  loadingQuizzes = false;
  loadingCertificates = false;

  // Export state
  exporting = false;

  constructor(
    private reportService: LearnerReportService,
    private router: Router,
    private i18n: I18nService
  ) {}

  ngOnInit(): void {
    this.loadOverview();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // TAB NAVIGATION
  // ==========================================

  selectTab(tab: TabType): void {
    this.activeTab = tab;

    switch (tab) {
      case 'overview':
        if (!this.overview) this.loadOverview();
        break;
      case 'courses':
        if (this.courseProgress.length === 0) this.loadCourseProgress();
        break;
      case 'activity':
        if (this.dailyActivity.length === 0) this.loadDailyActivity();
        break;
      case 'quizzes':
        if (this.quizAttempts.length === 0) this.loadQuizPerformance();
        break;
      case 'certificates':
        if (this.certificates.length === 0) this.loadCertificates();
        break;
    }
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadOverview(): void {
    this.loading = true;

    this.reportService.getOverview()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.overview = data;
          this.loading = false;
        },
        error: () => this.loading = false
      });

    // Also load recent activities and category breakdown for overview
    this.reportService.getRecentActivities(10)
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.recentActivities = data);

    this.reportService.getCategoryBreakdown()
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.categoryBreakdown = data);

    this.reportService.getMonthlyActivity(6)
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.monthlyActivity = data);
  }

  loadCourseProgress(): void {
    this.loadingCourses = true;

    this.reportService.getCourseProgress()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.courseProgress = data;
          this.loadingCourses = false;
        },
        error: () => this.loadingCourses = false
      });
  }

  loadDailyActivity(): void {
    this.loadingActivity = true;

    this.reportService.getDailyActivity(this.activityDays)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.dailyActivity = data;
          this.loadingActivity = false;
        },
        error: () => this.loadingActivity = false
      });
  }

  loadMonthlyActivity(): void {
    this.loadingActivity = true;

    this.reportService.getMonthlyActivity(12)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.monthlyActivity = data;
          this.loadingActivity = false;
        },
        error: () => this.loadingActivity = false
      });
  }

  loadQuizPerformance(): void {
    this.loadingQuizzes = true;

    this.reportService.getQuizPerformance(50)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.quizAttempts = data;
          this.loadingQuizzes = false;
        },
        error: () => this.loadingQuizzes = false
      });
  }

  loadCertificates(): void {
    this.loadingCertificates = true;

    this.reportService.getCertificates()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.certificates = data;
          this.loadingCertificates = false;
        },
        error: () => this.loadingCertificates = false
      });
  }

  // ==========================================
  // ACTIVITY PERIOD CHANGE
  // ==========================================

  onActivityPeriodChange(): void {
    if (this.activityPeriod === 'daily') {
      this.loadDailyActivity();
    } else {
      this.loadMonthlyActivity();
    }
  }

  // ==========================================
  // EXPORT
  // ==========================================

  exportReport(): void {
    this.exporting = true;

    this.reportService.getFullReport()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
          const url = window.URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = `learning-report-${new Date().toISOString().split('T')[0]}.json`;
          link.click();
          window.URL.revokeObjectURL(url);
          this.exporting = false;
        },
        error: () => this.exporting = false
      });
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  playCourse(courseId: string): void {
    this.router.navigate(['/player', courseId]);
  }

  viewCertificate(cert: Certificate): void {
    if (cert.pdf_url) {
      window.open(cert.pdf_url, '_blank');
    }
  }

  // ==========================================
  // FORMATTING HELPERS
  // ==========================================

  formatDuration(minutes: number): string {
    if (!minutes) return '0m';
    if (minutes < 60) return `${Math.round(minutes)}m`;
    const hours = Math.floor(minutes / 60);
    const mins = Math.round(minutes % 60);
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }

  formatHours(minutes: number): string {
    if (!minutes) return '0';
    return (minutes / 60).toFixed(1);
  }

  formatDate(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString();
  }

  formatDateTime(dateString: string | null): string {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  formatRelativeDate(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return this.i18n.t('report.just_now');
    if (diffMins < 60) return this.i18n.t('report.mins_ago', { count: diffMins });
    if (diffHours < 24) return this.i18n.t('report.hours_ago', { count: diffHours });
    if (diffDays === 1) return this.i18n.t('report.yesterday');
    if (diffDays < 7) return this.i18n.t('report.days_ago', { count: diffDays });
    return date.toLocaleDateString();
  }

  getStatusClass(status: string): string {
    const classes: Record<string, string> = {
      'not_started': 'status-not-started',
      'in_progress': 'status-in-progress',
      'completed': 'status-completed',
      'valid': 'status-valid',
      'expired': 'status-expired',
      'expiring_soon': 'status-expiring'
    };
    return classes[status] || '';
  }

  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      'not_started': this.i18n.t('report.not_started'),
      'in_progress': this.i18n.t('report.in_progress'),
      'completed': this.i18n.t('report.completed'),
      'valid': this.i18n.t('report.valid'),
      'expired': this.i18n.t('report.expired'),
      'expiring_soon': this.i18n.t('report.expiring_soon')
    };
    return labels[status] || status;
  }

  getActivityIcon(type: string): string {
    const icons: Record<string, string> = {
      'lesson': 'bi-play-circle',
      'course_completed': 'bi-check-circle',
      'certificate': 'bi-award',
      'quiz': 'bi-question-circle',
      'points': 'bi-star'
    };
    return icons[type] || 'bi-activity';
  }

  getActivityLabel(activity: RecentActivity): string {
    switch (activity.activity_type) {
      case 'lesson':
        return this.i18n.t(activity.status === 'completed' ? 'report.act_completed_lesson' : 'report.act_viewed_lesson');
      case 'course_completed':
        return this.i18n.t('report.act_completed_course');
      case 'certificate':
        return this.i18n.t('report.act_earned_certificate');
      case 'quiz':
        return this.i18n.t('report.act_completed_quiz');
      case 'points':
        return this.i18n.t('report.act_points_earned');
      default:
        return this.i18n.t('report.act_activity');
    }
  }

  // Chart helpers
  getMaxMinutes(): number {
    if (this.activityPeriod === 'daily') {
      return Math.max(...this.dailyActivity.map(d => d.minutes_spent), 1);
    }
    return Math.max(...this.monthlyActivity.map(d => d.minutes_spent), 1);
  }

  getBarHeight(minutes: number): number {
    const max = this.getMaxMinutes();
    return (minutes / max) * 100;
  }

  getCategoryMaxCourses(): number {
    return Math.max(...this.categoryBreakdown.map(c => c.total_courses), 1);
  }

  getCategoryBarWidth(count: number): number {
    return (count / this.getCategoryMaxCourses()) * 100;
  }
}
