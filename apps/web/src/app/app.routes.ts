import { Routes } from '@angular/router';
import {LoginComponent} from './public/login/login.component';
import {UserProfileComponent} from './shared/user-profile/user-profile.component';
import {RegisterComponent} from './public/register/register.component';
import {ResetComponent} from './public/reset/reset.component';
import {UserComponent} from './learner/user/user.component';
import {CerticatesComponent} from './learner/certicates/certicates.component';
import {AdminComponent} from './admin/admin/admin.component';
import {MediaComponent} from './admin/media/media.component';
import {SettingsComponent} from './admin/branding/settings.component';
import {LearningComponent} from './learner/learning/learning.component';
import {DictionaryComponent} from './admin/dictionary/dictionary.component';
import {CourseComponent} from './admin/courses/courses.component';
import {NotificationsComponent} from './learner/notifications/notifications.component';
import {NotesComponent} from './learner/notes/notes.component';
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
export const routes: Routes = [
  ...learningPathRoutes,
  ...proctoredAssessmentRoutes,
  ...reportsRoutes,
  ...usersRoutes,
  {
    /**
     * The application opens on the sign-in screen.
     *
     * The marketing site used to sit at `''` and share this bundle, so
     * somebody reading the pricing page downloaded the course player, the
     * assessment engine and every administration screen before deciding
     * whether they wanted an account. It is its own application now, in
     * apps/public, and nothing here knows about it.
     */
    path: '',
    pathMatch: 'full',
    redirectTo: 'login',
  },
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
    /**
     * Where Google and Microsoft send people back to.
     *
     * One address for the platform, registered once with each provider.
     * Which organisation a sign-in belongs to is carried by the `state`,
     * not by this path.
     */
    path: 'sso/callback',
    loadComponent: () =>
      import('./public/sso-callback/sso-callback.component').then((m) => m.SsoCallbackComponent),
    title: 'Signing in'
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
  {
    path: 'courses/create',
    loadComponent: () =>
      import('./admin/courses/course-edit/course-edit.component').then(m => m.CourseEditComponent),
    title: 'New Course',
  },
  {
    path: 'courses/:id/edit',
    loadComponent: () =>
      import('./admin/courses/course-edit/course-edit.component').then(m => m.CourseEditComponent),
    title: 'Edit Course',
  },
  {
    // Modules and lessons. Was reached as `/manageCourse?id=`, which put the
    // course id in a query string on a screen that is entirely about one
    // course — and left a second copy of it inside a dialog on the list.
    path: 'courses/:id/content',
    loadComponent: () =>
      import('./admin/courses/course-content/course-content.component')
        .then(m => m.CourseContentComponent),
    title: 'Course Content',
  },
  {
    path: 'enrollment',
    loadComponent: () =>
      import('./admin/courses/course-enrollment/course-enrollment.component')
        .then(m => m.CourseEnrollmentComponent),
    title: 'Course Enrollment',
  },
  {
    // How one course is going. Composed from the reporting module rather than
    // a second analytics store — see CourseInsightsComponent.
    path: 'courses/:id/insights',
    loadComponent: () =>
      import('./admin/courses/course-insights/course-insights.component')
        .then(m => m.CourseInsightsComponent),
    title: 'Course Insights',
  },
  {
    // Who is on one course, and how they are getting on.
    path: 'courses/:id/enrollees',
    loadComponent: () =>
      import('./admin/courses/course-enrollment/enrollees/course-enrollees.component')
        .then(m => m.CourseEnrolleesComponent),
    title: 'Course Enrolments',
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
    // Was a second user-detail screen, reached with `?id=`. It and
    // `/admin/users/:id` showed the same person from two different services
    // and disagreed about their roles. One survives.
    path: 'manageUsers',
    redirectTo: 'admin/users',
    pathMatch: 'full',
  },
  {
    // The content builder used to live here behind `?id=`, and a second copy
    // of it lived in a dialog on the course list. One survives, addressed by
    // the course it edits.
    path: 'manageCourse',
    redirectTo: 'courses',
    pathMatch: 'full',
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
    // The bell in the top bar has linked here since it was written; the
    // route never existed, so "View all notifications" went nowhere.
    path: 'notifications',
    component: NotificationsComponent,
    title: 'Notifications'
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
  },
  {
    /**
     * An organisation's own registration link, which is what an
     * administrator hands out. Declared last on purpose: a leading
     * `:tenantId` matches any single segment, so anywhere earlier it would
     * swallow `/login` and the rest.
     *
     * The bare `/register` above still resolves, and says the link names no
     * organisation — which is more use than a form that cannot be submitted.
     */
    path: ':tenantId/register',
    component: RegisterComponent,
    title: 'Register'
  },
  {
    /**
     * An organisation's own sign-in link.
     *
     * The same screen as `/login`, and it adds the buttons for whichever
     * directories that organisation accepts. `/login` cannot show them:
     * an email and a password identify an organisation on their own, and
     * a Google account does not.
     */
    path: ':tenantId/login',
    component: LoginComponent,
    title: 'Login'
  },
];
