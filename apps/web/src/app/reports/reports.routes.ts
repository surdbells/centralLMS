// reports/reports.routes.ts
import { Routes } from '@angular/router';

export const reportsRoutes: Routes = [
  // ============================================
  // ADMIN ROUTES
  // ============================================
  {
    path: 'admin/reports',
    children: [
      {
        path: '',
        loadComponent: () => import('./components/reports-dashboard/reports-dashboard.component')
          .then(m => m.ReportsDashboardComponent),
        title: 'Reports & Analytics'
      },
      {
        path: 'catalog',
        loadComponent: () => import('./components/report-catalog/report-catalog.component')
          .then(m => m.ReportCatalogComponent),
        title: 'Report Catalog'
      },
      {
        path: 'saved',
        loadComponent: () => import('./components/saved-reports/saved-reports.component')
          .then(m => m.SavedReportsComponent),
        title: 'Saved Reports'
      },
      {
        path: 'scheduled',
        loadComponent: () => import('./components/report-scheduler/report-scheduler.component')
          .then(m => m.ReportSchedulerComponent),
        title: 'Scheduled Reports'
      },
      {
        path: 'custom',
        loadComponent: () => import('./components/custom-report-builder/custom-report-builder.component')
          .then(m => m.CustomReportBuilderComponent),
        title: 'Custom Report Builder'
      },
      {
        path: 'manager',
        loadComponent: () => import('./components/manager-dashboard/manager-dashboard.component')
          .then(m => m.ManagerDashboardComponent),
        title: 'Manager Dashboard'
      },
      {
        path: ':key',
        loadComponent: () => import('./components/report-viewer/report-viewer.component')
          .then(m => m.ReportViewerComponent),
        title: 'Report Viewer'
      }
    ]
  },
  // ============================================
  // LEARNER ROUTES
  // ============================================
  {
    path: 'learner/reports',
    children: [
      {
        path: '',
        loadComponent: () => import('./components/learner-dashboard/learner-dashboard.component')
          .then(m => m.LearnerDashboardComponent),
        title: 'My Learning Dashboard'
      }
    ]
  }
];

// Export for app.routes.ts
// Usage: import { reportsRoutes } from './reports/reports.routes';
// Then spread: ...reportsRoutes
