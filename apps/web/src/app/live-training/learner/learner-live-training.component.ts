// live-training/learner/learner-live-training.component.ts

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  LiveTrainingService,
  LiveTraining,
  TrainingSession,
  TrainingEnrollment,
  LearnerProgress
} from '../live-training.service';
import {TuiDialog, TuiIcon} from '@taiga-ui/core';
import { TopComponent } from '../../partials/top/top.component';

@Component({
  selector: 'app-learner-live-training',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiDialog, TuiIcon, TopComponent],
  templateUrl: './learner-live-training.component.html',
  styleUrls: ['./learner-live-training.component.scss']
})
export class LearnerLiveTrainingComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Data
  enrolledTrainings: TrainingEnrollment[] = [];
  upcomingSessions: TrainingSession[] = [];
  selectedTraining: LiveTraining | null = null;
  selectedSession: TrainingSession | null = null;
  selectedEnrollment: TrainingEnrollment | null = null;
  learnerProgress: LearnerProgress | null = null;

  // UI State
  activeTab: 'dashboard' | 'trainings' | 'upcoming' | 'progress' | 'training-detail' | 'session-detail' = 'dashboard';
  loading = true;
  enrolling = false;

  // QR Scanner
  showQRScanner = false;
  scannerActive = false;
  qrToken = '';
  scanMessage = '';
  scanSuccess = false;

  // Session Detail Modal
  showSessionDetailModal = false;

  private readonly router = inject(Router);

  constructor(private liveTrainingService: LiveTrainingService) {}

  ngOnInit(): void {
    this.loadDashboardData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadDashboardData(): void {
    this.loading = true;

    // Load upcoming sessions
    this.liveTrainingService.getUpcomingSessions(5)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.upcomingSessions = data;
          this.loading = false;
        },
        error: err => {
          console.error('Error loading upcoming sessions:', err);
          this.loading = false;
        }
      });

    // Load my enrollments
    this.liveTrainingService.getMyEnrollments()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.enrolledTrainings = data;
        },
        error: err => {
          console.error('Error loading enrollments:', err);
        }
      });
  }

  loadTrainingProgress(enrollment: TrainingEnrollment): void {
    this.loading = true;

    this.liveTrainingService.getLearnerProgress(enrollment.training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.learnerProgress = data;
          this.activeTab = 'progress';
          this.loading = false;
        },
        error: err => {
          console.error('Error loading progress:', err);
          this.loading = false;
        }
      });
  }

  loadUpcomingSessions(): void {
    this.loading = true;

    this.liveTrainingService.getUpcomingSessions(20)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.upcomingSessions = data;
          this.activeTab = 'upcoming';
          this.loading = false;
        },
        error: err => {
          console.error('Error loading sessions:', err);
          this.loading = false;
        }
      });
  }

  // ==========================================
  // QR SCANNER
  // ==========================================

  openQRScanner(): void {
    this.showQRScanner = true;
    this.qrToken = '';
    this.scanMessage = '';
    this.scanSuccess = false;
  }

  closeQRScanner(): void {
    this.showQRScanner = false;
    this.scannerActive = false;
    this.qrToken = '';
    this.scanMessage = '';
  }

  // Manual QR token entry (for testing or when camera isn't available)
  submitQRCode(): void {
    if (!this.qrToken.trim()) return;

    this.scanMessage = '';

    this.liveTrainingService.checkIn(this.qrToken.trim())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.scanSuccess = true;
          // The server's own words: "Checked in" and "Checked in, marked as
          // late" are different outcomes, and somebody arriving late should
          // be told they were marked late rather than told nothing.
          this.scanMessage = result.message;
          // Refresh data after 2 seconds
          setTimeout(() => {
            this.closeQRScanner();
            this.loadDashboardData();
          }, 2000);
        },
        error: (err: { message?: string }) => {
          this.scanSuccess = false;
          // The API says what went wrong — an expired code, a register already
          // signed off, not being enrolled — and each is worth reading. The
          // old line reached into `err.error.message`, which an ApiError does
          // not have, so every failure read "Failed to mark attendance".
          this.scanMessage = err.message ?? 'That could not be marked. Please try again.';
        }
      });
  }

  // For actual QR scanning (would integrate with a QR library like zxing)
  onQRCodeScanned(token: string): void {
    this.qrToken = token;
    this.submitQRCode();
  }

  /** The certificates this person has earned. */
  viewCertificates(): void {
    void this.router.navigate(['/certificates']);
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  viewTrainingDetails(session: TrainingSession): void {
    this.liveTrainingService.getLearnerProgress(session.training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.learnerProgress = data;
          this.activeTab = 'progress';
        },
        error: err => console.error('Error loading progress:', err)
      });
  }

  goBack(): void {
    this.activeTab = 'dashboard';
    this.selectedTraining = null;
    this.learnerProgress = null;
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getSessionTypeIcon(type: string): string {
    switch (type) {
      case 'physical': return 'bi-building';
      case 'virtual': return 'bi-camera-video';
      case 'hybrid': return 'bi-diagram-3';
      default: return 'bi-calendar-event';
    }
  }

  getStatusBadgeClass(status: string): string {
    switch (status) {
      case 'scheduled': return 'bg-info';
      case 'ongoing': return 'bg-warning text-dark';
      case 'completed': return 'bg-success';
      case 'present': return 'bg-success';
      case 'late': return 'bg-warning text-dark';
      case 'absent': return 'bg-danger';
      case 'enrolled': return 'bg-primary';
      case 'eligible': return 'bg-success';
      case 'issued': return 'bg-success';
      default: return 'bg-secondary';
    }
  }

  getPlatformIcon(platform: string): string {
    switch (platform) {
      case 'zoom': return 'bi-camera-video-fill';
      case 'google_meet': return 'bi-google';
      case 'microsoft_teams': return 'bi-microsoft-teams';
      default: return 'bi-link-45deg';
    }
  }

  formatDate(date: string): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      weekday: 'short',
      month: 'short',
      day: 'numeric'
    });
  }

  formatTime(time: string): string {
    if (!time) return '-';
    return time.substring(0, 5);
  }

  formatDateTime(datetime: string): string {
    if (!datetime) return '-';
    return new Date(datetime).toLocaleString();
  }

  isSessionToday(session: TrainingSession): boolean {
    const today = new Date().toISOString().split('T')[0];
    return session.session_date === today;
  }

  isSessionUpcoming(session: TrainingSession): boolean {
    const now = new Date();
    const sessionDateTime = new Date(`${session.session_date}T${session.start_time}`);
    return sessionDateTime > now;
  }

  canJoinSession(session: TrainingSession): boolean {
    if (session.session_type === 'physical') return false;
    if (!session.join_url) return false;

    const now = new Date();
    const sessionStart = new Date(`${session.session_date}T${session.start_time}`);
    const sessionEnd = new Date(`${session.session_date}T${session.end_time}`);

    // Allow joining 15 minutes before start
    sessionStart.setMinutes(sessionStart.getMinutes() - 15);

    return now >= sessionStart && now <= sessionEnd;
  }

  joinSession(session: TrainingSession): void {
    // An in-app (Agora) class opens the room inside the LMS; the external
    // platforms open their own join link in a new tab.
    if (session.video_platform === 'agora') {
      void this.router.navigate(['/live-training/room', session.session_id]);
      return;
    }
    if (session.join_url) {
      window.open(session.join_url, '_blank');
    }
  }

  getTimeUntilSession(session: TrainingSession): string {
    const now = new Date();
    const sessionDateTime = new Date(`${session.session_date}T${session.start_time}`);
    const diff = sessionDateTime.getTime() - now.getTime();

    if (diff < 0) return 'Started';

    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

    if (hours > 24) {
      const days = Math.floor(hours / 24);
      return `${days} day${days > 1 ? 's' : ''}`;
    } else if (hours > 0) {
      return `${hours}h ${minutes}m`;
    } else {
      return `${minutes} min`;
    }
  }

  trackBySessionId(index: number, item: TrainingSession): string {
    return item.session_id;
  }

  // Get today's sessions from upcomingSessions
  getTodaySessions(): TrainingSession[] {
    if (!this.upcomingSessions) return [];
    return this.upcomingSessions.filter(session => this.isSessionToday(session));
  }

  // ==========================================
  // SINGLE ITEM LOADING
  // ==========================================

  loadTrainingDetails(training_id: string): void {
    this.loading = true;
    this.liveTrainingService.getTraining(training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: training => {
          this.selectedTraining = training;
          this.activeTab = 'training-detail';
          this.loading = false;
        },
        error: err => {
          console.error('Error loading training:', err);
          this.loading = false;
        }
      });
  }

  loadSessionDetails(session_id: string): void {
    this.loading = true;
    this.liveTrainingService.getSession(session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: session => {
          this.selectedSession = session;
          this.showSessionDetailModal = true;
          this.loading = false;
        },
        error: err => {
          console.error('Error loading session:', err);
          this.loading = false;
        }
      });
  }

  closeSessionDetailModal(): void {
    this.showSessionDetailModal = false;
    this.selectedSession = null;
  }

  loadEnrollmentDetails(enrollment_id: string): void {
    this.liveTrainingService.getEnrollment(enrollment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: enrollment => {
          this.selectedEnrollment = enrollment;
        },
        error: err => console.error('Error loading enrollment:', err)
      });
  }

  // ==========================================
  // SELF ENROLLMENT
  // ==========================================

  enrollInTraining(training_id: string): void {
    this.enrolling = true;
    this.liveTrainingService.enrollLearner(training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.enrolling = false;
          // Refresh data
          this.loadDashboardData();
          this.loadTrainingDetails(training_id);
        },
        error: err => {
          console.error('Error enrolling:', err);
          this.enrolling = false;
        }
      });
  }

  // ==========================================
  // CERTIFICATE ELIGIBILITY
  // ==========================================

  checkMyEligibility(enrollment: TrainingEnrollment): void {
    this.liveTrainingService.checkEligibility(enrollment.enrollment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: updated => {
          // Update the enrollment
          if (this.selectedEnrollment?.enrollment_id === updated.enrollment_id) {
            this.selectedEnrollment = updated;
          }
          // Update in enrolled trainings list
          const index = this.enrolledTrainings.findIndex(e => e.enrollment_id === updated.enrollment_id);
          if (index !== -1) {
            this.enrolledTrainings[index] = updated;
          }
        },
        error: err => console.error('Error checking eligibility:', err)
      });
  }

  // ==========================================
  // ADDITIONAL TRACK BY
  // ==========================================

  trackByEnrollmentId(index: number, item: TrainingEnrollment): string {
    return item.enrollment_id;
  }
}
