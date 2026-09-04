import { Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import { SessionStore } from '../../../core/auth/session.store';

/**
 * The frame every competency screen sits in.
 *
 * The four screens — overview, dictionary, profiles, assessments — share a
 * sub-navigation because they are one exercise read in order: what a job
 * needs, measured against what a person has, summarised across everyone.
 */
@Component({
  selector: 'app-competency-shell',
  standalone: true,
  imports: [AdmintopComponent, RouterOutlet, RouterLink, RouterLinkActive, TuiIcon],
  templateUrl: './competency-shell.component.html',
  styleUrl: './competency-shell.component.scss',
})
export class CompetencyShellComponent {
  private readonly session = inject(SessionStore);

  /** Cycles are competency's reference data, so a competency manager sees the tab. */
  readonly canManageCycles = computed(() => this.session.can()('competency.manage'));

  constructor(private readonly router: Router) {}

  goBack(): void {
    void this.router.navigate(['/administration']);
  }
}
