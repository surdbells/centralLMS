// course-enrollment/course-enrollees.component.ts

import { Component, OnInit, OnDestroy, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import {
  CourseEnrollmentService,
  Course,
  Enrollee,
  EnrollmentStats,
  User
} from '../course-enrollment.service';

type StatusFilter = 'all' | 'not_started' | 'in_progress' | 'completed' | 'dropped';

@Component({
  selector: 'app-course-enrollees',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './course-enrollees.component.html',
  styleUrls: ['./course-enrollees.component.scss']
})
export class CourseEnrolleesComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private searchSubject$ = new Subject<string>();

  // Input - can be set externally or selected from list
  @Input() courseId: number | null = null;

  // Data
  courses: Course[] = [];
  selectedCourse: Course | null = null;
  enrollees: Enrollee[] = [];
  stats: EnrollmentStats | null = null;
  unenrolledUsers: User[] = [];

  // Selection
  selectedEnrollees: Set<number> = new Set();
  selectAll = false;

  // Filters & Pagination
  statusFilter: StatusFilter = 'all';
  currentPage = 1;
  pageSize = 20;
  totalPages = 1;
  totalEnrollees = 0;

  // Search
  courseSearch = '';
  userSearch = '';

  // UI State
  loading = false;
  loadingEnrollees = false;
  loadingStats = false;
  loadingUnenrolled = false;
  processing = false;

  showCourseSelector = true;
  showAddUsersModal = false;
  showDueDateModal = false;
  showConfirmModal = false;

  // Modal Data
  confirmAction: 'unenroll' | 'mandatory' | 'optional' | null = null;
  newDueDate: string = '';
  selectedUsersToAdd: User[] = [];

  // Messages
  successMessage = '';
  errorMessage = '';

  constructor(private enrollmentService: CourseEnrollmentService) {}

  ngOnInit(): void {
    this.loadCourses();
    this.setupSearch();

    // If courseId provided as input, load that course
    if (this.courseId) {
      this.loadCourseById(this.courseId);
    }
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // INITIALIZATION
  // ==========================================

  private loadCourses(): void {
    this.loading = true;
    this.enrollmentService.getCourses()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => {
          this.courses = courses;
          this.loading = false;
        },
        error: () => this.loading = false
      });
  }

  private setupSearch(): void {
    this.searchSubject$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(search => {
      this.loadUnenrolledUsers(search);
    });
  }

  private loadCourseById(courseId: number): void {
    const course = this.courses.find(c => c.course_id === courseId);
    if (course) {
      this.selectCourse(course);
    } else {
      // Course not in list yet, wait for courses to load
      this.enrollmentService.getCourses()
        .pipe(takeUntil(this.destroy$))
        .subscribe(courses => {
          this.courses = courses;
          const found = courses.find(c => c.course_id === courseId);
          if (found) {
            this.selectCourse(found);
          }
        });
    }
  }

  // ==========================================
  // COURSE SELECTION
  // ==========================================

  selectCourse(course: Course): void {
    this.selectedCourse = course;
    this.showCourseSelector = false;
    this.resetState();
    this.loadCourseData();
  }

  changeCourse(): void {
    this.selectedCourse = null;
    this.showCourseSelector = true;
    this.resetState();
  }

  filterCourses(): Course[] {
    if (!this.courseSearch.trim()) return this.courses;
    const search = this.courseSearch.toLowerCase();
    return this.courses.filter(c =>
      c.title.toLowerCase().includes(search) ||
      c.category_name?.toLowerCase().includes(search)
    );
  }

  private resetState(): void {
    this.enrollees = [];
    this.stats = null;
    this.selectedEnrollees.clear();
    this.selectAll = false;
    this.statusFilter = 'all';
    this.currentPage = 1;
    this.clearMessages();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  private loadCourseData(): void {
    if (!this.selectedCourse) return;
    this.loadStats();
    this.loadEnrollees();
  }

  private loadStats(): void {
    if (!this.selectedCourse) return;

    this.loadingStats = true;
    this.enrollmentService.getCourseStats(this.selectedCourse.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (stats) => {
          this.stats = stats;
          this.loadingStats = false;
        },
        error: () => this.loadingStats = false
      });
  }

  loadEnrollees(): void {
    if (!this.selectedCourse) return;

    this.loadingEnrollees = true;
    this.selectedEnrollees.clear();
    this.selectAll = false;

    const status = this.statusFilter === 'all' ? undefined : this.statusFilter;

    this.enrollmentService.getCourseEnrollees(
      this.selectedCourse.course_id,
      status,
      this.currentPage,
      this.pageSize
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.enrollees = result.enrollees;
          this.totalEnrollees = result.pagination.total;
          this.totalPages = result.pagination.total_pages;
          this.loadingEnrollees = false;
        },
        error: () => this.loadingEnrollees = false
      });
  }

  private loadUnenrolledUsers(search?: string): void {
    if (!this.selectedCourse) return;

    this.loadingUnenrolled = true;
    this.enrollmentService.getUnenrolledUsers(this.selectedCourse.course_id, search)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (users) => {
          this.unenrolledUsers = users;
          this.loadingUnenrolled = false;
        },
        error: () => this.loadingUnenrolled = false
      });
  }

  // ==========================================
  // SELECTION
  // ==========================================

  toggleSelectAll(): void {
    if (this.selectAll) {
      this.enrollees.forEach(e => this.selectedEnrollees.add(e.user_id));
    } else {
      this.selectedEnrollees.clear();
    }
  }

  toggleEnrolleeSelection(enrollee: Enrollee): void {
    if (this.selectedEnrollees.has(enrollee.user_id)) {
      this.selectedEnrollees.delete(enrollee.user_id);
    } else {
      this.selectedEnrollees.add(enrollee.user_id);
    }
    this.updateSelectAllState();
  }

  isSelected(enrollee: Enrollee): boolean {
    return this.selectedEnrollees.has(enrollee.user_id);
  }

  private updateSelectAllState(): void {
    this.selectAll = this.enrollees.length > 0 &&
                     this.enrollees.every(e => this.selectedEnrollees.has(e.user_id));
  }

  get selectedCount(): number {
    return this.selectedEnrollees.size;
  }

  // ==========================================
  // FILTERS & PAGINATION
  // ==========================================

  onStatusFilterChange(): void {
    this.currentPage = 1;
    this.loadEnrollees();
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages) return;
    this.currentPage = page;
    this.loadEnrollees();
  }

  get pageNumbers(): number[] {
    const pages: number[] = [];
    const maxVisible = 5;
    let start = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
    let end = Math.min(this.totalPages, start + maxVisible - 1);

    if (end - start + 1 < maxVisible) {
      start = Math.max(1, end - maxVisible + 1);
    }

    for (let i = start; i <= end; i++) {
      pages.push(i);
    }
    return pages;
  }

  // ==========================================
  // BULK ACTIONS
  // ==========================================

  // Unenroll Users
  initiateUnenroll(): void {
    if (this.selectedCount === 0) return;
    this.confirmAction = 'unenroll';
    this.showConfirmModal = true;
  }

  confirmUnenroll(): void {
    if (!this.selectedCourse || this.selectedCount === 0) return;

    this.processing = true;
    const userIds = Array.from(this.selectedEnrollees);

    this.enrollmentService.unenrollUsers(
      this.selectedCourse.course_id,
      userIds,
      'Admin removed'
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.showSuccess(`${result.unenrolled?.length || 0} user(s) unenrolled successfully`);
          this.closeConfirmModal();
          this.loadCourseData();
          this.processing = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to unenroll users');
          this.processing = false;
        }
      });
  }

  // Set Mandatory
  initiateMandatory(): void {
    if (this.selectedCount === 0) return;
    this.confirmAction = 'mandatory';
    this.showConfirmModal = true;
  }

  initiateOptional(): void {
    if (this.selectedCount === 0) return;
    this.confirmAction = 'optional';
    this.showConfirmModal = true;
  }

  confirmMandatoryChange(): void {
    if (!this.selectedCourse || this.selectedCount === 0) return;

    this.processing = true;
    const userIds = Array.from(this.selectedEnrollees);
    const isMandatory = this.confirmAction === 'mandatory';

    this.enrollmentService.setMandatory(
      this.selectedCourse.course_id,
      userIds,
      isMandatory
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.showSuccess(`${result.affected_count || 0} enrollment(s) updated`);
          this.closeConfirmModal();
          this.loadEnrollees();
          this.processing = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to update enrollments');
          this.processing = false;
        }
      });
  }

  // Update Due Date
  openDueDateModal(): void {
    if (this.selectedCount === 0) return;
    this.newDueDate = '';
    this.showDueDateModal = true;
  }

  confirmDueDateUpdate(): void {
    if (!this.selectedCourse || this.selectedCount === 0) return;

    this.processing = true;
    const userIds = Array.from(this.selectedEnrollees);
    const dueDate = this.newDueDate || null;

    this.enrollmentService.updateDueDate(
      this.selectedCourse.course_id,
      userIds,
      dueDate
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.showSuccess(`${result.affected_count || 0} enrollment(s) updated`);
          this.closeDueDateModal();
          this.loadEnrollees();
          this.processing = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to update due dates');
          this.processing = false;
        }
      });
  }

  // ==========================================
  // ADD USERS
  // ==========================================

  openAddUsersModal(): void {
    this.selectedUsersToAdd = [];
    this.userSearch = '';
    this.loadUnenrolledUsers();
    this.showAddUsersModal = true;
  }

  onUserSearchChange(): void {
    this.searchSubject$.next(this.userSearch);
  }

  toggleUserToAdd(user: User): void {
    const index = this.selectedUsersToAdd.findIndex(u => u.id === user.id);
    if (index === -1) {
      this.selectedUsersToAdd.push(user);
    } else {
      this.selectedUsersToAdd.splice(index, 1);
    }
  }

  isUserToAddSelected(user: User): boolean {
    return this.selectedUsersToAdd.some(u => u.id === user.id);
  }

  removeUserToAdd(user: User): void {
    this.selectedUsersToAdd = this.selectedUsersToAdd.filter(u => u.id !== user.id);
  }

  confirmAddUsers(): void {
    if (!this.selectedCourse || this.selectedUsersToAdd.length === 0) return;

    this.processing = true;
    const userIds = this.selectedUsersToAdd.map(u => u.id);

    this.enrollmentService.enrollUsers({
      course_id: this.selectedCourse.course_id,
      enrollment_type: 'users',
      user_ids: userIds,
      is_mandatory: false,
      notify_users: true
    }).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.showSuccess(`${result.summary.enrolled_count} user(s) enrolled successfully`);
          this.closeAddUsersModal();
          this.loadCourseData();
          this.processing = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to enroll users');
          this.processing = false;
        }
      });
  }

  // ==========================================
  // MODAL HANDLING
  // ==========================================

  closeConfirmModal(): void {
    this.showConfirmModal = false;
    this.confirmAction = null;
  }

  closeDueDateModal(): void {
    this.showDueDateModal = false;
    this.newDueDate = '';
  }

  closeAddUsersModal(): void {
    this.showAddUsersModal = false;
    this.selectedUsersToAdd = [];
    this.userSearch = '';
  }

  // ==========================================
  // MESSAGES
  // ==========================================

  private showSuccess(message: string): void {
    this.successMessage = message;
    this.errorMessage = '';
    setTimeout(() => this.successMessage = '', 5000);
  }

  private showError(message: string): void {
    this.errorMessage = message;
    this.successMessage = '';
  }

  private clearMessages(): void {
    this.successMessage = '';
    this.errorMessage = '';
  }

  // ==========================================
  // HELPERS
  // ==========================================

  Math = Math; // For template access

  getStatusBadgeClass(status: string): string {
    const classes: Record<string, string> = {
      'not_started': 'bg-secondary',
      'in_progress': 'bg-primary',
      'completed': 'bg-success',
      'dropped': 'bg-danger'
    };
    return classes[status] || 'bg-secondary';
  }

  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      'not_started': 'Not Started',
      'in_progress': 'In Progress',
      'completed': 'Completed',
      'dropped': 'Dropped'
    };
    return labels[status] || status;
  }

  formatDate(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString();
  }

  formatProgress(progress: number): string {
    return `${Math.round(progress || 0)}%`;
  }

  isOverdue(dueDate: string): boolean {
    if (!dueDate) return false;
    const due = new Date(dueDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return due < today;
  }
}
