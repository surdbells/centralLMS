import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { TopComponent } from '../../partials/top/top.component';
import {
  CompetencyService,
  type CompetencyAssessment,
  type LevelSubmission,
  type ProficiencyLevel,
} from '../../competency/competency.service';

/**
 * The employee's own side of a competency assessment.
 *
 * An assessment is started for a person against the profile of their job; this
 * is where that person records, competency by competency, the level they hold.
 * It is theirs to fill in — the assessor's screen confirms it afterwards — so
 * it lives with the learner's pages and asks only that they are signed in, not
 * for the assessor's permission.
 */
@Component({
  selector: 'app-self-assessment',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent],
  templateUrl: './self-assessment.component.html',
  styleUrl: './self-assessment.component.scss',
})
export class SelfAssessmentComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);

  loading = true;
  saving = false;
  assessments: CompetencyAssessment[] = [];

  /** The one being filled in, with the levels claimed so far. */
  open: CompetencyAssessment | null = null;
  levels: Record<string, ProficiencyLevel> = {};

  readonly levelOptions: { value: ProficiencyLevel; label: string }[] = [
    { value: 0, label: 'Not yet' },
    { value: 1, label: 'Knowledge' },
    { value: 2, label: 'Skill' },
    { value: 3, label: 'Mastery' },
  ];

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.competency.getMyAssessments().subscribe({
      next: (paged) => {
        this.assessments = paged.items;
        this.loading = false;
      },
      error: () => {
        this.toast.error('Your assessments could not be loaded.');
        this.loading = false;
      },
    });
  }

  /** Whether the person may still change their self-assessment. */
  canSelfAssess(a: CompetencyAssessment): boolean {
    return a.status === 'draft' || a.status === 'self_assessed';
  }

  openAssessment(summary: CompetencyAssessment): void {
    // The list omits items; load the full assessment to fill in.
    this.competency.getAssessment(summary.id).subscribe({
      next: (full) => {
        this.open = full;
        this.levels = {};
        for (const item of full.items) {
          // Start each competency on what they last claimed, if anything.
          this.levels[item.competencyId] = (item.selfLevel ?? 0) as ProficiencyLevel;
        }
      },
      error: () => this.toast.error('That assessment could not be opened.'),
    });
  }

  back(): void {
    this.open = null;
  }

  setLevel(competencyId: string, value: ProficiencyLevel): void {
    this.levels[competencyId] = value;
  }

  submit(): void {
    if (this.open === null) {
      return;
    }
    this.saving = true;
    const submission: LevelSubmission = { ...this.levels };
    this.competency.selfAssess(this.open.id, submission).subscribe({
      next: (updated) => {
        this.saving = false;
        this.toast.success('Your self-assessment has been saved.');
        this.assessments = this.assessments.map((a) => (a.id === updated.id ? { ...a, ...updated } : a));
        this.open = null;
      },
      error: (err) => {
        this.saving = false;
        this.toast.error(
          err?.code === 'already_reviewed'
            ? 'This assessment has already been reviewed and can no longer be changed.'
            : 'Your self-assessment could not be saved.',
        );
      },
    });
  }
}
