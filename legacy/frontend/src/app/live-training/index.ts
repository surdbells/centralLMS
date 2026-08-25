// live-training/index.ts
// Barrel exports for standalone Angular application

// Routes
export * from './live-training.routes';

// Services
export * from './live-training.service';
export * from './services/video-integration.service';

// Admin Components
export * from './admin/admin-live-training.component';

// Learner Components
export * from './learner/learner-live-training.component';

// Instructor Components
export * from './instructor/instructor-dashboard.component';

// Reports Components
export * from './reports/training-reports.component';

// Shared Components
export * from './qr-scanner/qr-scanner.component';
export * from './integrations/integrations-settings.component';

// Widgets
export * from './widgets/upcoming-sessions-widget.component';
export * from './widgets/attendance-widget.component';

// Pipes
export * from './pipes/live-training-pipes';
