import { CanDeactivateFn, Routes } from '@angular/router';

/** A page that may have work in flight it wants to confirm before leaving. */
interface LeaveAware {
  canLeave?: () => boolean | Promise<boolean>;
}

/** Asks before leaving the manage page while uploads are still running. */
const confirmLeaveWhileUploading: CanDeactivateFn<LeaveAware> = (component) =>
  component?.canLeave ? component.canLeave() : true;

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
    canDeactivate: [confirmLeaveWhileUploading],
    title: 'Digital Library',
  },
];
