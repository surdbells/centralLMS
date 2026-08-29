import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import {
  CompetencyService,
  type AssessmentStatus,
  type CompetencyAssessment,
  type JobCompetencyProfile,
} from '../../competency.service';
import { UsersService, type User } from '../../../services/users.service';

/**
 * The register of assessments: who is being measured against which profile,
 * how far each has got, and how ready they are so far.
 *
 * Starting one is a small act — a person and a profile in a cycle — because
 * the substance is filled in afterwards, on the assessment's own page, as the
 * person self-assesses and an expert reviews.
 */
@Component({
  selector: 'app-competency-assessments',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  templateUrl: './competency-assessments.component.html',
  styleUrl: './competency-assessments.component.scss',
})
export class CompetencyAssessmentsComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly users = inject(UsersService);
  private readonly toast = inject(HotToastService);
  private readonly router = inject(Router);

  loading = true;
  assessments: CompetencyAssessment[] = [];
  total = 0;

  search = '';
  statusFilter: AssessmentStatus | '' = '';
  cycleFilter = '';

  private readonly search$ = new Subject<string>();

  readonly statuses: Array<{ value: AssessmentStatus; label: string; badge: string }> = [
    { value: 'draft', label: 'Draft', badge: 'bg-secondary-subtle text-secondary-emphasis' },
    { value: 'self_assessed', label: 'Self-assessed', badge: 'bg-info-subtle text-info-emphasis' },
    { value: 'reviewed', label: 'Reviewed', badge: 'bg-primary-subtle text-primary-emphasis' },
    { value: 'finalized', label: 'Finalised', badge: 'bg-success-subtle text-success-emphasis' },
  ];

  // Start modal
  modalOpen = false;
  starting = false;
  userQuery = '';
  userResults: User[] = [];
  selectedUser: User | null = null;
  profiles: JobCompetencyProfile[] = [];
  selectedProfileId = '';
  cycle = new Date().getFullYear().toString();
  private readonly userSearch$ = new Subject<string>();

  ngOnInit(): void {
    this.load();
    this.search$.pipe(debounceTime(300), distinctUntilChanged()).subscribe(() => this.load());
    this.userSearch$.pipe(debounceTime(250), distinctUntilChanged()).subscribe((q) => this.searchUsers(q));
  }

  load(): void {
    this.loading = true;
    this.competency
      .getAssessments({
        search: this.search || undefined,
        status: this.statusFilter || undefined,
        cycle: this.cycleFilter || undefined,
        perPage: 100,
      })
      .subscribe({
        next: (paged) => {
          this.assessments = paged.items;
          this.total = paged.meta.total;
          this.loading = false;
        },
        error: () => {
          this.toast.error('The assessments could not be loaded.');
          this.loading = false;
        },
      });
  }

  onSearch(value: string): void {
    this.search = value;
    this.search$.next(value);
  }

  statusMeta(status: AssessmentStatus): { label: string; badge: string } {
    return this.statuses.find((s) => s.value === status) ?? { label: status, badge: 'bg-secondary-subtle' };
  }

  open(assessment: CompetencyAssessment): void {
    void this.router.navigate(['/admin/competency/assessments', assessment.id]);
  }

  // --- start a new assessment ---------------------------------------------

  openStart(): void {
    this.modalOpen = true;
    this.selectedUser = null;
    this.userQuery = '';
    this.userResults = [];
    this.selectedProfileId = '';
    this.cycle = new Date().getFullYear().toString();
    if (!this.profiles.length) {
      this.competency.getProfiles({ perPage: 200 }).subscribe({
        next: (paged) => (this.profiles = paged.items.filter((p) => p.competencyCount > 0)),
        error: () => undefined,
      });
    }
  }

  closeStart(): void {
    this.modalOpen = false;
  }

  onUserQuery(value: string): void {
    this.userQuery = value;
    this.selectedUser = null;
    this.userSearch$.next(value);
  }

  private searchUsers(query: string): void {
    if (query.trim().length < 2) {
      this.userResults = [];
      return;
    }
    this.users.options(query, 8).subscribe({
      next: (paged) => (this.userResults = paged.items),
      error: () => (this.userResults = []),
    });
  }

  pickUser(user: User): void {
    this.selectedUser = user;
    this.userQuery = user.fullName;
    this.userResults = [];
  }

  start(): void {
    if (!this.selectedUser || !this.selectedProfileId) {
      this.toast.error('Choose a person and a profile.');
      return;
    }
    this.starting = true;
    this.competency.startAssessment(this.selectedUser.id, this.selectedProfileId, this.cycle.trim() || undefined).subscribe({
      next: (assessment) => {
        this.starting = false;
        this.closeStart();
        this.toast.success('Assessment started.');
        this.open(assessment);
      },
      error: (err) => {
        this.starting = false;
        if (err?.code === 'duplicate_assessment') {
          this.toast.error('This person already has an assessment against that profile for this cycle.');
        } else if (err?.code === 'empty_profile') {
          this.toast.error('That profile has no competencies to assess against.');
        } else {
          this.toast.error('The assessment could not be started.');
        }
      },
    });
  }
}
