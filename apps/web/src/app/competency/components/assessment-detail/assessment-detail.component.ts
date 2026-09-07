import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import {
  CompetencyService,
  type CompetencyAssessment,
  type ProficiencyLevel,
} from '../../competency.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

/** Which levels the current step lets you edit. */
type EditMode = 'none' | 'self' | 'review';

/**
 * One assessment, worked through its stages: the person records their own
 * levels, an expert confirms them, and it is finalised. The gap beside each
 * competency is the API's, recomputed every time a level changes — this screen
 * shows it, it never works it out.
 */
@Component({
  selector: 'app-assessment-detail',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, TranslatePipe],
  templateUrl: './assessment-detail.component.html',
  styleUrl: './assessment-detail.component.scss',
})
export class AssessmentDetailComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  loading = true;
  saving = false;
  assessment: CompetencyAssessment | null = null;

  editMode: EditMode = 'none';
  /** Levels being edited, keyed by competency id. */
  draft: Record<string, ProficiencyLevel> = {};
  finalizeComment = '';
  showFinalize = false;

  // The report's qualitative side, written by the assessor / line manager.
  savingNarrative = false;
  narrative = {
    assessorComment: '',
    lineManagerComment: '',
    lineExecutiveDecision: '',
    gapsIdentified: '',
    gapsClosureInterventions: '',
  };

  readonly levelOptions: Array<{ value: ProficiencyLevel; letter: string; label: string }> = [
    { value: 0, letter: '–', label: 'None' },
    { value: 1, letter: 'K', label: 'Knowledge' },
    { value: 2, letter: 'S', label: 'Skill' },
    { value: 3, letter: 'M', label: 'Mastery' },
  ];

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (id) {
      this.load(id);
    }
  }

  load(id: string): void {
    this.loading = true;
    this.competency.getAssessment(id).subscribe({
      next: (assessment) => {
        this.assessment = assessment;
        this.seedNarrative(assessment);
        this.loading = false;
      },
      error: () => {
        this.toast.error(this.i18n.t('competency.detail.load_error'));
        this.loading = false;
      },
    });
  }

  /** The tooltip for a level toggle button, matching the shared level names. */
  levelTitle(level: ProficiencyLevel): string {
    const key: Record<number, string> = {
      0: 'competency.detail.level_none',
      1: 'self_assessment.level_knowledge',
      2: 'self_assessment.level_skill',
      3: 'self_assessment.level_mastery',
    };
    return key[level] ? this.i18n.t(key[level]) : '';
  }

  // --- editing ------------------------------------------------------------

  startSelf(): void {
    if (!this.assessment) {
      return;
    }
    this.editMode = 'self';
    this.draft = {};
    for (const item of this.assessment.items) {
      this.draft[item.competencyId] = item.selfLevel ?? 0;
    }
  }

  startReview(): void {
    if (!this.assessment) {
      return;
    }
    this.editMode = 'review';
    this.draft = {};
    for (const item of this.assessment.items) {
      // Seed the review with the self-claim, so the expert confirms or corrects
      // rather than starting from nothing.
      this.draft[item.competencyId] = item.assessedLevel ?? item.selfLevel ?? 0;
    }
  }

  cancelEdit(): void {
    this.editMode = 'none';
    this.draft = {};
  }

  setLevel(competencyId: string, level: ProficiencyLevel): void {
    this.draft[competencyId] = level;
  }

  submit(): void {
    if (!this.assessment || this.editMode === 'none') {
      return;
    }
    this.saving = true;
    const id = this.assessment.id;
    const mode = this.editMode;
    const request =
      mode === 'self'
        ? this.competency.selfAssess(id, this.draft)
        : this.competency.review(id, this.draft);

    request.subscribe({
      next: (updated) => {
        this.assessment = updated;
        this.saving = false;
        this.editMode = 'none';
        this.toast.success(this.i18n.t(mode === 'self' ? 'competency.detail.self_recorded' : 'competency.detail.review_recorded'));
      },
      error: () => {
        this.saving = false;
        this.toast.error(this.i18n.t('competency.detail.levels_save_error'));
      },
    });
  }

  // --- finalising ---------------------------------------------------------

  openFinalize(): void {
    this.showFinalize = true;
    this.finalizeComment = this.assessment?.assessorComment ?? '';
  }

  cancelFinalize(): void {
    this.showFinalize = false;
  }

  finalize(): void {
    if (!this.assessment) {
      return;
    }
    this.saving = true;
    this.competency.finalize(this.assessment.id, this.finalizeComment.trim() || undefined).subscribe({
      next: (updated) => {
        this.assessment = updated;
        this.saving = false;
        this.showFinalize = false;
        this.toast.success(this.i18n.t('competency.detail.finalised'));
      },
      error: (err) => {
        this.saving = false;
        this.toast.error(
          this.i18n.t(
            err?.code === 'nothing_to_finalize'
              ? 'competency.detail.nothing_assessed'
              : 'competency.detail.finalise_error',
          ),
        );
      },
    });
  }

  // --- report narrative ---------------------------------------------------

  private seedNarrative(a: CompetencyAssessment): void {
    this.narrative = {
      assessorComment: a.assessorComment ?? '',
      lineManagerComment: a.lineManagerComment ?? '',
      lineExecutiveDecision: a.lineExecutiveDecision ?? '',
      gapsIdentified: a.gapsIdentified ?? '',
      gapsClosureInterventions: a.gapsClosureInterventions ?? '',
    };
  }

  saveNarrative(): void {
    if (!this.assessment) {
      return;
    }
    this.savingNarrative = true;
    this.competency.saveReport(this.assessment.id, { ...this.narrative }).subscribe({
      next: (updated) => {
        this.assessment = { ...this.assessment!, ...updated };
        this.savingNarrative = false;
        this.toast.success(this.i18n.t('competency.detail.notes_saved'));
      },
      error: () => {
        this.savingNarrative = false;
        this.toast.error(this.i18n.t('competency.detail.notes_save_error'));
      },
    });
  }

  // --- display helpers ----------------------------------------------------

  gapBadgeClass(category: string): string {
    return (
      {
        met: 'bg-success-subtle text-success-emphasis',
        minor: 'bg-warning-subtle text-warning-emphasis',
        major: 'bg-orange-subtle text-orange',
        significant: 'bg-danger-subtle text-danger-emphasis',
      }[category] ?? 'bg-secondary-subtle'
    );
  }

  letter(level: ProficiencyLevel | null): string {
    if (level === null) {
      return '–';
    }
    return this.levelOptions[level]?.letter ?? '–';
  }

  back(): void {
    void this.router.navigate(['/admin/competency/assessments']);
  }
}
