// learning-path-enrollment/learning-path-enrollment.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import {
  LearningPathEnrollmentService
} from '../../services/learning-path-enrollment.service';
import {
  Department,
  Team,
  Branch,
  User,
  LearningPathBasic,
  EnrollmentRequest,
  EnrollmentPreview,
  EnrollmentResult,
  EnrollmentStats
} from '../../interfaces/learning-path-enrollment.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';

type EnrollmentType = 'department' | 'team' | 'branch' | 'users';

@Component({
  selector: 'app-learning-path-enrollment',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent],
  templateUrl: './learning-path-enrollments.component.html',
  styleUrls: ['./learning-path-enrollments.component.scss']
})
export class LearningPathEnrollmentComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private searchSubject$ = new Subject<string>();

  session_data: any = "";
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
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

  // Data
  departments: Department[] = [];
  teams: Team[] = [];
  branches: Branch[] = [];
  users: User[] = [];
  learningPaths: LearningPathBasic[] = [];

  // Selection State
  selectedPath: LearningPathBasic | null = null;
  enrollmentType: EnrollmentType = 'users';
  selectedDepartmentId: number | null = null;
  selectedTeamId: number | null = null;
  selectedBranchId: number | null = null;
  selectedUsers: User[] = [];

  // Options
  isMandatory = false;
  dueDate: string = '';
  notifyUsers = true;
  priority: 'low' | 'normal' | 'high' | 'critical' = 'normal';

  // Search
  userSearch = '';
  pathSearch = '';

  // UI State
  loading = false;
  loadingUsers = false;
  loadingPreview = false;
  enrolling = false;

  showPathSelector = true;
  showPreviewModal = false;
  showResultModal = false;

  // Results
  preview: EnrollmentPreview | null = null;
  result: EnrollmentResult | null = null;
  stats: EnrollmentStats | null = null;
  error: string | null = null;

  constructor(private enrollmentService: LearningPathEnrollmentService) {}

  ngOnInit(): void {
    this.loadInitialData();
    this.setupSearch();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  private loadInitialData(): void {
    this.loading = true;

    // Load departments
    this.enrollmentService.getDepartments()
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.departments = data);

    // Load teams (teams are independent of departments in this schema)
    this.enrollmentService.getTeams()
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.teams = data);

    // Load branches
    this.enrollmentService.getBranches()
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.branches = data);

    // Load learning paths
    this.enrollmentService.getLearningPaths()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.learningPaths = data;
          this.loading = false;
        },
        error: () => this.loading = false
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
    if (!this.selectedPath) return;

    this.loadingUsers = true;

    const filters: any = {
      path_id: this.selectedPath.path_id
    };
    if (search) filters.search = search;
    if (this.selectedDepartmentId) filters.department_id = this.selectedDepartmentId;
    if (this.selectedTeamId) filters.team_id = this.selectedTeamId;
    if (this.selectedBranchId) filters.branch_id = this.selectedBranchId;

    this.enrollmentService.getUnenrolledUsers(filters)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.users = data;
          console.log(data);
          this.loadingUsers = false;
        },
        error: () => this.loadingUsers = false
      });
  }

  loadTeams(): void {
    this.enrollmentService.getTeams()
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.teams = data);
  }

  private loadPathStats(): void {
    if (!this.selectedPath) return;

    this.enrollmentService.getPathStats(this.selectedPath.path_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(stats => this.stats = stats);
  }

  // ==========================================
  // PATH SELECTION
  // ==========================================

  selectPath(path: LearningPathBasic): void {
    this.selectedPath = path;
    this.showPathSelector = false;
    this.resetSelections();
    this.loadPathStats();
  }

  changePath(): void {
    this.selectedPath = null;
    this.showPathSelector = true;
    this.stats = null;
    this.resetSelections();
  }

  filterPaths(): LearningPathBasic[] {
    if (!this.pathSearch.trim()) return this.learningPaths;
    const search = this.pathSearch.toLowerCase();
    return this.learningPaths.filter(p =>
      p.title.toLowerCase().includes(search) ||
      p.short_description?.toLowerCase().includes(search)
    );
  }

  // ==========================================
  // ENROLLMENT TYPE HANDLING
  // ==========================================

  onEnrollmentTypeChange(): void {
    this.resetSelections();

    if (this.enrollmentType === 'users') {
      this.loadUsers();
    }
  }

  onDepartmentChange(): void {
    if (this.enrollmentType === 'users') {
      this.loadUsers();
    }
  }

  onTeamChange(): void {
    if (this.enrollmentType === 'users') {
      this.loadUsers();
    }
  }

  onBranchChange(): void {
    if (this.enrollmentType === 'users') {
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
  // ENROLLMENT PREVIEW
  // ==========================================

  canPreview(): boolean {
    if (!this.selectedPath) return false;

    switch (this.enrollmentType) {
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

  getEnrollmentSummary(): string {
    if (!this.selectedPath) return '';

    switch (this.enrollmentType) {
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

  previewEnrollment(): void {
    if (!this.canPreview() || !this.selectedPath) return;

    this.loadingPreview = true;
    this.error = null;

    const request = this.buildEnrollmentRequest();

    this.enrollmentService.previewEnrollment(request)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (preview) => {
          this.preview = preview;
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
  // ENROLLMENT EXECUTION
  // ==========================================

  confirmEnrollment(): void {
    if (!this.selectedPath) return;

    this.enrolling = true;
    this.error = null;

    const request = this.buildEnrollmentRequest();

    this.enrollmentService.enrollUsers(request)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.result = result;
          this.showPreviewModal = false;
          this.showResultModal = true;
          this.enrolling = false;
        },
        error: (err) => {
          this.error = err.error?.message || 'Enrollment failed';
          this.enrolling = false;
        }
      });
  }

  private buildEnrollmentRequest(): EnrollmentRequest {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
    const request: EnrollmentRequest = {
      path_id: this.selectedPath!.path_id,
      enrollment_type: this.enrollmentType,
      is_mandatory: this.isMandatory,
      notify_users: this.notifyUsers,
      priority: this.priority,
      user_id: this.user_session.id,
      token: this.user_session.token,
      tenant_id: this.user_session.tenant_id
    };

    if (this.dueDate) {
      request.due_date = this.dueDate;
    }

    switch (this.enrollmentType) {
      case 'department':
        request.department_id = this.selectedDepartmentId!;
        break;
      case 'team':
        request.team_id = this.selectedTeamId!;
        break;
      case 'branch':
        request.branch_id = this.selectedBranchId!;
        break;
      case 'users':
        request.user_ids = this.selectedUsers.map(u => u.user_id);
        break;
    }

    return request;
  }

  // ==========================================
  // MODAL HANDLING
  // ==========================================

  closePreviewModal(): void {
    this.showPreviewModal = false;
    this.preview = null;
  }

  closeResultModal(): void {
    this.showResultModal = false;
    this.result = null;
    this.resetSelections();
    this.changePath();
  }

  startNewEnrollment(): void {
    this.closeResultModal();
  }

  // ==========================================
  // UTILITY METHODS
  // ==========================================

  getSkillLevelBadge(level: string): string {
    const badges: Record<string, string> = {
      'beginner': 'bg-success',
      'intermediate': 'bg-info',
      'advanced': 'bg-warning text-dark',
      'expert': 'bg-danger',
      'all_levels': 'bg-secondary'
    };
    return badges[level] || 'bg-secondary';
  }

  getPathTypeBadge(type: string): string {
    const badges: Record<string, string> = {
      'sequential': 'bg-primary',
      'flexible': 'bg-info',
      'milestone': 'bg-warning text-dark'
    };
    return badges[type] || 'bg-secondary';
  }

  getDepartmentUserCount(): number {
    const dept = this.departments.find(d => d.department_id === this.selectedDepartmentId);
    return dept?.user_count || 0;
  }

  getTeamUserCount(): number {
    const team = this.teams.find(t => t.team_id === this.selectedTeamId);
    return team?.user_count || 0;
  }

  getBranchUserCount(): number {
    const branch = this.branches.find(b => b.branch_id === this.selectedBranchId);
    return branch?.user_count || 0;
  }

  formatDuration(hours: number | null): string {
    if (!hours) return 'N/A';
    if (hours < 1) return `${Math.round(hours * 60)} min`;
    return `${hours} hr${hours > 1 ? 's' : ''}`;
  }

  getMinDueDate(): string {
    const today = new Date();
    return today.toISOString().split('T')[0];
  }
}
