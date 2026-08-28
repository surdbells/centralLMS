// live-training/live-training.routes.ts
// Standalone routes for Angular 17+ applications

import { Routes } from '@angular/router';
import { AdminLiveTrainingComponent } from './admin/admin-live-training.component';
import { TrainingFormComponent } from './training-form/training-form.component';
import { LearnerLiveTrainingComponent } from './learner/learner-live-training.component';
import { InstructorDashboardComponent } from './instructor/instructor-dashboard.component';
import { TrainingReportsComponent } from './reports/training-reports.component';
import { IntegrationsSettingsComponent } from './integrations/integrations-settings.component';
import { QrScannerComponent } from './qr-scanner/qr-scanner.component';
import {TrainingEnrollmentComponent} from './training-enrollment/training-enrollment.component';
import {TrainingSkillsComponent} from './skills/training-skills.component';

export const LIVE_TRAINING_ROUTES: Routes = [
  // Setting one up. Declared before `admin/:trainingId`, because a
  // parameter matches anything and `admin/new` would otherwise open the
  // admin screen for a programme called "new".
  {
    path: 'admin/new',
    component: TrainingFormComponent,
    data: { title: 'New live training' }
  },
  {
    path: 'admin/:trainingId/edit',
    component: TrainingFormComponent,
    data: { title: 'Edit live training' }
  },

  // Admin Routes
  {
    path: 'admin',
    component: AdminLiveTrainingComponent,
    data: { title: 'Live Training Management' }
  },
  {
    path: 'admin/:trainingId',
    component: AdminLiveTrainingComponent,
    data: { title: 'Training Details' }
  },
  {
    path: 'admin/:trainingId/session/:sessionId',
    component: AdminLiveTrainingComponent,
    data: { title: 'Session Details' }
  },
  // Instructor Routes
  {
    path: 'instructor',
    component: InstructorDashboardComponent,
    data: { title: 'Instructor Dashboard' }
  },
  // Reports Routes
  {
    path: 'reports',
    component: TrainingReportsComponent,
    data: { title: 'Training Reports' }
  },
  // Integration Settings
  {
    path: 'integrations',
    component: IntegrationsSettingsComponent,
    data: { title: 'Video Integrations' }
  },
  // Enrollments
  {
    path: 'enrollments',
    component: TrainingEnrollmentComponent,
    data: { title: 'Training enrollment' }
  },
  // Enrollments
  {
    path: 'skills',
    component: TrainingSkillsComponent,
    data: { title: 'Training skills and Competency mapping' }
  },
  // QR Scanner
  {
    path: 'scan',
    component: QrScannerComponent,
    data: { title: 'Scan Attendance' }
  },
  // Learner Routes (default)
  {
    path: 'training/:trainingId',
    component: LearnerLiveTrainingComponent,
    data: { title: 'Training Details' }
  },
  {
    path: '',
    component: LearnerLiveTrainingComponent,
    data: { title: 'Live Training' }
  },

  // Redirect unknown routes
  {
    path: '**',
    redirectTo: ''
  }
];

export default LIVE_TRAINING_ROUTES;
