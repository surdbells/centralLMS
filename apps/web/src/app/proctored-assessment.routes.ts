import { Routes } from '@angular/router';

export const proctoredAssessmentRoutes: Routes = [
  // ============================================
  // ADMIN ROUTES
  // ============================================

  // --- Phase 15: Admin Dashboard & Reports ---
  {
    path: 'admin/assessments',
    children: [
      {
        path: '',
        loadComponent: () => import('./proctored/assessment-dashboard/assessment-dashboard.component')
          .then(m => m.AssessmentDashboardComponent),
        title: 'Assessment Dashboard'
      },
      {
        path: 'reports',
        loadComponent: () => import('./proctored/assessment-reports/assessment-reports.component')
          .then(m => m.AssessmentReportsComponent),
        title: 'Assessment Reports'
      },
      // --- Phase 10: Assessment Builder ---
      {
        path: 'list',
        loadComponent: () => import('./proctored/assessment-list/assessment-list.component')
          .then(m => m.AssessmentListComponent),
        title: 'Manage Assessments'
      },
      {
        path: 'create',
        loadComponent: () => import('./proctored/assessment-form/assessment-form.component')
          .then(m => m.AssessmentFormComponent),
        title: 'Create Assessment',
        data: { mode: 'create' }
      },
      {
        path: ':id/edit',
        loadComponent: () => import('./proctored/assessment-form/assessment-form.component')
          .then(m => m.AssessmentFormComponent),
        title: 'Edit Assessment',
        data: { mode: 'edit' }
      },
      {
        path: ':id/preview',
        loadComponent: () => import('./proctored/assessment-preview/assessment-preview.component')
          .then(m => m.AssessmentPreviewComponent),
        title: 'Preview Assessment'
      },
      {
        path: ':id/sections',
        loadComponent: () => import('./proctored/section-manager/section-manager.component')
          .then(m => m.SectionManagerComponent),
        title: 'Manage Sections'
      },
      {
        path: ':id/settings',
        loadComponent: () => import('./proctored/assessment-settings/assessment-settings.component')
          .then(m => m.AssessmentSettingsComponent),
        title: 'Assessment Settings'
      }
    ]
  },

  // --- Phase 9: Question Bank ---
  {
    path: 'admin/question-bank',
    children: [
      {
        path: '',
        loadComponent: () => import('./proctored/question-list/question-list.component')
          .then(m => m.QuestionListComponent),
        title: 'Question Bank'
      },
      {
        path: 'create',
        loadComponent: () => import('./proctored/question-form/question-form.component')
          .then(m => m.QuestionFormComponent),
        title: 'Create Question',
        data: { mode: 'create' }
      },
      {
        path: ':id/edit',
        loadComponent: () => import('./proctored/question-form/question-form.component')
          .then(m => m.QuestionFormComponent),
        title: 'Edit Question',
        data: { mode: 'edit' }
      },
      {
        path: 'import',
        loadComponent: () => import('./proctored/question-import/question-import.component')
          .then(m => m.QuestionImportComponent),
        title: 'Import Questions'
      }
    ]
  },

  // --- Phase 11: Grading ---
  {
    path: 'admin/grading',
    children: [
      {
        path: '',
        loadComponent: () => import('./proctored/grading/grading-queue/grading-queue.component')
          .then(m => m.GradingQueueComponent),
        title: 'Grading Queue'
      },
      // The rubric routes come before `:attemptId`. Angular takes the first
      // route that matches, and a parameter matches anything — declared after
      // it, `rubrics` was never reachable and the URL opened the grading
      // screen for an attempt called "rubrics".
      {
        path: 'rubrics',
        loadComponent: () => import('./proctored/grading/rubric-manager/rubric-manager.component')
          .then(m => m.RubricManagerComponent),
        title: 'Rubric Manager'
      },
      {
        path: 'rubrics/create',
        loadComponent: () => import('./proctored/grading/rubric-form/rubric-form.component')
          .then(m => m.RubricFormComponent),
        title: 'Create Rubric',
        data: { mode: 'create' }
      },
      {
        path: 'rubrics/:id/edit',
        loadComponent: () => import('./proctored/grading/rubric-form/rubric-form.component')
          .then(m => m.RubricFormComponent),
        title: 'Edit Rubric',
        data: { mode: 'edit' }
      },
      {
        path: ':attemptId',
        loadComponent: () => import('./proctored/grading/grading-interface/grading-interface.component')
          .then(m => m.GradingInterfaceComponent),
        title: 'Grade Attempt'
      }
    ]
  },

  // --- Phase 12: Proctoring Review ---
  {
    path: 'admin/proctoring',
    children: [
      {
        path: 'settings',
        loadComponent: () => import('./proctored/proctoring-settings/proctoring-settings.component')
          .then(m => m.ProctoringSettingsComponent),
        title: 'Proctoring Settings'
      },
      {
        path: 'sessions',
        loadComponent: () => import('./proctored/session-review/session-review.component')
          .then(m => m.SessionReviewComponent),
        title: 'Proctoring Sessions'
      },
      {
        path: 'violations',
        loadComponent: () => import('./proctored/violation-viewer/violation-viewer.component')
          .then(m => m.ViolationViewerComponent),
        title: 'Violations'
      },
      {
        path: 'snapshots/:sessionId',
        loadComponent: () => import('./proctored/snapshot-gallery/snapshot-gallery.component')
          .then(m => m.SnapshotGalleryComponent),
        title: 'Snapshot Gallery'
      }
    ]
  },

  // ============================================
  // LEARNER ROUTES
  // ============================================
  {
    path: 'learner/assessments',
    children: [
      // --- Phase 15: Learner Dashboard ---
      {
        path: '',
        loadComponent: () => import('./proctored/learner-dashboard/learner-dashboard.component')
          .then(m => m.LearnerDashboardComponent),
        title: 'My Assessments'
      },
      // --- Phase 15: Assessment Catalog ---
      {
        path: 'catalog',
        loadComponent: () => import('./proctored/assessment-catalog/assessment-catalog.component')
          .then(m => m.AssessmentCatalogComponent),
        title: 'Assessment Catalog'
      },
      // --- Phase 14: History ---
      {
        path: 'history',
        loadComponent: () => import('./proctored/assessment-history/assessment-history.component')
          .then(m => m.AssessmentHistoryComponent),
        title: 'Assessment History'
      },
      // --- Phase 14: Certificates ---
      {
        path: 'certificates/:id',
        loadComponent: () => import('./proctored/certificate-viewer/certificate-viewer.component')
          .then(m => m.CertificateViewerComponent),
        title: 'Certificate'
      },
      // --- Phase 13: Assessment Lobby ---
      {
        path: ':id/lobby',
        loadComponent: () => import('./proctored/assessment-lobby/assessment-lobby.component')
          .then(m => m.AssessmentLobbyComponent),
        title: 'Assessment Lobby'
      },
      // --- Phase 13: Assessment Player ---
      {
        path: ':id/attempt/:attemptId',
        loadComponent: () => import('./proctored/assessment-player/assessment-player.component')
          .then(m => m.AssessmentPlayerComponent),
        title: 'Assessment'
      },
      // --- Phase 14: Assessment Results ---
      {
        path: ':id/results/:attemptId',
        loadComponent: () => import('./proctored/assessment-results/assessment-results.component')
          .then(m => m.AssessmentResultsComponent),
        title: 'Assessment Results'
      },
      // --- Assessment Detail (generic landing) ---
      {
        path: ':id',
        loadComponent: () => import('./proctored/assessment-catalog/assessment-catalog.component')
          .then(m => m.AssessmentCatalogComponent),
        title: 'Assessment Details'
      }
    ]
  }
];

// ============================================
// USAGE IN app.routes.ts:
// ============================================
// import { proctoredAssessmentRoutes } from './proctored-assessment/proctored-assessment.routes';
// Then spread into your main routes: ...proctoredAssessmentRoutes
