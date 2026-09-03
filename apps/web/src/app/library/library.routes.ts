import { Routes } from '@angular/router';

/**
 * The digital library — two screens on one module.
 *
 * `/library` is what a learner sees: the files shared with them. `/admin/library`
 * is where an administrator shares and manages them. Both are lazy, and the
 * viewer they share is lazy inside them.
 */
export const libraryRoutes: Routes = [
  {
    path: 'library',
    loadComponent: () =>
      import('./components/library-browse/library-browse.component').then((m) => m.LibraryBrowseComponent),
    title: 'Digital Library',
  },
  {
    path: 'admin/library',
    loadComponent: () =>
      import('./components/library-manage/library-manage.component').then((m) => m.LibraryManageComponent),
    title: 'Digital Library',
  },
];
