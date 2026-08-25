import { Routes } from '@angular/router';
import {LoginComponent} from './public/login/login.component';
import {UserProfileComponent} from './shared/user-profile/user-profile.component';
import {RegisterComponent} from './public/register/register.component';
import {ResetComponent} from './public/reset/reset.component';
import {UserComponent} from './learner/user/user.component';
import {CerticatesComponent} from './learner/certicates/certicates.component';
import {AdminComponent} from './admin/admin/admin.component';
import {MediaComponent} from './admin/media/media.component';
import {EnrollmentComponent} from './admin/enrollment/enrollment.component';
import {SettingsComponent} from './admin/branding/settings.component';
import {LearningComponent} from './learner/learning/learning.component';
import {DictionaryComponent} from './admin/dictionary/dictionary.component';
import {ManageUsersComponent} from './admin/users/manage-users/manage-users.component';
import {CourseComponent} from './admin/courses/courses.component';
import {NotesComponent} from './learner/notes/notes.component';
import {ManageCourseComponent} from './admin/courses/manage-course/manage-course.component';
import {MyReportComponent} from './learner/my-report/my-report.component';
import {LearnerSkillsPageComponent} from './learner/learner-skills-page/learner-skills-page.component';
import {AdminSkillsPageComponent} from './admin/admin-skills-page/admin-skills-page.component';
import {MyTrainingComponent} from './learner/my-training/my-training.component';
import {AdminTrainingComponent} from './admin/admin-training/admin-training.component';
import {learningPathRoutes} from './learning-path.routes';
import {LmsHomepageComponent} from './public/lms-homepage/lms-homepage.component';
import {proctoredAssessmentRoutes} from './proctored-assessment.routes';
import {reportsRoutes} from './reports/reports.routes';
import {usersRoutes} from './admin/users/users.routes';
import { websiteRoutes } from './website/website.routes';
export const routes: Routes = [
  ...learningPathRoutes,
  ...proctoredAssessmentRoutes,
  ...reportsRoutes,
  ...usersRoutes,
  ...websiteRoutes,
  {
    path: 'login',
    component: LoginComponent,
    title: 'Login'
  },{
    path: 'register',
    component: RegisterComponent,
    title: 'Register'
  },
  {
    path: 'reset',
    component: ResetComponent,
    title: 'Reset password'
  },
  {
    path: 'home',
    component: LmsHomepageComponent,
    title: ''
  },
  {
    path: 'profile',
    component: UserProfileComponent,
    title: 'Manage your profile'
  },
  {
    path: 'account',
    component: UserComponent,
    title: 'My account'
  },
  {
    path: 'certificates',
    component: CerticatesComponent,
    title: 'My Certificates'
  },
  {
    path: 'administration',
    component: AdminComponent,
    title: 'Administration'
  },
  {
    path: 'media',
    component: MediaComponent,
    title: 'Media & Learning Content'
  },
  { path: 'courses', component: CourseComponent, title: 'Course Management' },
  { path: 'courses/create', loadComponent: () => import('./admin/courses/course-edit/course-edit.component').then(m => m.CourseEditComponent) },
  { path: 'courses/:id/edit', loadComponent: () => import('./admin/courses/course-edit/course-edit.component').then(m => m.CourseEditComponent) },
  {
    path: 'enrollment',
    component: EnrollmentComponent,
    title: 'Course Enrollment'
  },
  {
    path: 'settings',
    component: SettingsComponent,
    title: 'Settings'
  },
  {
    path: 'learning',
    component: LearningComponent,
    title: 'Learning'
  },
  {
    path: 'manageUsers',
    component: ManageUsersComponent,
    title: 'Manage users'
  },
  {
    path: 'manageCourse',
    component: ManageCourseComponent,
    title: 'Manage course'
  },
  {
    path: 'dictionary',
    component: DictionaryComponent,
    title: 'Dictionary'
  },
  {
    path: 'myreport',
    component: MyReportComponent,
    title: 'Performance Report'
  },
  {
    path: 'competencies',
    component: LearnerSkillsPageComponent,
    title: 'Competencies'
  },
  {
    path: 'skills',
    component: AdminSkillsPageComponent,
    title: 'Skills & Competencies'
  },
  {
    path: 'notes',
    component: NotesComponent,
    title: ''
  },
  {
    path: 'mytraining',
    component: MyTrainingComponent,
    title: 'Live Training'
  },
  {
    path: 'trainings',
    component: AdminTrainingComponent,
    title: 'Live Training'
  },
  {
    path: 'live-training',
    loadChildren: () => import('./live-training/live-training.routes')
      .then(m => m.LIVE_TRAINING_ROUTES)
  },
  {
    path: 'messaging',
    loadChildren: () => import('./messaging/messaging.routes').then(m => m.MESSAGING_ROUTES)
  },
  {
    path: 'player',
    loadChildren: () => import('./course-player/course-player.routes')
  },
  {
    path: 'mysettings',
    loadComponent: () => import('./learner/my-account.component').then(m => m.MyAccountComponent),
    title: 'My Account'
  },  {
    path: 'onboarding',
    loadComponent: () => import('./website/pages/onboarding.component').then(m => m.OnboardingComponent),
    title: 'Get Started - Central LMS'
  },
  {
    path: 'onboarding/:planId',
    loadComponent: () => import('./website/pages/onboarding.component').then(m => m.OnboardingComponent),
    title: 'Get Started - Central LMS'
  },
];
