import { decodeSessionBlob } from '../../core/utils/session-blob';
import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathAssignment,
  AssignmentCreateRequest
} from '../../interfaces/learning-path.interfaces';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { HttpClient } from '@angular/common/http';
import { GlobalComponent } from '../../global-component';

// Interfaces matching course enrollment pattern
interface Department {
  department_id: number;
  department_name: string;
  department_code: string;
  user_count: number;
}

interface Team {
  team_id: number;
  team_name: string;
  team_code: string;
  user_count: number;
}

interface Branch {
  branch_id: number;
  branch_name: string;
  branch_code: string;
  city: string;
  country: string;
  user_count: number;
}

interface User {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  department_name?: string;
  team_name?: string;
  branch_name?: string;
}

interface AssignmentPreview {
  success: boolean;
  path: { path_id: number; title: string };
  summary: {
    total_users: number;
    will_enroll: number;
    already_enrolled: number;
  };
  users_to_enroll: Array<{ user_id: number; email: string; name: string }>;
  users_already_enrolled: Array<{ user_id: number; email: string; name: string }>;
}

interface AssignmentResult {
  success: boolean;
  message: string;
  summary: {
    total_users: number;
    enrolled_count: number;
    skipped_count: number;
    failed_count: number;
  };
  enrolled: Array<{
    user_id: number;
    email: string;
    first_name: string;
    last_name: string;
    enrollment_id: number;
  }>;
  skipped: Array<{ user_id: number; email: string; name: string; reason: string }>;
  failed: Array<{ user_id: number; email: string; name: string; reason: string }>;
  path: { path_id: number; title: string };
}

interface AssignmentStatistics {
  total_enrollments: number;
  completed_enrollments: number;
  in_progress_enrollments: number;
  not_started_enrollments: number;
  overdue_enrollments: number;
  avg_progress: number;
  avg_completion_days: number;
}

type AssignmentType = 'department' | 'team' | 'branch' | 'users';

@Component({
  selector: 'app-learning-path-assignments',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, AdmintopComponent],
  templateUrl: './learning-path-assignments.component.html',
  styleUrls: ['./learning-path-assignments.component.scss']
})
export class LearningPathAssignmentsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private searchSubject$ = new Subject<string>();

  session_data: any = "";
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: { id: 0, name: "", status: "" },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  // Path data
  pathId!: number;
  path: LearningPath | null = null;
  assignments: LearningPathAssignment[] = [];

  // Reference data
  departments: Department[] = [];
  teams: Team[] = [];
  branches: Branch[] = [];
  users: User[] = [];

  // Selection State
  assignmentType: AssignmentType = 'users';
  selectedDepartmentId: number | null = null;
  selectedTeamId: number | null = null;
  selectedBranchId: number | null = null;
  selectedUsers: User[] = [];

  // Options
  isMandatory = false;
  dueDate: string = '';
  dueDaysFromAssignment: number | null = null;
  notifyUsers = true;
  sendReminder = true;
  priority: 'low' | 'normal' | 'high' | 'critical' = 'normal';
  assignmentNotes = '';

  // Search
  userSearch = '';

  // UI State
  loading = false;
  loadingUsers = false;
  loadingPreview = false;
  saving = false;

  showAssignModal = false;
  showPreviewModal = false;
  showResultModal = false;
  showStatsModal = false;

  // Results
  preview: AssignmentPreview | null = null;
  result: AssignmentResult | null = null;
  error: string | null = null;

  // Statistics
  selectedAssignment: LearningPathAssignment | null = null;
  assignmentStats: AssignmentStatistics | null = null;
  loadingStats = false;

  constructor(
    private route: ActivatedRoute,
    private learningPathService: LearningPathService,
    private http: HttpClient
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.pathId = Number(params['id']);
      this.loadPath();
      this.loadAssignments();
      this.loadInitialData();
    });
    this.setupSearch();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private getAuthParams(): any {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id
    };
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadPath(): void {
    this.learningPathService.getById(this.pathId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (path) => this.path = path,
        error: (err) => console.error('Error loading path:', err)
      });
  }

  loadAssignments(): void {
    this.loading = true;
    this.learningPathService.getAssignments(this.pathId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.assignments = response || [];
          this.loading = false;
        },
        error: () => this.loading = false
      });
  }

  private loadInitialData(): void {
    const authParams = this.getAuthParams();

    // Load departments
    this.http.get<any>(GlobalComponent.courseEnrollment, {
      params: { action: 'departments', ...authParams }
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => this.departments = res.data || [],
      error: () => this.departments = []
    });

    // Load teams
    this.http.get<any>(GlobalComponent.courseEnrollment, {
      params: { action: 'teams', ...authParams }
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => this.teams = res.data || [],
      error: () => this.teams = []
    });

    // Load branches
    this.http.get<any>(GlobalComponent.courseEnrollment, {
      params: { action: 'branches', ...authParams }
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => this.branches = res.data || [],
      error: () => this.branches = []
    });
  }

  private setupSearch(): void {
    this.searchSubject$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(search => {
      this.loadUsers(search);
    });
  }

  onUserSearchChange(): void {
    this.searchSubject$.next(this.userSearch);
  }

  private loadUsers(search?: string): void {
    this.loadingUsers = true;

    const authParams = this.getAuthParams();
    let params: any = { action: 'users', ...authParams };

    if (search) params.search = search;
    if (this.selectedDepartmentId) params.department_id = this.selectedDepartmentId;
    if (this.selectedTeamId) params.team_id = this.selectedTeamId;
    if (this.selectedBranchId) params.branch_id = this.selectedBranchId;

    this.http.get<any>(GlobalComponent.courseEnrollment, { params })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (res) => {
          this.users = res.data || [];
          this.loadingUsers = false;
        },
        error: () => {
          this.users = [];
          this.loadingUsers = false;
        }
      });
  }

  // ==========================================
  // ASSIGNMENT TYPE HANDLING
  // ==========================================

  onAssignmentTypeChange(): void {
    this.resetSelections();
    if (this.assignmentType === 'users') {
      this.loadUsers();
    }
  }

  onDepartmentChange(): void {
    if (this.assignmentType === 'users') {
      this.loadUsers();
    }
  }

  onTeamChange(): void {
    if (this.assignmentType === 'users') {
      this.loadUsers();
    }
  }

  onBranchChange(): void {
    if (this.assignmentType === 'users') {
      this.loadUsers();
    }
  }

  private resetSelections(): void {
    this.selectedDepartmentId = null;
    this.selectedTeamId = null;
    this.selectedBranchId = null;
    this.selectedUsers = [];
    this.users = [];
    this.userSearch = '';
  }

  // ==========================================
  // USER SELECTION
  // ==========================================

  toggleUserSelection(user: User): void {
    const index = this.selectedUsers.findIndex(u => u.id === user.id);
    if (index === -1) {
      this.selectedUsers.push(user);
    } else {
      this.selectedUsers.splice(index, 1);
    }
  }

  isUserSelected(user: User): boolean {
    return this.selectedUsers.some(u => u.id === user.id);
  }

  selectAllUsers(): void {
    this.selectedUsers = [...this.users];
  }

  deselectAllUsers(): void {
    this.selectedUsers = [];
  }

  removeSelectedUser(user: User): void {
    this.selectedUsers = this.selectedUsers.filter(u => u.id !== user.id);
  }

  // ==========================================
  // ASSIGNMENT PREVIEW
  // ==========================================

  canPreview(): boolean {
    switch (this.assignmentType) {
      case 'department':
        return !!this.selectedDepartmentId;
      case 'team':
        return !!this.selectedTeamId;
      case 'branch':
        return !!this.selectedBranchId;
      case 'users':
        return this.selectedUsers.length > 0;
      default:
        return false;
    }
  }

  getAssignmentSummary(): string {
    switch (this.assignmentType) {
      case 'department':
        const dept = this.departments.find(d => d.department_id === this.selectedDepartmentId);
        return `All users in ${dept?.department_name || 'selected department'} (${dept?.user_count || 0} users)`;
      case 'team':
        const team = this.teams.find(t => t.team_id === this.selectedTeamId);
        return `All users in ${team?.team_name || 'selected team'} (${team?.user_count || 0} users)`;
      case 'branch':
        const branch = this.branches.find(b => b.branch_id === this.selectedBranchId);
        return `All users in ${branch?.branch_name || 'selected branch'} (${branch?.user_count || 0} users)`;
      case 'users':
        return `${this.selectedUsers.length} selected user(s)`;
      default:
        return '';
    }
  }

  previewAssignment(): void {
    if (!this.canPreview()) return;

    this.loadingPreview = true;
    this.error = null;

    const request = this.buildAssignmentRequest();

    this.http.post<any>(GlobalComponent.learning_path, {
      ...request,
      action: 'preview-assignment'
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        this.preview = res.data;
        this.showPreviewModal = true;
        this.loadingPreview = false;
      },
      error: (err) => {
        this.error = err.error?.message || 'Failed to generate preview';
        this.loadingPreview = false;
      }
    });
  }

  // ==========================================
  // ASSIGNMENT EXECUTION
  // ==========================================

  confirmAssignment(): void {
    this.saving = true;
    this.error = null;

    const request = this.buildAssignmentRequest();

    this.learningPathService.createAssignment(request as AssignmentCreateRequest)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (res) => {
          this.result = {
            success: true,
            message: `Assignment created successfully`,
            summary: {
              total_users: this.preview?.summary.total_users || 0,
              enrolled_count: res.enrollments_created,
              skipped_count: this.preview?.summary.already_enrolled || 0,
              failed_count: 0
            },
            enrolled: this.preview?.users_to_enroll.map(u => ({
              user_id: u.user_id,
              email: u.email,
              first_name: u.name.split(' ')[0],
              last_name: u.name.split(' ').slice(1).join(' '),
              enrollment_id: 0
            })) || [],
            skipped: this.preview?.users_already_enrolled.map(u => ({
              user_id: u.user_id,
              email: u.email,
              name: u.name,
              reason: 'Already enrolled'
            })) || [],
            failed: [],
            path: { path_id: this.pathId, title: this.path?.title || '' }
          };
          this.showPreviewModal = false;
          this.showResultModal = true;
          this.saving = false;
        },
        error: (err) => {
          this.error = err.error?.message || 'Assignment failed';
          this.saving = false;
        }
      });
  }

  private buildAssignmentRequest(): any {
    const authParams = this.getAuthParams();

    const request: any = {
      path_id: this.pathId,
      assignment_type: this.assignmentType,
      is_mandatory: this.isMandatory,
      send_notification: this.notifyUsers,
      send_reminder: this.sendReminder,
      priority: this.priority,
      assignment_notes: this.assignmentNotes,
      ...authParams
    };

    if (this.dueDate) {
      request.due_date = this.dueDate;
    }

    if (this.dueDaysFromAssignment) {
      request.due_days_from_assignment = this.dueDaysFromAssignment;
    }

    switch (this.assignmentType) {
      case 'department':
        request.department_id = this.selectedDepartmentId;
        break;
      case 'team':
        request.team_id = this.selectedTeamId;
        break;
      case 'branch':
        request.branch_id = this.selectedBranchId;
        break;
      case 'users':
        request.user_ids = this.selectedUsers.map(u => u.id);
        break;
    }

    return request;
  }

  // ==========================================
  // MODAL HANDLING
  // ==========================================

  openAssignModal(): void {
    this.resetForm();
    this.showAssignModal = true;
  }

  closeAssignModal(): void {
    this.showAssignModal = false;
  }

  closePreviewModal(): void {
    this.showPreviewModal = false;
    this.preview = null;
  }

  closeResultModal(): void {
    this.showResultModal = false;
    this.result = null;
    this.resetForm();
    this.loadAssignments();
  }

  startNewAssignment(): void {
    this.closeResultModal();
    this.openAssignModal();
  }

  private resetForm(): void {
    this.assignmentType = 'users';
    this.selectedDepartmentId = null;
    this.selectedTeamId = null;
    this.selectedBranchId = null;
    this.selectedUsers = [];
    this.users = [];
    this.userSearch = '';
    this.isMandatory = false;
    this.dueDate = '';
    this.dueDaysFromAssignment = null;
    this.notifyUsers = true;
    this.sendReminder = true;
    this.priority = 'normal';
    this.assignmentNotes = '';
    this.error = null;
  }

  // ==========================================
  // ASSIGNMENT ACTIONS
  // ==========================================

  pauseAssignment(assignment: LearningPathAssignment): void {
    if (!confirm('Are you sure you want to pause this assignment?')) return;

    this.learningPathService.pauseAssignment(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadAssignments(),
        error: (err) => alert('Failed to pause assignment')
      });
  }

  resumeAssignment(assignment: LearningPathAssignment): void {
    this.learningPathService.resumeAssignment(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadAssignments(),
        error: (err) => alert('Failed to resume assignment')
      });
  }

  cancelAssignment(assignment: LearningPathAssignment): void {
    if (!confirm('Cancel this assignment? Existing enrollments will not be affected.')) return;

    this.learningPathService.cancelAssignment(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadAssignments(),
        error: (err) => alert('Failed to cancel assignment')
      });
  }

  viewStats(assignment: LearningPathAssignment): void {
    this.selectedAssignment = assignment;
    this.assignmentStats = null;
    this.loadingStats = true;
    this.showStatsModal = true;

    this.learningPathService.getAssignmentStatistics(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (stats) => {
          this.assignmentStats = stats;
          this.loadingStats = false;
        },
        error: () => this.loadingStats = false
      });
  }

  closeStatsModal(): void {
    this.showStatsModal = false;
    this.selectedAssignment = null;
    this.assignmentStats = null;
  }

  sendReminders(assignment: LearningPathAssignment): void {
    if (!confirm('Send reminder notifications to all incomplete enrollees?')) return;

    this.learningPathService.sendAssignmentReminders(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => alert(`Reminders sent to ${result.sent} user(s)`),
        error: () => alert('Failed to send reminders')
      });
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getPriorityBadge(priority: string): string {
    const badges: Record<string, string> = {
      low: 'bg-secondary',
      normal: 'bg-info',
      high: 'bg-warning text-dark',
      critical: 'bg-danger'
    };
    return badges[priority] || 'bg-secondary';
  }

  getStatusBadge(status: string): string {
    const badges: Record<string, string> = {
      active: 'bg-success',
      paused: 'bg-warning text-dark',
      completed: 'bg-primary',
      cancelled: 'bg-secondary'
    };
    return badges[status] || 'bg-secondary';
  }

  getAssignmentTypeLabel(type: string): string {
    const labels: Record<string, string> = {
      department: 'By Department',
      team: 'By Team',
      branch: 'By Branch',
      users: 'Selected Users'
    };
    return labels[type] || type;
  }

  getCompletionPercentage(): number {
    if (!this.assignmentStats || !this.assignmentStats.total_enrollments) return 0;
    return Math.round((this.assignmentStats.completed_enrollments / this.assignmentStats.total_enrollments) * 100);
  }
}
