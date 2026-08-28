import { Routes } from '@angular/router';

export const learningPathRoutes: Routes = [
  // ============================================
  // ADMIN ROUTES
  // ============================================
  {
    path: 'admin/learning-paths',
    children: [
      {
        path: '',
        loadComponent: () => import('./admin/learning-paths-list/learning-paths-list.component')
          .then(m => m.LearningPathsListComponent),
        title: 'Learning Paths'
      },
      {
        path: 'create',
        loadComponent: () => import('./admin/learning-path-form/learning-path-form.component')
          .then(m => m.LearningPathFormComponent),
        title: 'Create Learning Path',
        data: { mode: 'create' }
      },
      {
        // Before `:id`, or it is read as a path id and nothing is found.
        path: 'moderation',
        loadComponent: () => import('./admin/review-moderation/review-moderation.component')
          .then(m => m.ReviewModerationComponent),
        title: 'Review Moderation'
      },
      {
        path: ':id',
        loadComponent: () => import('./admin/learning-path-detail/learning-path-detail.component')
          .then(m => m.LearningPathDetailComponent),
        title: 'Learning Path Details'
      },
      {
        path: ':id/edit',
        loadComponent: () => import('./admin/learning-path-form/learning-path-form.component')
          .then(m => m.LearningPathFormComponent),
        title: 'Edit Learning Path',
        data: { mode: 'edit' }
      },
      {
        path: ':id/builder',
        loadComponent: () => import('./admin/learning-path-builder/learning-path-builder.component')
          .then(m => m.LearningPathBuilderComponent),
        title: 'Learning Path Builder'
      },
      {
        path: ':id/enrollments',
        loadComponent: () => import('./admin/learning-path-enrollments/learning-path-enrollments.component')
          .then(m => m.LearningPathEnrollmentComponent),
        title: 'Enrollments'
      },
      {
        path: ':id/assignments',
        loadComponent: () => import('./admin/learning-path-assignments/learning-path-assignments.component')
          .then(m => m.LearningPathAssignmentsComponent),
        title: 'Assignments'
      },
      {
        path: ':id/reviews',
        loadComponent: () => import('./admin/learning-path-reviews/learning-path-reviews.component')
          .then(m => m.LearningPathReviewsComponent),
        title: 'Reviews'
      }
    ]
  },

  // ============================================
  // LEARNER ROUTES
  // ============================================
  {
    path: 'learner/paths',
    children: [
      {
        path: '',
        loadComponent: () => import('./learner/learning-path-catalog/learning-path-catalog.component')
          .then(m => m.LearningPathCatalogComponent),
        title: 'Learning Path Catalog'
      },
      {
        path: 'my',
        loadComponent: () => import('./learner/my-learning-paths/my-learning-paths.component')
          .then(m => m.MyLearningPathsComponent),
        title: 'My Learning Paths'
      },
      {
        path: ':id',
        loadComponent: () => import('./learner/learning-path-viewer/learning-path-viewer.component')
          .then(m => m.LearningPathViewerComponent),
        title: 'Learning Path'
      },
      {
        path: ':id/items/:itemId',
        loadComponent: () => import('./learner/learning-path-item-viewer/learning-path-item-viewer.component')
          .then(m => m.LearningPathItemViewerComponent),
        title: 'Learning Item'
      },
      {
        path: 'slug/:slug',
        loadComponent: () => import('./learner/learning-path-viewer/learning-path-viewer.component')
          .then(m => m.LearningPathViewerComponent),
        title: 'Learning Path'
      }
    ]
  }
];

// Export for use in app.routes.ts
// Usage: import { learningPathRoutes } from './learning-path/learning-path.routes';
// Then spread into your main routes: ...learningPathRoutes
