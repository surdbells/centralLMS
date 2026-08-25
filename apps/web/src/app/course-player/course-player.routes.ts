// course-player/course-player.routes.ts

import { Routes } from '@angular/router';
export const COURSE_PLAYER_ROUTES: Routes = [
  {
    path: ':courseId',
    loadComponent: () => import('./components/course-player/course-player.component')
      .then(m => m.CoursePlayerComponent),
    title: 'Course Player'
  }
];

export default COURSE_PLAYER_ROUTES;
