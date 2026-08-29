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
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon],
  templateUrl: './assessment-detail.component.html',
  styleUrl: './assessment-detail.component.scss',
})
export class AssessmentDetailComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);

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
        this.toast.error('The assessment could not be loaded.');
        this.loading = false;
      },
    });
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
        this.toast.success(mode === 'self' ? 'Self-assessment recorded.' : 'Review recorded.');
      },
      error: () => {
        this.saving = false;
        this.toast.error('The levels could not be saved.');
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
        this.toast.success('Assessment finalised.');
      },
      error: (err) => {
        this.saving = false;
        this.toast.error(
          err?.code === 'nothing_to_finalize'
            ? 'Nothing has been assessed yet.'
            : 'The assessment could not be finalised.',
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
        this.toast.success('Report notes saved.');
      },
      error: () => {
        this.savingNarrative = false;
        this.toast.error('The report notes could not be saved.');
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
