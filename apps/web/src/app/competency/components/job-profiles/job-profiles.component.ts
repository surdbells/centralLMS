import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import {
  CompetencyService,
  type Competency,
  type JobCompetencyProfile,
  type ProficiencyLevel,
} from '../../competency.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

/** One row being built in the requirement editor. */
interface RequirementDraft {
  competencyId: string;
  requiredLevel: ProficiencyLevel;
  isCritical: boolean;
}

/**
 * Job Competency Profiles — the standard each role is measured against.
 *
 * A profile is its requirements: a job title with nothing required of it
 * measures nobody. So the editor is a requirement builder first — pick a
 * competency, say to what level, mark it critical if the job cannot be done
 * without it — and the title and function are the frame around that.
 */
@Component({
  selector: 'app-job-profiles',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TranslatePipe],
  templateUrl: './job-profiles.component.html',
  styleUrl: './job-profiles.component.scss',
})
export class JobProfilesComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  loading = true;
  saving = false;
  profiles: JobCompetencyProfile[] = [];
  search = '';
  functionFilter = '';
  knownFunctions: string[] = [];
  expandedId: string | null = null;
  /** The full profile behind the open row — the list omits requirements. */
  expandedProfile: JobCompetencyProfile | null = null;
  expandedLoading = false;

  // Every competency, for the requirement picker, indexed by function.
  allCompetencies: Competency[] = [];

  // The searchable "add a competency" picker.
  pickerOpen = false;
  pickerSearch = '';

  // Modal / editor state
  modalOpen = false;
  editing: JobCompetencyProfile | null = null;
  jobTitle = '';
  functionName = '';
  description = '';
  requirements: RequirementDraft[] = [];

  deleteTarget: JobCompetencyProfile | null = null;

  readonly levels: Array<{ value: ProficiencyLevel; label: string }> = [
    { value: 1, label: 'Knowledge' },
    { value: 2, label: 'Skill' },
    { value: 3, label: 'Mastery' },
  ];

  ngOnInit(): void {
    this.load();
    this.competency.getCompetencies({ perPage: 500 }).subscribe({
      next: (paged) => (this.allCompetencies = paged.items),
      error: () => undefined,
    });
  }

  load(): void {
    this.loading = true;
    this.competency
      .getProfiles({ search: this.search || undefined, function: this.functionFilter || undefined, perPage: 200 })
      .subscribe({
        next: (paged) => {
          this.profiles = paged.items;
          this.loading = false;
        },
        error: () => {
          this.toast.error(this.i18n.t('competency.profiles.load_error'));
          this.loading = false;
        },
      });
    this.competency.getFunctions().subscribe({
      next: (functions) => (this.knownFunctions = functions.map((f) => f.function)),
      error: () => undefined,
    });
  }

  toggleExpand(id: string): void {
    if (this.expandedId === id) {
      this.expandedId = null;
      this.expandedProfile = null;
      return;
    }

    // The list view carries no requirements, so the full profile is fetched
    // the moment a row is opened.
    this.expandedId = id;
    this.expandedProfile = null;
    this.expandedLoading = true;
    this.competency.getProfile(id).subscribe({
      next: (profile) => {
        this.expandedProfile = profile;
        this.expandedLoading = false;
      },
      error: () => {
        this.toast.error(this.i18n.t('competency.profiles.profile_load_error'));
        this.expandedLoading = false;
      },
    });
  }

  // --- competencies available to pick, for the chosen function -------------

  get pickableCompetencies(): Competency[] {
    const chosen = new Set(this.requirements.map((r) => r.competencyId));
    return this.allCompetencies
      .filter((c) => !this.functionName || c.functionName === this.functionName)
      .filter((c) => !chosen.has(c.id));
  }

  /** The pickable competencies narrowed by what has been typed. */
  get filteredPickable(): Competency[] {
    const q = this.pickerSearch.trim().toLowerCase();
    if (!q) {
      return this.pickableCompetencies;
    }
    return this.pickableCompetencies.filter(
      (c) => c.name.toLowerCase().includes(q) || (c.description ?? '').toLowerCase().includes(q),
    );
  }

  openPicker(): void {
    this.pickerOpen = true;
  }

  closePicker(): void {
    setTimeout(() => (this.pickerOpen = false), 150);
  }

  pick(competency: Competency): void {
    this.addRequirement(competency.id);
    this.pickerSearch = '';
    this.pickerOpen = false;
  }

  competencyName(id: string): string {
    return this.allCompetencies.find((c) => c.id === id)?.name ?? this.i18n.t('competency.profiles.unknown_competency');
  }

  /** The tooltip for a level toggle, matching the shared level names. */
  levelTitle(level: ProficiencyLevel): string {
    const key: Record<number, string> = {
      1: 'self_assessment.level_knowledge',
      2: 'self_assessment.level_skill',
      3: 'self_assessment.level_mastery',
    };
    return key[level] ? this.i18n.t(key[level]) : '';
  }

  // --- the editor ---------------------------------------------------------

  openNew(): void {
    this.editing = null;
    this.jobTitle = '';
    this.functionName = this.functionFilter || '';
    this.description = '';
    this.requirements = [];
    this.modalOpen = true;
  }

  openEdit(profile: JobCompetencyProfile): void {
    this.editing = profile;
    this.jobTitle = profile.jobTitle;
    this.functionName = profile.functionName;
    this.description = profile.description ?? '';
    // The list omits each profile's requirements to avoid loading every one of
    // every profile, so the editor fetches the full profile — otherwise it
    // opens claiming "no competencies added yet" over a profile that has them.
    this.requirements = [];
    this.modalOpen = true;
    this.competency.getProfile(profile.id).subscribe({
      next: (full) => {
        this.requirements = full.requirements.map((r) => ({
          competencyId: r.competencyId,
          requiredLevel: r.requiredLevel,
          isCritical: r.isCritical,
        }));
      },
      error: () => this.toast.error(this.i18n.t('competency.profiles.profile_load_error')),
    });
  }

  closeModal(): void {
    this.modalOpen = false;
    this.editing = null;
  }

  addRequirement(competencyId: string): void {
    if (!competencyId) {
      return;
    }
    this.requirements.push({ competencyId, requiredLevel: 2, isCritical: false });
  }

  removeRequirement(index: number): void {
    this.requirements.splice(index, 1);
  }

  save(): void {
    if (!this.jobTitle.trim() || !this.functionName.trim()) {
      this.toast.error(this.i18n.t('competency.profiles.validation_title_function'));
      return;
    }
    if (!this.requirements.length) {
      this.toast.error(this.i18n.t('competency.profiles.validation_requirements'));
      return;
    }
    this.saving = true;
    const input = {
      jobTitle: this.jobTitle,
      functionName: this.functionName,
      description: this.description,
      requirements: this.requirements,
    };
    const request = this.editing
      ? this.competency.updateProfile(this.editing.id, input)
      : this.competency.createProfile(input);

    request.subscribe({
      next: () => {
        this.toast.success(this.i18n.t(this.editing ? 'competency.profiles.updated' : 'competency.profiles.created'));
        this.saving = false;
        this.closeModal();
        this.load();
      },
      error: (err) => {
        this.saving = false;
        this.toast.error(
          this.i18n.t(
            err?.code === 'duplicate_profile'
              ? 'competency.profiles.duplicate'
              : 'competency.profiles.save_error',
          ),
        );
      },
    });
  }

  askDelete(profile: JobCompetencyProfile): void {
    this.deleteTarget = profile;
  }

  cancelDelete(): void {
    this.deleteTarget = null;
  }

  confirmDelete(): void {
    if (!this.deleteTarget) {
      return;
    }
    this.competency.deleteProfile(this.deleteTarget.id).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('competency.profiles.removed'));
        this.deleteTarget = null;
        this.load();
      },
      error: () => {
        this.toast.error(this.i18n.t('competency.profiles.remove_error'));
        this.deleteTarget = null;
      },
    });
  }

  levelLabel(level: ProficiencyLevel): string {
    return this.levels.find((l) => l.value === level)?.label ?? '-';
  }

  levelLetter(level: ProficiencyLevel): string {
    return ['-', 'K', 'S', 'M'][level] ?? '-';
  }
}
