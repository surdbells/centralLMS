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
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { LearningPathEnrollmentService } from '../../services/learning-path-enrollment.service';
import type {
  Branch,
  Department,
  EnrollmentRequest,
  Team,
  User,
} from '../../interfaces/learning-path-enrollment.interfaces';

// Department, Team, Branch and User are imported rather than declared
// again — this file had its own copies of all four, differing only in
// which fields somebody happened to need on the day.

interface AssignmentPreview {
  success: boolean;
  path: { path_id: string; title: string };
  summary: {
    total_users: number;
    will_enroll: number;
    already_enrolled: number;
  };
  users_to_enroll: Array<{ user_id: string; email: string; name: string }>;
  users_already_enrolled: Array<{ user_id: string; email: string; name: string }>;
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
    user_id: string;
    email: string;
    first_name: string;
    last_name: string;
    enrollment_id: string;
  }>;
  skipped: Array<{ user_id: string; email: string; name: string; reason: string }>;
  failed: Array<{ user_id: string; email: string; name: string; reason: string }>;
  path: { path_id: string; title: string };
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
  imports: [CommonModule, FormsModule, RouterModule, AdmintopComponent, TranslatePipe],
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
  pathId!: string;
  path: LearningPath | null = null;
  assignments: LearningPathAssignment[] = [];

  // Reference data
  departments: Department[] = [];
  teams: Team[] = [];
  branches: Branch[] = [];
  users: User[] = [];

  // Selection State
  assignmentType: AssignmentType = 'users';
  selectedDepartmentId: string | null = null;
  selectedTeamId: string | null = null;
  selectedBranchId: string | null = null;
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
    // The same service the enrolment screen uses. This component had its
    // own copies of "list the departments", "list the users" and "preview
    // what this would do", each a direct call to the legacy endpoint.
    private enrollmentService: LearningPathEnrollmentService,
    private i18n: I18nService,
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.pathId = String(params['id']);
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
    this.enrollmentService.getDepartments().pipe(takeUntil(this.destroy$)).subscribe({
      next: (departments) => this.departments = departments,
      error: () => this.departments = [],
    });

    this.enrollmentService.getTeams().pipe(takeUntil(this.destroy$)).subscribe({
      next: (teams) => this.teams = teams,
      error: () => this.teams = [],
    });

    this.enrollmentService.getBranches().pipe(takeUntil(this.destroy$)).subscribe({
      next: (branches) => this.branches = branches,
      error: () => this.branches = [],
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

    this.enrollmentService
      .getUsers({
        search: search === '' ? undefined : search,
        department_id: this.selectedDepartmentId ?? undefined,
        team_id: this.selectedTeamId ?? undefined,
        branch_id: this.selectedBranchId ?? undefined,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (users) => {
          this.users = users;
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
    const index = this.selectedUsers.findIndex(u => u.user_id === user.user_id);
    if (index === -1) {
      this.selectedUsers.push(user);
    } else {
      this.selectedUsers.splice(index, 1);
    }
  }

  isUserSelected(user: User): boolean {
    return this.selectedUsers.some(u => u.user_id === user.user_id);
  }

  selectAllUsers(): void {
    this.selectedUsers = [...this.users];
  }

  deselectAllUsers(): void {
    this.selectedUsers = [];
  }

  removeSelectedUser(user: User): void {
    this.selectedUsers = this.selectedUsers.filter(u => u.user_id !== user.user_id);
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
        return this.i18n.t('paths_admin.assign.summary_group', {
          name: dept?.department_name || this.i18n.t('paths_admin.assign.selected_department'),
          count: dept?.user_count || 0,
        });
      case 'team':
        const team = this.teams.find(t => t.team_id === this.selectedTeamId);
        return this.i18n.t('paths_admin.assign.summary_group', {
          name: team?.team_name || this.i18n.t('paths_admin.assign.selected_team'),
          count: team?.user_count || 0,
        });
      case 'branch':
        const branch = this.branches.find(b => b.branch_id === this.selectedBranchId);
        return this.i18n.t('paths_admin.assign.summary_group', {
          name: branch?.branch_name || this.i18n.t('paths_admin.assign.selected_branch'),
          count: branch?.user_count || 0,
        });
      case 'users':
        return this.i18n.t('paths_admin.assign.summary_users', { count: this.selectedUsers.length });
      default:
        return '';
    }
  }

  previewAssignment(): void {
    if (!this.canPreview()) return;

    this.loadingPreview = true;
    this.error = null;

    // The same preview the enrolment screen shows, computed the same way:
    // a diff between who is chosen and who is already on the path. This
    // screen had its own round trip for it, which is a second answer to
    // the same question.
    this.enrollmentService
      .previewEnrollment(this.buildEnrollmentRequest())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (preview) => {
          this.preview = preview as unknown as AssignmentPreview;
          this.showPreviewModal = true;
          this.loadingPreview = false;
        },
        error: (err: { message?: string }) => {
          this.error = err.message ?? this.i18n.t('paths_admin.assign.preview_error');
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

    // A standing rule, not a one-off enrolment. It enrols everybody it covers
    // now — which is what this button has always appeared to do — and keeps
    // enrolling people who join the group afterwards, which is what the word
    // "assignment" on this screen was always meant to mean.
    this.learningPathService
      .createAssignment(this.buildAssignmentRequest())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (res) => {
          this.result = {
            success: true,
            message: this.i18n.t('paths_admin.assign.created_success'),
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
              enrollment_id: ''
            })) || [],
            skipped: this.preview?.users_already_enrolled.map(u => ({
              user_id: u.user_id,
              email: u.email,
              name: u.name,
              reason: this.i18n.t('paths_admin.assign.reason_already_enrolled')
            })) || [],
            failed: [],
            path: { path_id: this.pathId, title: this.path?.title || '' }
          };
          this.showPreviewModal = false;
          this.showResultModal = true;
          this.saving = false;
        },
        error: (err: { message?: string }) => {
          // The API says what went wrong. `err.error.message` is the legacy
          // envelope's shape, and an ApiError has no `error`, so every
          // failure read as a flat "Assignment failed".
          this.error = err.message ?? this.i18n.t('paths_admin.assign.enroll_error');
          this.saving = false;
        }
      });
  }

  /**
   * The choices on the form, as a standing rule.
   *
   * A rule names a group — "Operations", "the London branch" — rather than
   * the people who happened to be in it this afternoon, which is what lets it
   * keep enrolling newcomers. Picking people by hand is the one case that is
   * a list rather than a description, and the API calls that kind `custom`.
   */
  private buildAssignmentRequest(): AssignmentCreateRequest {
    const request: AssignmentCreateRequest = {
      path_id: this.pathId,
      assignment_type: this.assignmentType === 'users' ? 'user' : this.assignmentType,
      is_mandatory: this.isMandatory,
      send_notification: this.notifyUsers,
      send_reminder: this.sendReminder,
      priority: this.priority,
      assignment_notes: this.assignmentNotes,
    };

    // A fixed date or a number of days, never both: two answers to "when is
    // this due" is one too many, and the API refuses it.
    if (this.dueDate) {
      request.due_date = this.dueDate;
    } else if (this.dueDaysFromAssignment) {
      request.due_days_from_assignment = this.dueDaysFromAssignment;
    }

    switch (this.assignmentType) {
      case 'department':
        request.department_id = this.selectedDepartmentId ?? undefined;
        break;
      case 'team':
        request.team_id = this.selectedTeamId ?? undefined;
        break;
      case 'branch':
        request.branch_id = this.selectedBranchId ?? undefined;
        break;
      case 'users':
        // Everybody who was ticked, not just the first: the form lets a
        // list be chosen and a rule built from one of them is not the rule
        // anybody made.
        request.user_ids = this.selectedUsers.map((u) => String(u.user_id));
        break;
    }

    return request;
  }

  /**
   * The same choices, as a one-off enrolment.
   *
   * Still used for the preview, which asks "who would this cover and who is
   * already on the path" — the same question the enrolment screen asks, and
   * answered once rather than twice.
   *
   * No `token`, `user_id` or `tenant_id`: those came from
   * `atob(sessionStorage.SESSION)` and were posted with the request, which
   * let the client name the organisation being enrolled into.
   */
  private buildEnrollmentRequest(): EnrollmentRequest {
    const request: EnrollmentRequest = {
      path_id: this.pathId,
      enrollment_type: this.assignmentType as EnrollmentRequest['enrollment_type'],
      is_mandatory: this.isMandatory,
      notify_users: this.notifyUsers,
      priority: this.priority,
    };

    if (this.dueDate) {
      request.due_date = this.dueDate;
    }

    switch (this.assignmentType) {
      case 'department':
        request.department_id = this.selectedDepartmentId ?? undefined;
        break;
      case 'team':
        request.team_id = this.selectedTeamId ?? undefined;
        break;
      case 'branch':
        request.branch_id = this.selectedBranchId ?? undefined;
        break;
      case 'users':
        request.user_ids = this.selectedUsers.map((u) => String(u.user_id));
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
    if (!confirm(this.i18n.t('paths_admin.assign.confirm_pause'))) return;

    this.learningPathService.pauseAssignment(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadAssignments(),
        error: (err) => alert(this.i18n.t('paths_admin.assign.pause_failed'))
      });
  }

  resumeAssignment(assignment: LearningPathAssignment): void {
    this.learningPathService.resumeAssignment(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadAssignments(),
        error: (err) => alert(this.i18n.t('paths_admin.assign.resume_failed'))
      });
  }

  cancelAssignment(assignment: LearningPathAssignment): void {
    if (!confirm(this.i18n.t('paths_admin.assign.confirm_cancel'))) return;

    this.learningPathService.cancelAssignment(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadAssignments(),
        error: (err) => alert(this.i18n.t('paths_admin.assign.cancel_failed'))
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
          this.assignmentStats = stats as AssignmentStatistics;
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
    if (!confirm(this.i18n.t('paths_admin.assign.confirm_reminders'))) return;

    this.learningPathService.sendAssignmentReminders(assignment.assignment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => alert(this.i18n.t('paths_admin.assign.reminders_sent', { count: result.sent })),
        error: () => alert(this.i18n.t('paths_admin.assign.reminders_failed'))
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
      department: this.i18n.t('paths_admin.by_department'),
      team: this.i18n.t('paths_admin.by_team'),
      branch: this.i18n.t('paths_admin.by_branch'),
      users: this.i18n.t('paths_admin.selected_users')
    };
    return labels[type] || type;
  }

  getCompletionPercentage(): number {
    if (!this.assignmentStats || !this.assignmentStats.total_enrollments) return 0;
    return Math.round((this.assignmentStats.completed_enrollments / this.assignmentStats.total_enrollments) * 100);
  }
}
