// training-enrollment/training-enrollment.component.ts

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { Router } from '@angular/router';
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
import { LiveTrainingService } from '../live-training.service';
import type { EnrollmentRule } from '../live-training.interfaces';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';

type EnrollmentType = 'department' | 'team' | 'branch' | 'users';

@Component({
  selector: 'app-training-enrollment',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, TranslatePipe],
  templateUrl: './training-enrollment.component.html',
  styleUrls: ['./training-enrollment.component.scss']
})
export class TrainingEnrollmentComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);
  private readonly trainings$ = inject(LiveTrainingService);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);

  private destroy$ = new Subject<void>();
  private searchSubject$ = new Subject<string>();

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

  /**
   * Whether this should keep going.
   *
   * Ticked, the choice becomes a standing rule: whoever joins the department
   * next month is enrolled too. Unticked, it enrols the people who are in it
   * today and stops there. Only a group can be a rule — a hand-picked list of
   * five people is already the list, and there is nothing for a rule to keep
   * matching.
   */
  makeStandingRule = false;

  /** What the last rule run did, shown beside the list. */
  ruleMessage: string | null = null;

  // Standing rules on the selected programme
  rules: EnrollmentRule[] = [];
  loadingRules = false;

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
    this.loadRules();
  }

  changeTraining(): void {
    this.selectedTraining = null;
    this.showTrainingSelector = true;
    this.rules = [];
    this.resetSelections();
  }

  // ==========================================
  // STANDING RULES
  // ==========================================

  /** Only a group can be a rule; a hand-picked list is already the list. */
  canBeStandingRule(): boolean {
    return this.enrollmentType !== 'users';
  }

  loadRules(): void {
    if (!this.selectedTraining) return;

    this.loadingRules = true;
    this.trainings$.getEnrollmentRules(this.selectedTraining.training_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (rules) => {
          this.rules = rules;
          this.loadingRules = false;
        },
        error: () => {
          this.rules = [];
          this.loadingRules = false;
        }
      });
  }

  ruleTarget(rule: EnrollmentRule): string {
    const criteria = rule.target_criteria ?? {};
    const named = (ids: unknown[] | undefined, list: Array<Record<string, unknown>>,
                   idKey: string, nameKey: string): string[] =>
      (ids ?? []).map((id) => {
        const found = list.find((row) => String(row[idKey]) === String(id));
        return found ? String(found[nameKey]) : String(id);
      });

    switch (rule.enrollment_type) {
      case 'department':
        return named(criteria.department_ids, this.departments as unknown as Array<Record<string, unknown>>,
          'department_id', 'department_name').join(', ');
      case 'team':
        return named(criteria.team_ids, this.teams as unknown as Array<Record<string, unknown>>,
          'team_id', 'team_name').join(', ');
      case 'branch':
        return named(criteria.branch_ids, this.branches as unknown as Array<Record<string, unknown>>,
          'branch_id', 'branch_name').join(', ');
      case 'direct':
        return this.i18n.t('live.enroll.chosen_people', { count: (criteria.user_ids ?? []).length });
      default:
        return this.i18n.t('live.enroll.everybody');
    }
  }

  toggleRule(rule: EnrollmentRule): void {
    if (!rule.rule_id) return;

    // Pausing stops it enrolling anybody new. It does not take the programme
    // away from the people it already enrolled.
    const change = rule.is_active
      ? this.trainings$.pauseEnrollmentRule(rule.rule_id)
      : this.trainings$.resumeEnrollmentRule(rule.rule_id);

    change.pipe(takeUntil(this.destroy$)).subscribe({ next: () => this.loadRules() });
  }

  runRule(rule: EnrollmentRule): void {
    if (!rule.rule_id) return;

    this.trainings$.applyEnrollmentRule(rule.rule_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.error = null;
          this.ruleMessage = result.enrolled === 0
            ? this.i18n.t('live.enroll.nobody_new')
            : this.i18n.t('live.enroll.newly_enrolled', { count: result.enrolled });
          this.loadRules();
        },
        error: (err) => this.error = err?.message ?? this.i18n.t('live.enroll.rule_run_failed')
      });
  }

  async deleteRule(rule: EnrollmentRule): Promise<void> {
    if (!rule.rule_id) return;
    if (!(await this.dialog.confirm(this.i18n.t('live.enroll.delete_rule_confirm'), { type: 'danger' }))) return;

    this.trainings$.deleteEnrollmentRule(rule.rule_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadRules() });
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
        return this.i18n.t('live.enroll.summary_department', {
          name: dept?.department_name || this.i18n.t('live.enroll.selected_department'),
          count: dept?.user_count || 0
        });
      case 'team':
        const team = this.teams.find(t => t.team_id === this.selectedTeamId);
        return this.i18n.t('live.enroll.summary_team', {
          name: team?.team_name || this.i18n.t('live.enroll.selected_team'),
          count: team?.user_count || 0
        });
      case 'branch':
        const branch = this.branches.find(b => b.branch_id === this.selectedBranchId);
        return this.i18n.t('live.enroll.summary_branch', {
          name: branch?.branch_name || this.i18n.t('live.enroll.selected_branch'),
          count: branch?.user_count || 0
        });
      case 'users':
        return this.i18n.t('live.enroll.summary_users', { count: this.selectedUsers.length });
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
          this.error = err.error?.message || this.i18n.t('live.enroll.preview_failed');
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

    if (this.makeStandingRule && this.canBeStandingRule()) {
      this.saveStandingRule();
      return;
    }

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
          this.error = err.error?.message || this.i18n.t('live.enroll.enroll_failed');
          this.enrolling = false;
        }
      });
  }

  /**
   * Saves the choice as a standing rule, and runs it once.
   *
   * A rule that only took effect at the next sweep would look broken to
   * whoever just made it, so the count on the result screen is what it
   * actually enrolled just now.
   */
  private saveStandingRule(): void {
    const training = this.selectedTraining!;

    this.trainings$
      .bulkEnrollByRule(training.training_id, this.buildRule(training.training_id))
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.result = {
            success: true,
            message: this.i18n.t('live.enroll.rule_created'),
            summary: {
              total_users: result.enrolled,
              enrolled_count: result.enrolled,
              waitlisted_count: 0,
              already_enrolled_count: 0,
              failed_count: 0,
            },
          } as unknown as EnrollmentResult;
          this.showPreviewModal = false;
          this.showResultModal = true;
          this.enrolling = false;
          this.loadRules();
        },
        error: (err: { message?: string }) => {
          this.error = err.message ?? this.i18n.t('live.enroll.rule_save_failed');
          this.enrolling = false;
        }
      });
  }

  private buildRule(trainingId: string): EnrollmentRule {
    const rule: EnrollmentRule = {
      training_id: trainingId,
      enrollment_type: this.enrollmentType === 'users' ? 'direct' : this.enrollmentType,
      target_criteria: {},
    };

    switch (this.enrollmentType) {
      case 'department':
        rule.target_criteria.department_ids = [this.selectedDepartmentId!];
        break;
      case 'team':
        rule.target_criteria.team_ids = [this.selectedTeamId!];
        break;
      case 'branch':
        rule.target_criteria.branch_ids = [this.selectedBranchId!];
        break;
      case 'users':
        rule.target_criteria.user_ids = this.selectedUsers.map((u) => String(u.id));
        break;
    }

    return rule;
  }

  /**
   * What to enrol, and whom.
   *
   * No `user_id`, `token` or `tenant_id`: those were decoded out of
   * `atob(sessionStorage.SESSION)` and posted with the request, which let
   * the client name the organisation being enrolled into. All three come
   * from the bearer token now.
   */
  private buildEnrollmentRequest(): EnrollmentRequest {
    const request: EnrollmentRequest = {
      training_id: this.selectedTraining!.training_id,
      enrollment_type: this.enrollmentType,
      is_mandatory: this.isMandatory,
      notify_users: this.notifyUsers,
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

  /** Back to the programme list this screen was opened from. */
  goBack(): void {
    void this.router.navigate(['/live-training/admin']);
  }
}
