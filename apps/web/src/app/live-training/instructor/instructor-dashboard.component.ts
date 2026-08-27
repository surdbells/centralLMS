// live-training/instructor/instructor-dashboard.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  LiveTrainingService,
  TrainingSession,
  SessionAttendance,
  SessionInstructor
} from '../live-training.service';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';

interface InstructorSession extends TrainingSession {
  training_title?: string;
  enrolled_count?: number;
  present_count?: number;
}

@Component({
  selector: 'app-instructor-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent],
  templateUrl: './instructor-dashboard.component.html',
  styleUrls: ['./instructor-dashboard.component.scss']

})
export class InstructorDashboardComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Data
  allSessions: InstructorSession[] = [];
  todaySessions: InstructorSession[] = [];
  upcomingSessions: InstructorSession[] = [];
  pastSessions: InstructorSession[] = [];
  activeSession: InstructorSession | null = null;
  attendanceRecords: SessionAttendance[] = [];

  // Stats
  totalLearners = 0;
  avgAttendance = 0;

  // UI State
  activeTab: 'today' | 'upcoming' | 'past' | 'attendance' = 'today';
  loading = true;
  selectedSessionId = '';
  selectedSessionForAttendance: InstructorSession | null = null;

  // QR Modal
  showQRModal = false;
  qrSession: InstructorSession | null = null;

  // Attendance Summary
  attendanceSummary = { present: 0, late: 0, absent: 0, excused: 0 };

  // Session instructors
  sessionInstructors: SessionInstructor[] = [];

  // Virtual Attendance Modal
  showVirtualModal = false;
  selectedAttendanceRecord: SessionAttendance | null = null;
  virtualDuration = 0;

  // Instructors Modal
  showInstructorsModal = false;

  constructor(private liveTrainingService: LiveTrainingService) {}

  ngOnInit(): void {
    this.loadData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadData(): void {
    this.loading = true;

    // Load upcoming sessions for instructor
    this.liveTrainingService.getUpcomingSessions(50)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: sessions => {
          this.allSessions = sessions as InstructorSession[];
          this.categorizeSessions();
          this.calculateStats();
          this.loading = false;
        },
        error: err => {
          console.error('Error loading sessions:', err);
          this.loading = false;
        }
      });
  }

  refreshData(): void {
    this.loadData();
  }

  categorizeSessions(): void {
    const today = new Date().toISOString().split('T')[0];
    const now = new Date();
    const weekFromNow = new Date();
    weekFromNow.setDate(weekFromNow.getDate() + 7);

    this.todaySessions = this.allSessions.filter(s => s.session_date === today);
    this.upcomingSessions = this.allSessions.filter(s => {
      const sessionDate = new Date(s.session_date);
      return sessionDate > now && sessionDate <= weekFromNow;
    });
    this.pastSessions = this.allSessions.filter(s => {
      const sessionDate = new Date(s.session_date);
      return sessionDate < now && s.session_date !== today;
    });

    // Check for active/ongoing session
    this.activeSession = this.allSessions.find(s => s.status === 'ongoing') || null;
  }

  calculateStats(): void {
    // Calculate total unique learners and average attendance
    let totalAttended = 0;
    let totalEnrolled = 0;

    this.pastSessions.forEach(s => {
      totalAttended += s.present_count || 0;
      totalEnrolled += s.enrolled_count || 0;
    });

    this.avgAttendance = totalEnrolled > 0 ? Math.round((totalAttended / totalEnrolled) * 100) : 0;
  }

  // Session Actions
  openSession(session: InstructorSession): void {
    // Navigate to session management view
    console.log('Opening session:', session);
  }

  viewSessionDetails(session: InstructorSession): void {
    console.log('Viewing session details:', session);
  }

  viewAttendance(session: InstructorSession): void {
    this.selectedSessionId = session.session_id.toString();
    this.selectedSessionForAttendance = session;
    this.activeTab = 'attendance';
    this.loadAttendance();
  }

  loadAttendance(session?: InstructorSession): void {
    // If session is provided, set it as selected
    if (session) {
      this.selectedSessionId = session.session_id.toString();
      this.selectedSessionForAttendance = session;
    }

    if (!this.selectedSessionId) {
      this.selectedSessionForAttendance = null;
      this.attendanceRecords = [];
      return;
    }

    const sessionToLoad = session || this.allSessions.find(s => s.session_id.toString() === this.selectedSessionId);
    this.selectedSessionForAttendance = sessionToLoad || null;

    if (sessionToLoad) {
      this.liveTrainingService.getSessionAttendance(sessionToLoad.session_id)
        .pipe(takeUntil(this.destroy$))
        .subscribe(records => {
          this.attendanceRecords = records;
          this.calculateAttendanceSummary();
        });
    }
  }

  calculateAttendanceSummary(): void {
    this.attendanceSummary = { present: 0, late: 0, absent: 0, excused: 0 };
    this.attendanceRecords.forEach(r => {
      const status = r.status as string;
      switch (status) {
        case 'present': this.attendanceSummary.present++; break;
        case 'late': this.attendanceSummary.late++; break;
        case 'absent': this.attendanceSummary.absent++; break;
        case 'excused': this.attendanceSummary.excused++; break;
      }
    });
  }

  markAttendance(record: SessionAttendance, status: string): void {
    if (!this.selectedSessionForAttendance) return;

    this.liveTrainingService.overrideAttendance(
      this.selectedSessionForAttendance.session_id,
      record.user_id,
      status,
      'Manual override by instructor'
    )
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        (record as any).status = status;
        this.calculateAttendanceSummary();
      });
  }

  lockAttendance(): void {
    if (!this.selectedSessionForAttendance) return;
    if (!confirm('Lock attendance? This action cannot be undone.')) return;

    this.liveTrainingService.lockAttendance(this.selectedSessionForAttendance.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        if (this.selectedSessionForAttendance) {
          this.selectedSessionForAttendance.attendance_locked = true;
        }
      });
  }

  exportAttendance(session: InstructorSession): void {
    this.liveTrainingService.exportAttendance(session.training_id!)
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => {
        // Download CSV
        const blob = new Blob([this.convertToCSV(data)], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `attendance_${session.session_id}.csv`;
        a.click();
      });
  }

  convertToCSV(data: any[]): string {
    if (data.length === 0) return '';
    const headers = Object.keys(data[0]);
    const rows = data.map(row => headers.map(h => row[h]).join(','));
    return [headers.join(','), ...rows].join('\n');
  }

  // QR Code
  showQRCode(session: InstructorSession): void {
    this.qrSession = session;
    this.showQRModal = true;
  }

  closeQRModal(): void {
    this.showQRModal = false;
    this.qrSession = null;
  }

  getQRCodeUrl(token: string | null | undefined): string {
    if (!token) return '';
    return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(token)}`;
  }

  downloadQR(): void {
    if (!this.qrSession || !this.qrSession.qr_code_token) return;
    const link = document.createElement('a');
    link.href = this.getQRCodeUrl(this.qrSession.qr_code_token);
    link.download = `qr_${this.qrSession.session_id}.png`;
    link.click();
  }

  regenerateQR(): void {
    if (!this.qrSession) return;
    this.liveTrainingService.generateQRCode(this.qrSession.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => {
        if (this.qrSession) {
          this.qrSession.qr_code_token = data.qr_token;
        }
      });
  }

  // Helpers
  getAttendancePercent(session: InstructorSession): number {
    if (!session.enrolled_count) return 0;
    return ((session.present_count || 0) / session.enrolled_count) * 100;
  }

  getSessionTypeBadge(type: string): string {
    switch (type) {
      case 'physical': return 'bg-success';
      case 'virtual': return 'bg-info';
      case 'hybrid': return 'bg-warning text-dark';
      default: return 'bg-secondary';
    }
  }

  getStatusBadge(status: string): string {
    switch (status) {
      case 'scheduled': return 'bg-info';
      case 'ongoing': return 'bg-warning text-dark';
      case 'completed': return 'bg-success';
      case 'cancelled': return 'bg-danger';
      default: return 'bg-secondary';
    }
  }

  getAttendanceStatusBadge(status: string): string {
    switch (status) {
      case 'present': return 'bg-success';
      case 'late': return 'bg-warning text-dark';
      case 'absent': return 'bg-danger';
      case 'excused': return 'bg-secondary';
      default: return 'bg-black text-white';
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

  formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
  }

  formatTime(time: string): string {
    return time?.substring(0, 5) || '';
  }

  formatDateTime(datetime: string): string {
    return new Date(datetime).toLocaleString();
  }

  getInitials(firstName: string | undefined, lastName: string | undefined): string {
    return `${firstName?.charAt(0) || ''}${lastName?.charAt(0) || ''}`.toUpperCase();
  }

  trackBySessionId(index: number, item: InstructorSession): string {
    return item.session_id;
  }

  trackByAttendanceId(index: number, item: SessionAttendance): string {
    return item.attendance_id;
  }

  // ==========================================
  // LOAD SINGLE SESSION
  // ==========================================

  loadSessionDetails(session_id: string): void {
    this.loading = true;
    this.liveTrainingService.getSession(session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: session => {
          const instructorSession = session as InstructorSession;
          // Find and update in all sessions
          const idx = this.allSessions.findIndex(s => s.session_id === session_id);
          if (idx !== -1) {
            this.allSessions[idx] = instructorSession;
          }
          this.loading = false;
        },
        error: err => {
          console.error('Error loading session:', err);
          this.loading = false;
        }
      });
  }

  // ==========================================
  // INSTRUCTOR MANAGEMENT
  // ==========================================

  loadSessionInstructors(session_id: string): void {
    this.liveTrainingService.getSessionInstructors(session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: instructors => {
          this.sessionInstructors = instructors;
          this.showInstructorsModal = true;
        },
        error: err => console.error('Error loading instructors:', err)
      });
  }

  // ==========================================
  // VIRTUAL ATTENDANCE MODAL
  // ==========================================

  openVirtualAttendanceForm(record: SessionAttendance): void {
    this.selectedAttendanceRecord = record;
    this.virtualDuration = 0;
    this.showVirtualModal = true;
  }

  closeVirtualModal(): void {
    this.showVirtualModal = false;
    this.selectedAttendanceRecord = null;
    this.virtualDuration = 0;
  }

  submitVirtualAttendance(): void {
    if (!this.selectedSessionForAttendance || !this.selectedAttendanceRecord || !this.virtualDuration) return;

    this.liveTrainingService.markAttendanceVirtual(
      this.selectedSessionForAttendance.session_id,
      this.selectedAttendanceRecord.user_id,
      { duration_minutes: this.virtualDuration }
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.closeVirtualModal();
          this.loadAttendance(this.selectedSessionForAttendance!);
        },
        error: err => console.error('Error marking virtual attendance:', err)
      });
  }

  // ==========================================
  // VIRTUAL ATTENDANCE
  // ==========================================

  markVirtualAttendance(session_id: string, learner_id: string, durationMinutes: number): void {
    this.liveTrainingService.markAttendanceVirtual(session_id, learner_id, {
      duration_minutes: durationMinutes
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadAttendance(this.selectedSessionForAttendance!);
        },
        error: err => console.error('Error marking virtual attendance:', err)
      });
  }

  // ==========================================
  // QR DATA
  // ==========================================

  loadQRData(session: InstructorSession): void {
    this.liveTrainingService.getQRData(session.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          session.qr_code_token = data.qr_token;
          this.qrSession = session;
          this.showQRModal = true;
        },
        error: () => {
          // If no QR data, generate new one
          this.generateNewQR(session);
        }
      });
  }

  generateNewQR(session: InstructorSession): void {
    this.liveTrainingService.generateQRCode(session.session_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          session.qr_code_token = data.qr_token;
          this.qrSession = session;
          this.showQRModal = true;
        },
        error: err => console.error('Error generating QR:', err)
      });
  }

  trackByInstructorId(index: number, item: SessionInstructor): string {
    return String(item.id);
  }
}
