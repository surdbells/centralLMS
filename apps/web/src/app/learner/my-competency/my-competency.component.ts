import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  CompetencyService,
  type CompetencyAssessment,
  type ProficiencyLevel,
} from '../../competency/competency.service';
import { CycleSelectComponent } from '../../competency/components/cycle-select/cycle-select.component';
import { TranslatePipe } from '../../translate.pipe';

/** The learner's own view of the competency exercise, cycle by cycle. */
type Tab = 'profile' | 'last' | 'self' | 'sme' | 'line';

/**
 * A learner's own competency assurance, in five cycle-filterable views: the
 * profile their role is measured against, their last completed assurance, and
 * each of the three assessment stages (self, SME, line manager).
 *
 * Read-only — the one thing the learner does, their self-assessment, happens on
 * its own screen, which the Self tab links to while it is still their turn. All
 * the numbers are the API's; this screen only arranges them.
 */
@Component({
  selector: 'app-my-competency',
  standalone: true,
  imports: [CommonModule, FormsModule, CycleSelectComponent, TranslatePipe],
  templateUrl: './my-competency.component.html',
  styleUrl: './my-competency.component.scss',
})
export class MyCompetencyComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly router = inject(Router);

  loading = true;
  activeTab: Tab = 'profile';

  /** The chosen cycle (bound to the cycle picker). Empty means none yet. */
  cycle = '';
  /** The learner's assessments for the chosen cycle — one per job profile. */
  summaries: CompetencyAssessment[] = [];
  /** The assessment being shown, loaded in full (with its items). */
  selected: CompetencyAssessment | null = null;

  readonly tabs: ReadonlyArray<{ id: Tab; icon: string; labelKey: string }> = [
    { id: 'profile', icon: 'bi-bullseye',       labelKey: 'my_competency.tab_profile' },
    { id: 'last',    icon: 'bi-patch-check',     labelKey: 'my_competency.tab_last' },
    { id: 'self',    icon: 'bi-person',          labelKey: 'my_competency.tab_self' },
    { id: 'sme',     icon: 'bi-person-check',     labelKey: 'my_competency.tab_sme' },
    { id: 'line',    icon: 'bi-person-vcard',     labelKey: 'my_competency.tab_line' },
  ];

  ngOnInit(): void {
    // Open on the learner's most recent assessment's cycle, so the screen lands
    // on something rather than asking them to choose first.
    this.competency.getMyAssessments().subscribe({
      next: (page) => {
        this.cycle = page.items[0]?.cycle ?? '';
        if (this.cycle !== '') {
          this.loadCycle();
        } else {
          this.loading = false;
        }
      },
      error: () => (this.loading = false),
    });
  }

  onCycleChange(cycle: string): void {
    this.cycle = cycle;
    this.loadCycle();
  }

  private loadCycle(): void {
    this.loading = true;
    this.selected = null;
    this.competency.getMyAssessments(this.cycle).subscribe({
      next: (page) => {
        this.summaries = page.items;
        if (page.items[0]) {
          this.open(page.items[0].id);
        } else {
          this.loading = false;
        }
      },
      error: () => (this.loading = false),
    });
  }

  open(id: string): void {
    this.loading = true;
    this.competency.getAssessment(id).subscribe({
      next: (assessment) => {
        this.selected = assessment;
        this.loading = false;
      },
      error: () => (this.loading = false),
    });
  }

  /** Still the learner's turn to self-assess. */
  get canSelfAssess(): boolean {
    return this.selected?.status === 'draft' || this.selected?.status === 'self_assessed';
  }

  get openGaps(): number {
    const c = this.selected?.gapCounts;
    return c ? c['minor'] + c['major'] + c['significant'] : 0;
  }

  goSelfAssess(): void {
    void this.router.navigate(['/self-assessment']);
  }

  letter(level: ProficiencyLevel | null | undefined): string {
    const letters = ['–', 'K', 'S', 'M'];

    return level === null || level === undefined ? '–' : letters[level] ?? '–';
  }

  gapClass(category: string): string {
    return (
      {
        met: 'gap-met',
        minor: 'gap-minor',
        major: 'gap-major',
        significant: 'gap-significant',
      }[category] ?? 'gap-met'
    );
  }
}
