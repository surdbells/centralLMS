// competency/competency.routes.ts
import { Routes } from '@angular/router';

/**
 * Competency assurance — one module, four related screens under a shared shell.
 *
 * The dictionary defines what capabilities exist; the job profiles say what
 * each role needs of them; the assessments measure people against those
 * profiles; the overview reads the outcome across everyone. They sit behind
 * one sub-navigation because they are read in that order.
 */
export const competencyRoutes: Routes = [
  {
    // The individual report prints full-page, so it sits outside the shell
    // and its sub-navigation rather than inside it.
    path: 'admin/competency/assessments/:id/report',
    loadComponent: () =>
      import('./components/individual-report/individual-report.component').then(
        (m) => m.IndividualReportComponent,
      ),
    title: 'Competency Report',
  },
  {
    path: 'admin/competency',
    loadComponent: () =>
      import('./components/competency-shell/competency-shell.component').then(
        (m) => m.CompetencyShellComponent,
      ),
    children: [
      {
        path: '',
        loadComponent: () =>
          import('./components/outcome-report/outcome-report.component').then(
            (m) => m.OutcomeReportComponent,
          ),
        title: 'Competency Assurance',
      },
      {
        path: 'dictionary',
        loadComponent: () =>
          import('./components/competency-dictionary/competency-dictionary.component').then(
            (m) => m.CompetencyDictionaryComponent,
          ),
        title: 'Competency Dictionary',
      },
      {
        path: 'profiles',
        loadComponent: () =>
          import('./components/job-profiles/job-profiles.component').then(
            (m) => m.JobProfilesComponent,
          ),
        title: 'Job Competency Profiles',
      },
      {
        path: 'assessments',
        loadComponent: () =>
          import('./components/competency-assessments/competency-assessments.component').then(
            (m) => m.CompetencyAssessmentsComponent,
          ),
        title: 'Competency Assessments',
      },
      {
        path: 'assessments/:id',
        loadComponent: () =>
          import('./components/assessment-detail/assessment-detail.component').then(
            (m) => m.AssessmentDetailComponent,
          ),
        title: 'Assessment',
      },
      {
        path: 'reports',
        loadComponent: () =>
          import('./components/report-viewer/report-viewer.component').then(
            (m) => m.ReportViewerComponent,
          ),
        title: 'Competency Reports',
      },
    ],
  },
];

// Usage: import { competencyRoutes } from './competency/competency.routes';
// Then spread into app.routes.ts: ...competencyRoutes
