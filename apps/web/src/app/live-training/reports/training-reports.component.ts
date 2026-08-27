// live-training/reports/training-reports.component.ts

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  LiveTrainingService,
  TrainingAnalytics
} from '../live-training.service';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import { Router } from '@angular/router';

interface ReportData {
  training_id: string;
  title: string;
  type: string;
  sessions_count: number;
  total_enrolled: number;
  avg_attendance: number;
  completion_rate: number;
  certificates_issued: number;
}

interface DateRange {
  start: string;
  end: string;
  label: string;
}

@Component({
  selector: 'app-training-reports',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent],
  templateUrl: './training-reports.component.html',
  styleUrls: ['./training-reports.component.scss']
})
export class TrainingReportsComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);

  private destroy$ = new Subject<void>();

  // Date Range Options
  dateRanges: DateRange[] = [
    { start: this.getDateString(-7), end: this.getDateString(0), label: 'Last 7 Days' },
    { start: this.getDateString(-30), end: this.getDateString(0), label: 'Last 30 Days' },
    { start: this.getDateString(-90), end: this.getDateString(0), label: 'Last 90 Days' },
    { start: this.getFirstDayOfYear(), end: this.getDateString(0), label: 'This Year' }
  ];
  selectedDateRange: DateRange = this.dateRanges[1];

  // Overview Stats
  overviewStats = {
    total_trainings: 0,
    total_sessions: 0,
    total_learners: 0,
    certificates_issued: 0,
    trainings_change: 0,
    sessions_change: 0,
    learners_change: 0,
    certificates_change: 0
  };

  // Chart Data
  chartView: 'daily' | 'weekly' | 'monthly' = 'weekly';
  attendanceTrendData: { label: string; value: number; percentage: number }[] = [];

  // Type Distribution
  typeDistribution: { type: string; count: number; percentage: number }[] = [];

  // Reports Data
  reports: ReportData[] = [];
  filteredReports: ReportData[] = [];
  searchTerm = '';
  filterStatus = '';
  sortColumn = 'title';
  sortDirection: 'asc' | 'desc' = 'asc';

  // Top Instructors
  topInstructors: { name: string; avatar: string | null; sessions_conducted: number; avg_rating: number }[] = [];

  // Attendance Breakdown
  attendanceBreakdown = { present: 75, late: 10, absent: 12, excused: 3 };
  modeBreakdown = { physical: 60, virtual: 40 };

  // Certification Stats
  certStats = { total_eligible: 0, issued: 0, pending: 0, revoked: 0 };

  // Detail Modal
  showDetailModal = false;
  selectedReport: ReportData | null = null;
  selectedTrainingAnalytics: any = null;

  constructor(private liveTrainingService: LiveTrainingService) {}

  ngOnInit(): void {
    this.loadData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  /**
   * Back to the live training screens.
   *
   * This page had no way out at all: it is reached from the training
   * admin, sits under its own route, and offered nothing but the browser's
   * own back button — which the rest of the application never asks anybody
   * to use.
   */
  goBack(): void {
    void this.router.navigate(['/live-training/admin']);
  }

  loadData(): void {
    // Load overview stats
    this.loadOverviewStats();
    this.loadReports();
    this.loadChartData();
    this.loadTypeDistribution();
    this.loadTopInstructors();
    this.loadCertStats();
  }

  loadOverviewStats(): void {
    // Mock data - replace with API call
    this.overviewStats = {
      total_trainings: 24,
      total_sessions: 156,
      total_learners: 1245,
      certificates_issued: 890,
      trainings_change: 12,
      sessions_change: 8,
      learners_change: 15,
      certificates_change: 22
    };
  }

  loadReports(): void {
    // Load trainings from API
    this.liveTrainingService.getTrainings()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: trainings => {
          this.reports = trainings.map(t => ({
            training_id: t.training_id,
            title: t.title,
            type: t.training_type,
            sessions_count: t.session_count || 0,
            total_enrolled: t.enrollment_count || 0,
            avg_attendance: 0, // Will be calculated from analytics
            completion_rate: 0,
            certificates_issued: 0
          }));
          this.filterReports();

          // Load analytics for each training
          this.loadTrainingAnalytics();
        },
        error: err => console.error('Error loading trainings:', err)
      });
  }

  loadTrainingAnalytics(): void {
    // Load analytics for overview
    const analyticsRequests = this.reports.slice(0, 10).map(r =>
      this.liveTrainingService.getTrainingAnalytics(r.training_id)
    );

    if (analyticsRequests.length > 0) {
      forkJoin(analyticsRequests)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: analyticsArray => {
            analyticsArray.forEach((analytics, idx) => {
              if (this.reports[idx]) {
                this.reports[idx].avg_attendance = analytics.attendance?.average_percentage || 0;
                this.reports[idx].completion_rate = (analytics.sessions?.completed || 0) / Math.max(analytics.sessions?.total || 1, 1) * 100;
                this.reports[idx].certificates_issued = analytics.certification?.issued || 0;
              }
            });
            this.filterReports();
            this.updateOverviewStats(analyticsArray);
          },
          error: err => console.error('Error loading analytics:', err)
        });
    }
  }

  updateOverviewStats(analyticsArray: TrainingAnalytics[]): void {
    let totalSessions = 0;
    let totalLearners = 0;
    let totalCerts = 0;

    analyticsArray.forEach(a => {
      totalSessions += a.sessions?.total || 0;
      totalLearners += a.enrollments?.total || 0;
      totalCerts += a.certification?.issued || 0;
    });

    this.overviewStats.total_trainings = this.reports.length;
    this.overviewStats.total_sessions = totalSessions;
    this.overviewStats.total_learners = totalLearners;
    this.overviewStats.certificates_issued = totalCerts;
  }

  loadChartData(): void {
    // Mock attendance trend data
    this.attendanceTrendData = [
      { label: 'Mon', value: 85, percentage: 85 },
      { label: 'Tue', value: 78, percentage: 78 },
      { label: 'Wed', value: 92, percentage: 92 },
      { label: 'Thu', value: 88, percentage: 88 },
      { label: 'Fri', value: 75, percentage: 75 },
      { label: 'Sat', value: 45, percentage: 45 },
      { label: 'Sun', value: 30, percentage: 30 }
    ];
  }

  loadTypeDistribution(): void {
    this.typeDistribution = [
      { type: 'physical', count: 12, percentage: 50 },
      { type: 'virtual', count: 8, percentage: 33 },
      { type: 'hybrid', count: 4, percentage: 17 }
    ];
  }

  loadTopInstructors(): void {
    this.topInstructors = [
      { name: 'John Smith', avatar: null, sessions_conducted: 45, avg_rating: 4.8 },
      { name: 'Sarah Johnson', avatar: null, sessions_conducted: 38, avg_rating: 4.7 },
      { name: 'Michael Brown', avatar: null, sessions_conducted: 32, avg_rating: 4.6 },
      { name: 'Emily Davis', avatar: null, sessions_conducted: 28, avg_rating: 4.5 },
      { name: 'David Wilson', avatar: null, sessions_conducted: 25, avg_rating: 4.4 }
    ];
  }

  loadCertStats(): void {
    this.certStats = {
      total_eligible: 1050,
      issued: 890,
      pending: 145,
      revoked: 15
    };
  }

  onDateRangeChange(): void {
    this.loadData();
  }

  filterReports(): void {
    let result = [...this.reports];

    if (this.searchTerm) {
      const term = this.searchTerm.toLowerCase();
      result = result.filter(r => r.title.toLowerCase().includes(term));
    }

    if (this.filterStatus) {
      result = result.filter(r => r.type === this.filterStatus);
    }

    this.filteredReports = result;
  }

  sortBy(column: string): void {
    if (this.sortColumn === column) {
      this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortColumn = column;
      this.sortDirection = 'asc';
    }

    this.filteredReports.sort((a: any, b: any) => {
      const valA = a[column];
      const valB = b[column];

      if (typeof valA === 'string') {
        return this.sortDirection === 'asc'
          ? valA.localeCompare(valB)
          : valB.localeCompare(valA);
      }

      return this.sortDirection === 'asc' ? valA - valB : valB - valA;
    });
  }

  viewDetails(report: ReportData): void {
    this.selectedReport = report;

    // Load full training details
    forkJoin({
      training: this.liveTrainingService.getTraining(report.training_id),
      analytics: this.liveTrainingService.getTrainingAnalytics(report.training_id)
    }).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ training, analytics }) => {
          this.selectedTrainingAnalytics = {
            ...analytics,
            training
          };
          this.showDetailModal = true;
        },
        error: err => console.error('Error loading training details:', err)
      });
  }

  closeDetailModal(): void {
    this.showDetailModal = false;
    this.selectedReport = null;
    this.selectedTrainingAnalytics = null;
  }

  exportReport(): void {
    // Export all reports as CSV
    const csv = this.generateCSV(this.filteredReports);
    this.downloadCSV(csv, 'training_reports.csv');
  }

  exportTrainingReport(report: ReportData): void {
    // Export attendance for a single training
    this.liveTrainingService.exportAttendance(report.training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          const csv = this.generateCSV(data);
          this.downloadCSV(csv, `${report.title.replace(/\s+/g, '_')}_attendance.csv`);
        },
        error: err => console.error('Error exporting:', err)
      });
  }

  generateCSV(data: any[]): string {
    if (data.length === 0) return '';
    const headers = Object.keys(data[0]);
    const rows = data.map(row => headers.map(h => row[h]).join(','));
    return [headers.join(','), ...rows].join('\n');
  }

  downloadCSV(csv: string, filename: string): void {
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
  }

  // Helpers
  getDateString(daysOffset: number): string {
    const date = new Date();
    date.setDate(date.getDate() + daysOffset);
    return date.toISOString().split('T')[0];
  }

  getFirstDayOfYear(): string {
    return new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0];
  }

  getTypeIcon(type: string): string {
    switch (type) {
      case 'physical': return 'bi-building';
      case 'virtual': return 'bi-camera-video';
      case 'hybrid': return 'bi-diagram-3';
      default: return 'bi-calendar-event';
    }
  }

  getTypeColor(type: string): string {
    switch (type) {
      case 'physical': return 'bg-success';
      case 'virtual': return 'bg-info';
      case 'hybrid': return 'bg-warning';
      default: return 'bg-secondary';
    }
  }

  getTypeBadge(type: string): string {
    switch (type) {
      case 'physical': return 'bg-success';
      case 'virtual': return 'bg-info';
      case 'hybrid': return 'bg-warning text-dark';
      default: return 'bg-secondary';
    }
  }

  getAttendanceColor(percentage: number): string {
    if (percentage >= 90) return 'bg-success';
    if (percentage >= 75) return 'bg-info';
    if (percentage >= 60) return 'bg-warning';
    return 'bg-danger';
  }

  getCompletionBadge(rate: number): string {
    if (rate >= 90) return 'bg-success';
    if (rate >= 75) return 'bg-info';
    if (rate >= 50) return 'bg-warning text-dark';
    return 'bg-danger';
  }

  getRankClass(rank: number): string {
    switch (rank) {
      case 1: return 'rank-1';
      case 2: return 'rank-2';
      case 3: return 'rank-3';
      default: return 'rank-default';
    }
  }

  getInitials(name: string): string {
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
  }

  trackByTrainingId(index: number, item: ReportData): string {
    return item.training_id;
  }
}
