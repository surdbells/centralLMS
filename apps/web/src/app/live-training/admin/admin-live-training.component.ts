// live-training/admin/admin-live-training.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  LiveTrainingService,
  LiveTraining,
  TrainingSession,
  TrainingEnrollment,
  SessionAttendance,
  SessionInstructor,
  TrainingAnalytics,
  VideoIntegration
} from '../live-training.service';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {RouterLink} from '@angular/router';

@Component({
  selector: 'app-admin-live-training',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, RouterLink],
  templateUrl: './admin-live-training.component.html',
  styleUrls: ['./admin-live-training.component.scss']
})
export class AdminLiveTrainingComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Data
  trainings: LiveTraining[] = [];
  filteredTrainings: LiveTraining[] = [];
  selectedTraining: LiveTraining | null = null;
  sessions: TrainingSession[] = [];
  enrollments: TrainingEnrollment[] = [];
  attendance: SessionAttendance[] = [];
  analytics: TrainingAnalytics | null = null;
  integrations: VideoIntegration[] = [];

  // UI State
  activeTab: 'list' | 'details' | 'sessions' | 'enrollments' | 'analytics' | 'integrations' = 'list';
  loading = true;
  saving = false;
  searchTerm = '';

  // Filters
  filterStatus = '';
  filterType = '';

  // Modals
  showTrainingModal = false;
  showSessionModal = false;
  showEnrollModal = false;
  showAttendanceModal = false;
  showQRModal = false;
  showDeleteModal = false;

  // Form Data
  editingTraining: Partial<LiveTraining> = {};
  editingSession: Partial<TrainingSession> = {};
  isEditMode = false;

  // Session Management
  selectedSession: TrainingSession | null = null;
  qrCodeData: { qr_token: string; expires_at: string } | null = null;

  // Enrollment
  selectedUsers: string[] = [];
  availableUsers: Array<{user_id: string; first_name: string; last_name: string; email: string}> = [];
  quickEnrollUserId = '';

  // Attendance Override
  overrideUserId: string | null = null;
  overrideStatus = '';
  overrideReason = '';

  // Delete
  deleteType: 'training' | 'session' = 'training';
  deleteId = '';
  deleteName = '';

  // Instructor Management
  showInstructorModal = false;
  sessionInstructors: SessionInstructor[] = [];
  availableInstructors: Array<{user_id: string; first_name: string; last_name: string; email: string}> = [];
  newInstructorId: string | null = null;
  newInstructorRole = 'primary';
  newInstructorIsHost = false;

  // Certificate Management
  showCertificateModal = false;
  selectedEnrollment: TrainingEnrollment | null = null;
  revokeReason = '';

  // Integration Management
  showIntegrationModal = false;
  editingIntegration: Partial<VideoIntegration> = {};

  // Virtual Attendance
  showVirtualAttendanceModal = false;
  virtualAttendanceData = {
    learner_id: '',
    join_time: '',
    leave_time: '',
    duration_minutes: 0
  };

  // Constants
  trainingTypes = ['physical', 'virtual', 'hybrid'];
  sessionStatuses = ['scheduled', 'ongoing', 'completed', 'cancelled'];
  trainingStatuses = ['draft', 'published', 'ongoing', 'completed', 'cancelled'];
  attendanceStatuses = ['present', 'late', 'absent'];
  videoPlatforms = ['zoom', 'google_meet', 'microsoft_teams'];
  instructorRoles = ['primary', 'co_instructor', 'assistant'];
  reminderChannels = ['email', 'in_app', 'sms', 'whatsapp'];

  constructor(private liveTrainingService: LiveTrainingService) {}

  ngOnInit(): void {
    this.loadTrainings();
    this.loadIntegrations();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadTrainings(): void {
    this.loading = true;
    this.liveTrainingService.getTrainings()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.trainings = data;
          this.applyFilters();
          this.loading = false;
        },
        error: err => {
          console.error('Error loading trainings:', err);
          this.loading = false;
        }
      });
  }

  loadTrainingDetails(training: LiveTraining): void {
    this.selectedTraining = training;
    this.loading = true;

    forkJoin({
      sessions: this.liveTrainingService.getTrainingSessions(training.training_id),
      enrollments: this.liveTrainingService.getTrainingEnrollments(training.training_id),
      analytics: this.liveTrainingService.getTrainingAnalytics(training.training_id)
    }).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.sessions = data.sessions;
          this.enrollments = data.enrollments;
          this.analytics = data.analytics;
          this.activeTab = 'details';
          this.loading = false;
        },
        error: err => {
          console.error('Error loading training details:', err);
          this.loading = false;
        }
      });
  }

  loadSessionAttendance(session: TrainingSession): void {
    this.selectedSession = session;
    this.loading = true;

    this.liveTrainingService.getSessionAttendance(session.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.attendance = data;
          this.showAttendanceModal = true;
          this.loading = false;
        },
        error: err => {
          console.error('Error loading attendance:', err);
          this.loading = false;
        }
      });
  }

  loadIntegrations(): void {
    this.liveTrainingService.getIntegrations()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => this.integrations = data,
        error: err => console.error('Error loading integrations:', err)
      });
  }

  // ==========================================
  // FILTERING
  // ==========================================

  applyFilters(): void {
    let result = [...this.trainings];

    if (this.searchTerm) {
      const term = this.searchTerm.toLowerCase();
      result = result.filter(t =>
        t.title.toLowerCase().includes(term) ||
        t.description?.toLowerCase().includes(term)
      );
    }

    if (this.filterStatus) {
      result = result.filter(t => t.status === this.filterStatus);
    }

    if (this.filterType) {
      result = result.filter(t => t.training_type === this.filterType);
    }

    this.filteredTrainings = result;
  }

  onSearchChange(): void {
    this.applyFilters();
  }

  onFilterChange(): void {
    this.applyFilters();
  }

  // ==========================================
  // TRAINING CRUD
  // ==========================================

  openNewTrainingModal(): void {
    this.isEditMode = false;
    this.editingTraining = {
      title: '',
      description: '',
      training_type: 'virtual',
      status: 'draft',
      certification_enabled: false,
      min_attendance_percentage: 80,
      reminder_24h: true,
      reminder_1h: true,
      reminder_15m: false,
      reminder_channels: ['email', 'in_app'],
      qr_grace_period_minutes: 15,
      qr_valid_before_start_minutes: 30,
      min_virtual_duration_minutes: 30
    };
    this.showTrainingModal = true;
  }

  openEditTrainingModal(training: LiveTraining): void {
    this.isEditMode = true;
    this.editingTraining = { ...training };
    this.showTrainingModal = true;
  }

  closeTrainingModal(): void {
    this.showTrainingModal = false;
    this.editingTraining = {};
  }

  saveTraining(): void {
    if (!this.editingTraining.title) return;

    this.saving = true;

    const operation = this.isEditMode
      ? this.liveTrainingService.updateTraining(this.editingTraining.training_id!, this.editingTraining)
      : this.liveTrainingService.createTraining(this.editingTraining);

    operation.pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeTrainingModal();
          this.loadTrainings();
        },
        error: err => {
          console.error('Error saving training:', err);
          this.saving = false;
        }
      });
  }

  confirmDeleteTraining(training: LiveTraining): void {
    this.deleteType = 'training';
    this.deleteId = training.training_id;
    this.deleteName = training.title;
    this.showDeleteModal = true;
  }

  executeDelete(): void {
    this.saving = true;

    const operation = this.deleteType === 'training'
      ? this.liveTrainingService.deleteTraining(this.deleteId)
      : this.liveTrainingService.deleteSession(this.deleteId);

    operation.pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.showDeleteModal = false;
          if (this.deleteType === 'training') {
            this.loadTrainings();
            this.activeTab = 'list';
            this.selectedTraining = null;
          } else {
            this.loadTrainingDetails(this.selectedTraining!);
          }
        },
        error: err => {
          console.error('Error deleting:', err);
          this.saving = false;
        }
      });
  }

  publishTraining(training: LiveTraining): void {
    this.liveTrainingService.updateTraining(training.training_id, { status: 'published' })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadTrainings();
          if (this.selectedTraining?.training_id === training.training_id) {
            this.loadTrainingDetails(training);
          }
        },
        error: err => console.error('Error publishing:', err)
      });
  }

  // ==========================================
  // SESSION MANAGEMENT
  // ==========================================

  openNewSessionModal(): void {
    if (!this.selectedTraining) return;

    this.isEditMode = false;
    this.editingSession = {
      title: `Session ${this.sessions.length + 1}`,
      session_type: this.selectedTraining.training_type,
      session_date: '',
      start_time: '09:00',
      end_time: '17:00',
      timezone: 'UTC',
      is_mandatory: false,
      allow_late_join: true,
      late_threshold_minutes: 15
    };
    this.showSessionModal = true;
  }

  openEditSessionModal(session: TrainingSession): void {
    this.isEditMode = true;
    this.editingSession = { ...session };
    this.showSessionModal = true;
  }

  closeSessionModal(): void {
    this.showSessionModal = false;
    this.editingSession = {};
  }

  saveSession(): void {
    if (!this.editingSession.title || !this.editingSession.session_date) return;

    this.saving = true;

    const operation = this.isEditMode
      ? this.liveTrainingService.updateSession(this.editingSession.session_id!, this.editingSession)
      : this.liveTrainingService.createSession(this.selectedTraining!.training_id, this.editingSession);

    operation.pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeSessionModal();
          this.loadTrainingDetails(this.selectedTraining!);
        },
        error: err => {
          console.error('Error saving session:', err);
          this.saving = false;
        }
      });
  }

  confirmDeleteSession(session: TrainingSession): void {
    this.deleteType = 'session';
    this.deleteId = session.session_id;
    this.deleteName = session.title;
    this.showDeleteModal = true;
  }

  // ==========================================
  // QR CODE
  // ==========================================

  openQRModal(session: TrainingSession): void {
    this.selectedSession = session;
    this.qrCodeData = null;

    if (session.qr_code_token) {
      this.qrCodeData = {
        qr_token: session.qr_code_token,
        expires_at: session.qr_code_expires_at || ''
      };
      this.showQRModal = true;
    } else {
      // Use loadQRData to fetch or generate QR
      this.loadQRData(session);
    }
  }

  generateQRCode(session: TrainingSession): void {
    this.liveTrainingService.generateQRCode(session.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.qrCodeData = {
            qr_token: data.qr_token,
            expires_at: ''
          };
          this.showQRModal = true;
          // Reload session to get expires_at
          this.loadTrainingDetails(this.selectedTraining!);
        },
        error: err => console.error('Error generating QR:', err)
      });
  }

  closeQRModal(): void {
    this.showQRModal = false;
    this.qrCodeData = null;
  }

  getQRCodeUrl(): string {
    if (!this.qrCodeData) return '';
    // Generate QR code URL using external service
    return `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(this.qrCodeData.qr_token)}`;
  }

  // ==========================================
  // ENROLLMENT
  // ==========================================

  openEnrollModal(): void {
    if (!this.selectedTraining) return;
    this.selectedUsers = [];
    // Load available users (you'd implement this)
    this.showEnrollModal = true;
  }

  closeEnrollModal(): void {
    this.showEnrollModal = false;
    this.selectedUsers = [];
  }

  enrollSelectedUsers(): void {
    if (!this.selectedTraining || this.selectedUsers.length === 0) return;

    this.saving = true;

    this.liveTrainingService.bulkEnroll(this.selectedTraining.training_id, this.selectedUsers)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: result => {
          this.saving = false;
          this.closeEnrollModal();
          this.loadTrainingDetails(this.selectedTraining!);
          // Show results
          console.log(`Enrolled: ${result.success.length}, Failed: ${result.failed.length}`);
        },
        error: err => {
          console.error('Error enrolling:', err);
          this.saving = false;
        }
      });
  }

  toggleUserSelection(userId: string): void {
    const index = this.selectedUsers.indexOf(userId);
    if (index > -1) {
      this.selectedUsers.splice(index, 1);
    } else {
      this.selectedUsers.push(userId);
    }
  }

  cancelEnrollment(enrollment: TrainingEnrollment): void {
    if (!confirm(`Cancel enrollment for ${enrollment.first_name} ${enrollment.last_name}?`)) return;

    this.liveTrainingService.cancelEnrollment(enrollment.enrollment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadTrainingDetails(this.selectedTraining!),
        error: err => console.error('Error cancelling enrollment:', err)
      });
  }

  // ==========================================
  // ATTENDANCE
  // ==========================================

  closeAttendanceModal(): void {
    this.showAttendanceModal = false;
    this.attendance = [];
    this.selectedSession = null;
  }

  overrideAttendance(att: SessionAttendance): void {
    this.overrideUserId = att.user_id;
    this.overrideStatus = att.status;
    this.overrideReason = '';
  }

  submitAttendanceOverride(): void {
    if (!this.selectedSession || !this.overrideUserId || !this.overrideReason) return;

    this.saving = true;

    this.liveTrainingService.overrideAttendance(
      this.selectedSession.session_id,
      this.overrideUserId,
      this.overrideStatus,
      this.overrideReason
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.overrideUserId = null;
          this.loadSessionAttendance(this.selectedSession!);
        },
        error: err => {
          console.error('Error overriding attendance:', err);
          this.saving = false;
        }
      });
  }

  lockSessionAttendance(): void {
    if (!this.selectedSession) return;
    if (!confirm('Lock attendance for this session? This cannot be undone.')) return;

    this.liveTrainingService.lockAttendance(this.selectedSession.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadSessionAttendance(this.selectedSession!);
          this.loadTrainingDetails(this.selectedTraining!);
        },
        error: err => console.error('Error locking attendance:', err)
      });
  }

  // ==========================================
  // CERTIFICATION
  // ==========================================

  issueCertificate(enrollment: TrainingEnrollment): void {
    if (!confirm(`Issue certificate to ${enrollment.first_name} ${enrollment.last_name}?`)) return;

    this.liveTrainingService.issueCertificate(enrollment.enrollment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadTrainingDetails(this.selectedTraining!),
        error: err => console.error('Error issuing certificate:', err)
      });
  }

  // ==========================================
  // EXPORT
  // ==========================================

  exportAttendance(): void {
    if (!this.selectedTraining) return;

    this.liveTrainingService.exportAttendance(this.selectedTraining.training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          // Convert to CSV and download
          this.downloadCSV(data, `attendance_${this.selectedTraining!.title}.csv`);
        },
        error: err => console.error('Error exporting:', err)
      });
  }

  private downloadCSV(data: any[], filename: string): void {
    if (!data.length) return;

    const headers = Object.keys(data[0]);
    const csv = [
      headers.join(','),
      ...data.map(row => headers.map(h => `"${row[h] || ''}"`).join(','))
    ].join('\n');

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getStatusBadgeClass(status: string): string {
    switch (status) {
      case 'draft': return 'bg-secondary';
      case 'published': return 'bg-primary';
      case 'scheduled': return 'bg-info';
      case 'ongoing': return 'bg-warning text-dark';
      case 'completed': return 'bg-success';
      case 'cancelled': return 'bg-danger';
      case 'present': return 'bg-success';
      case 'late': return 'bg-warning text-dark';
      case 'absent': return 'bg-danger';
      case 'enrolled': return 'bg-primary';
      case 'waitlisted': return 'bg-warning text-dark';
      case 'eligible': return 'bg-success';
      case 'issued': return 'bg-success';
      case 'not_eligible': return 'bg-danger';
      default: return 'bg-secondary';
    }
  }

  getTypeIcon(type: string): string {
    switch (type) {
      case 'physical': return 'bi-building';
      case 'virtual': return 'bi-camera-video';
      case 'hybrid': return 'bi-diagram-3';
      default: return 'bi-calendar-event';
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
    return new Date(date).toLocaleDateString();
  }

  formatTime(time: string): string {
    if (!time) return '-';
    return time.substring(0, 5);
  }

  formatDateTime(datetime: string): string {
    if (!datetime) return '-';
    return new Date(datetime).toLocaleString();
  }

  goBack(): void {
    this.activeTab = 'list';
    this.selectedTraining = null;
  }

  trackByTrainingId(index: number, item: LiveTraining): string {
    return item.training_id;
  }

  trackBySessionId(index: number, item: TrainingSession): string {
    return item.session_id;
  }

  trackByEnrollmentId(index: number, item: TrainingEnrollment): string {
    return item.enrollment_id;
  }

  trackByAttendanceId(index: number, item: SessionAttendance): string {
    return item.attendance_id;
  }

  // Toggle reminder channels
  toggleReminderChannel(channel: string): void {
    const channels = this.editingTraining.reminder_channels || [];
    const index = channels.indexOf(channel);
    if (index > -1) {
      channels.splice(index, 1);
    } else {
      channels.push(channel);
    }
    this.editingTraining.reminder_channels = [...channels];
  }

  hasReminderChannel(channel: string): boolean {
    return (this.editingTraining.reminder_channels || []).includes(channel);
  }

  // Get attendance count by status
  getAttendanceCount(status: string): number {
    if (!this.attendance) return 0;
    return this.attendance.filter(a => a.status === status).length;
  }

  // ==========================================
  // SINGLE ITEM LOADING
  // ==========================================

  loadSingleTraining(training_id: string): void {
    this.loading = true;
    this.liveTrainingService.getTraining(training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: training => {
          this.selectedTraining = training;
          this.loadTrainingDetails(training);
        },
        error: err => {
          console.error('Error loading training:', err);
          this.loading = false;
        }
      });
  }

  loadSingleSession(session_id: string): void {
    this.loading = true;
    this.liveTrainingService.getSession(session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: session => {
          this.selectedSession = session;
          this.loading = false;
        },
        error: err => {
          console.error('Error loading session:', err);
          this.loading = false;
        }
      });
  }

  loadSingleEnrollment(enrollment_id: string): void {
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
  // INSTRUCTOR MANAGEMENT
  // ==========================================

  openInstructorModal(session: TrainingSession): void {
    this.selectedSession = session;
    this.loadSessionInstructors(session.session_id);
    this.showInstructorModal = true;
    this.newInstructorId = null;
    this.newInstructorRole = 'primary';
    this.newInstructorIsHost = false;
  }

  closeInstructorModal(): void {
    this.showInstructorModal = false;
    this.sessionInstructors = [];
    this.selectedSession = null;
  }

  loadSessionInstructors(session_id: string): void {
    this.liveTrainingService.getSessionInstructors(session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: instructors => {
          this.sessionInstructors = instructors;
        },
        error: err => console.error('Error loading instructors:', err)
      });
  }

  assignInstructor(): void {
    if (!this.selectedSession || !this.newInstructorId) return;

    this.saving = true;
    this.liveTrainingService.assignInstructor(
      this.selectedSession.session_id,
      this.newInstructorId,
      this.newInstructorRole,
      this.newInstructorIsHost
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.loadSessionInstructors(this.selectedSession!.session_id);
          this.newInstructorId = null;
          this.newInstructorRole = 'primary';
          this.newInstructorIsHost = false;
        },
        error: err => {
          console.error('Error assigning instructor:', err);
          this.saving = false;
        }
      });
  }

  removeInstructor(instructor: SessionInstructor): void {
    if (!this.selectedSession) return;
    if (!confirm(`Remove ${instructor.first_name} ${instructor.last_name} as instructor?`)) return;

    this.liveTrainingService.removeInstructor(
      this.selectedSession.session_id,
      String(instructor.id)
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadSessionInstructors(this.selectedSession!.session_id);
        },
        error: err => console.error('Error removing instructor:', err)
      });
  }

  // ==========================================
  // INDIVIDUAL ENROLLMENT
  // ==========================================

  enrollSingleLearner(learner_id: string): void {
    if (!this.selectedTraining) return;

    this.saving = true;
    this.liveTrainingService.enrollLearner(this.selectedTraining.training_id, learner_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.loadTrainingDetails(this.selectedTraining!);
        },
        error: err => {
          console.error('Error enrolling learner:', err);
          this.saving = false;
        }
      });
  }

  // ==========================================
  // CERTIFICATE MANAGEMENT
  // ==========================================

  openCertificateModal(enrollment: TrainingEnrollment): void {
    this.selectedEnrollment = enrollment;
    this.revokeReason = '';
    this.showCertificateModal = true;
  }

  closeCertificateModal(): void {
    this.showCertificateModal = false;
    this.selectedEnrollment = null;
    this.revokeReason = '';
  }

  checkEligibility(enrollment: TrainingEnrollment): void {
    this.liveTrainingService.checkEligibility(enrollment.enrollment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: updated => {
          // Update enrollment in list
          const index = this.enrollments.findIndex(e => e.enrollment_id === enrollment.enrollment_id);
          if (index !== -1) {
            this.enrollments[index] = updated;
          }
        },
        error: err => console.error('Error checking eligibility:', err)
      });
  }

  revokeCertificate(): void {
    if (!this.selectedEnrollment || !this.revokeReason) return;

    this.saving = true;
    this.liveTrainingService.revokeCertificate(this.selectedEnrollment.enrollment_id, this.revokeReason)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeCertificateModal();
          this.loadTrainingDetails(this.selectedTraining!);
        },
        error: err => {
          console.error('Error revoking certificate:', err);
          this.saving = false;
        }
      });
  }

  // ==========================================
  // VIRTUAL ATTENDANCE
  // ==========================================

  openVirtualAttendanceModal(session: TrainingSession): void {
    this.selectedSession = session;
    this.virtualAttendanceData = {
      learner_id: '',
      join_time: '',
      leave_time: '',
      duration_minutes: 0
    };
    this.showVirtualAttendanceModal = true;
  }

  closeVirtualAttendanceModal(): void {
    this.showVirtualAttendanceModal = false;
    this.selectedSession = null;
  }

  markVirtualAttendance(): void {
    if (!this.selectedSession || !this.virtualAttendanceData.learner_id) return;

    this.saving = true;
    this.liveTrainingService.markAttendanceVirtual(
      this.selectedSession.session_id,
      this.virtualAttendanceData.learner_id,
      {
        join_time: this.virtualAttendanceData.join_time || undefined,
        leave_time: this.virtualAttendanceData.leave_time || undefined,
        duration_minutes: this.virtualAttendanceData.duration_minutes
      }
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeVirtualAttendanceModal();
          this.loadSessionAttendance(this.selectedSession!);
        },
        error: err => {
          console.error('Error marking virtual attendance:', err);
          this.saving = false;
        }
      });
  }

  // ==========================================
  // QR DATA
  // ==========================================

  loadQRData(session: TrainingSession): void {
    this.liveTrainingService.getQRData(session.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.qrCodeData = {
            qr_token: data.qr_token,
            expires_at: data.expires_at ?? '',
          };
          this.showQRModal = true;
        },
        error: err => {
          console.error('Error loading QR data:', err);
          // Generate new QR if not found
          this.generateQRCode(session);
        }
      });
  }

  // ==========================================
  // VIDEO INTEGRATION MANAGEMENT
  // ==========================================

  openIntegrationModal(integration?: VideoIntegration): void {
    this.editingIntegration = integration ? { ...integration } : {
      platform: 'zoom',
      is_active: true
    };
    this.showIntegrationModal = true;
  }

  closeIntegrationModal(): void {
    this.showIntegrationModal = false;
    this.editingIntegration = {};
  }

  saveIntegration(): void {
    if (!this.editingIntegration.platform) return;

    this.saving = true;
    this.liveTrainingService.saveIntegration(this.editingIntegration)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeIntegrationModal();
          this.loadIntegrations();
        },
        error: err => {
          console.error('Error saving integration:', err);
          this.saving = false;
        }
      });
  }

  disconnectIntegration(integration: VideoIntegration): void {
    if (!confirm(`Disconnect ${integration.platform} integration?`)) return;

    this.liveTrainingService.disconnectIntegration(integration.platform)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadIntegrations(),
        error: err => console.error('Error disconnecting integration:', err)
      });
  }

  // ==========================================
  // TRACK BY FUNCTIONS
  // ==========================================

  trackByInstructorId(index: number, item: SessionInstructor): string {
    return String(item.id);
  }

  trackByIntegrationId(index: number, item: VideoIntegration): string {
    return item.integration_id;
  }
}
