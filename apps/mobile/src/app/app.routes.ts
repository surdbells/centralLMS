import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'tabs',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/tabs/tabs.page').then((m) => m.TabsPage),
    children: [
      {
        path: 'home',
        loadComponent: () => import('./pages/home/home.page').then((m) => m.HomePage),
      },
      {
        path: 'courses',
        loadComponent: () => import('./pages/courses/courses.page').then((m) => m.CoursesPage),
      },
      {
        path: 'progress',
        loadComponent: () => import('./pages/progress/progress.page').then((m) => m.ProgressPage),
      },
      {
        path: 'profile',
        loadComponent: () => import('./pages/profile/profile.page').then((m) => m.ProfilePage),
      },
      { path: '', redirectTo: 'home', pathMatch: 'full' },
    ],
  },
  {
    path: 'courses/:id',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./pages/course-detail/course-detail.page').then((m) => m.CourseDetailPage),
  },
  {
    path: 'courses/:courseId/lessons/:lessonId',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/lesson/lesson.page').then((m) => m.LessonPage),
  },
  {
    path: 'leaderboard',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/leaderboard/leaderboard.page').then((m) => m.LeaderboardPage),
  },
  {
    path: 'certificates',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/certificates/certificates.page').then((m) => m.CertificatesPage),
  },
  {
    path: 'library',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/library/library.page').then((m) => m.LibraryPage),
  },
  {
    path: 'achievements',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/achievements/achievements.page').then((m) => m.AchievementsPage),
  },
  {
    path: 'assessments',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/assessments/assessments.page').then((m) => m.AssessmentsPage),
  },
  {
    path: 'assessments/:id',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/assessments/assessment-detail.page').then((m) => m.AssessmentDetailPage),
  },
  {
    path: 'paths',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/paths/paths.page').then((m) => m.PathsPage),
  },
  {
    path: 'paths/:id',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/paths/path-detail.page').then((m) => m.PathDetailPage),
  },
  { path: '', redirectTo: 'tabs/home', pathMatch: 'full' },
  { path: '**', redirectTo: 'tabs/home' },
];
