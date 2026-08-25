import { decodeSessionBlob } from '../../core/utils/session-blob';
// training-enrollment/training-enrollment.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import {
  TrainingEnrollmentService,
  Department,
  Team,
  Branch,
  User,
  Training,
  EnrollmentRequest,
  EnrollmentPreview,
  EnrollmentResult
} from './training-enrollment.service';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';

type EnrollmentType = 'department' | 'team' | 'branch' | 'users';

@Component({
  selector: 'app-training-enrollment',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent],
  templateUrl: './training-enrollment.component.html',
  styleUrls: ['./training-enrollment.component.scss']
})
export class TrainingEnrollmentComponent implements OnInit, OnDestroy {
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
  trainings: Training[] = [];

  // Selection State
  selectedTraining: Training | null = null;
  enrollmentType: EnrollmentType = 'users';
  selectedDepartmentId: number | null = null;
  selectedTeamId: number | null = null;
  selectedBranchId: number | null = null;
  selectedUsers: User[] = [];

  // Options
  isMandatory = false;
  notifyUsers = true;

  // Search
  userSearch = '';
  trainingSearch = '';

  // UI State
  loading = false;
  loadingUsers = false;
  loadingPreview = false;
  enrolling = false;

  showTrainingSelector = true;
  showPreviewModal = false;
  showResultModal = false;

  // Results
  preview: EnrollmentPreview | null = null;
  result: EnrollmentResult | null = null;
  error: string | null = null;

  constructor(private enrollmentService: TrainingEnrollmentService) {}

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

    // Load teams
    this.enrollmentService.getTeams()
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.teams = data);

    // Load branches
    this.enrollmentService.getBranches()
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => this.branches = data);

    // Load trainings
    this.enrollmentService.getTrainings()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.trainings = data;
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
    this.loadingUsers = true;

    const filters: any = {};
    if (search) filters.search = search;
    if (this.selectedDepartmentId) filters.department_id = this.selectedDepartmentId;
    if (this.selectedTeamId) filters.team_id = this.selectedTeamId;
    if (this.selectedBranchId) filters.branch_id = this.selectedBranchId;

    this.enrollmentService.getUsers(filters)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.users = data;
          this.loadingUsers = false;
        },
        error: () => this.loadingUsers = false
      });
  }

  // ==========================================
  // TRAINING SELECTION
  // ==========================================

  selectTraining(training: Training): void {
    this.selectedTraining = training;
    this.showTrainingSelector = false;
    this.resetSelections();
  }

  changeTraining(): void {
    this.selectedTraining = null;
    this.showTrainingSelector = true;
    this.resetSelections();
  }

  filterTrainings(): Training[] {
    if (!this.trainingSearch.trim()) return this.trainings;
    const search = this.trainingSearch.toLowerCase();
    return this.trainings.filter(t =>
      t.title.toLowerCase().includes(search) ||
      t.training_type?.toLowerCase().includes(search)
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
  // ENROLLMENT PREVIEW
  // ==========================================

  canPreview(): boolean {
    if (!this.selectedTraining) return false;

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
    if (!this.selectedTraining) return '';

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
    if (!this.canPreview() || !this.selectedTraining) return;

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
    if (!this.selectedTraining) return;

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
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;

    const request: EnrollmentRequest = {
      training_id: this.selectedTraining!.training_id,
      enrollment_type: this.enrollmentType,
      is_mandatory: this.isMandatory,
      notify_users: this.notifyUsers,
      user_id: this.user_session.id,
      token: this.user_session.token,
      tenant_id: this.user_session.tenant_id
    };

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
        request.user_ids = this.selectedUsers.map(u => u.id);
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
    this.changeTraining();
  }

  startNewEnrollment(): void {
    this.closeResultModal();
  }
}
