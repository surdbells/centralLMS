// reports/index.ts - Barrel exports

// Models
export * from './models/report.models';

// Service
export { ReportService } from './services/report.service';

// Components
export { ReportsDashboardComponent } from './components/reports-dashboard/reports-dashboard.component';
export { ReportCatalogComponent } from './components/report-catalog/report-catalog.component';
export { ReportViewerComponent } from './components/report-viewer/report-viewer.component';
export { ReportFiltersComponent } from './components/report-filters/report-filters.component';
export { ReportTableComponent } from './components/report-table/report-table.component';
export { ReportChartComponent } from './components/report-chart/report-chart.component';
export { ReportExportComponent } from './components/report-export/report-export.component';
export { SavedReportsComponent } from './components/saved-reports/saved-reports.component';
export { LearnerDashboardComponent } from './components/learner-dashboard/learner-dashboard.component';
export { ManagerDashboardComponent } from './components/manager-dashboard/manager-dashboard.component';
export { ReportSchedulerComponent } from './components/report-scheduler/report-scheduler.component';
export { CustomReportBuilderComponent } from './components/custom-report-builder/custom-report-builder.component';

// Routes
export { reportsRoutes } from '../../../../../../../Downloads/front/reports.routes';
