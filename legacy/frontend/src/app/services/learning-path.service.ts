// learning-path/learning-path.service.ts
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { GlobalComponent } from '../global-component';
import {
  LearningPath,
  LearningPathSection,
  LearningPathItem,
  LearningPathEnrollment,
  LearningPathItemProgress,
  LearningPathReview,
  LearningPathAssignment,
  LearningPathCreateRequest,
  LearningPathUpdateRequest,
  SectionCreateRequest,
  SectionUpdateRequest,
  ItemCreateRequest,
  ItemUpdateRequest,
  ReviewCreateRequest,
  AssignmentCreateRequest,
  LearningPathListParams,
  EnrollmentListParams,
  ApiResponse,
  PaginatedResponse,
  LearningPathStatistics,
  RatingSummary,
  ProgressBySection,
  EnrollmentStatistics
} from '../interfaces/learning-path.interfaces';

@Injectable({
  providedIn: 'root'
})
export class LearningPathService {
  private apiUrl = GlobalComponent.learning_path;

  session_data: any = "";
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  constructor(private http: HttpClient) {}

  private getAuthPayload(): any {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }

  // ============================================
  // LEARNING PATH CRUD
  // ============================================

  list(params?: LearningPathListParams): Observable<PaginatedResponse<LearningPath>> {
    return this.http.post<ApiResponse<PaginatedResponse<LearningPath>>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-paths',
      ...params
    }).pipe(map(res => res.data));
  }

  getCatalog(params?: LearningPathListParams): Observable<PaginatedResponse<LearningPath>> {
    return this.http.post<ApiResponse<PaginatedResponse<LearningPath>>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-catalog',
      ...params
    }).pipe(map(res => res.data));
  }

  getById(pathId: number): Observable<LearningPath> {
    return this.http.post<ApiResponse<LearningPath>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-path',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  getBySlug(slug: string): Observable<LearningPath> {
    return this.http.post<ApiResponse<LearningPath>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-path-by-slug',
      slug
    }).pipe(map(res => res.data));
  }

  getPathDetails(pathId: number): Observable<{
    path: LearningPath;
    sections: LearningPathSection[];
    enrollment: LearningPathEnrollment | null;
    progress: ProgressBySection | null;
  }> {
    return this.http.post<ApiResponse<{
      path: LearningPath;
      sections: LearningPathSection[];
      enrollment: LearningPathEnrollment | null;
      progress: ProgressBySection | null;
    }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-path-details',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  create(data: LearningPathCreateRequest): Observable<{ path_id: number }> {
    return this.http.post<ApiResponse<{ path_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-path',
      ...data
    }).pipe(map(res => res.data));
  }

  update(pathId: number, data: LearningPathUpdateRequest): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-path',
      path_id: pathId,
      ...data
    }).pipe(map(() => {}));
  }

  delete(pathId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-path',
      path_id: pathId
    }).pipe(map(() => {}));
  }

  publish(pathId: number): Observable<{ errors?: string[] }> {
    return this.http.post<ApiResponse<{ errors?: string[] }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'publish-path',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  archive(pathId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'archive-path',
      path_id: pathId
    }).pipe(map(() => {}));
  }

  duplicate(pathId: number, newTitle?: string): Observable<{ path_id: number }> {
    return this.http.post<ApiResponse<{ path_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'duplicate-path',
      path_id: pathId,
      new_title: newTitle
    }).pipe(map(res => res.data));
  }

  getStatistics(pathId: number): Observable<LearningPathStatistics> {
    return this.http.post<ApiResponse<LearningPathStatistics>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-path-statistics',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  // ============================================
  // SECTIONS
  // ============================================

  getSections(pathId: number, includeItems = false): Observable<LearningPathSection[]> {
    return this.http.post<ApiResponse<LearningPathSection[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-sections',
      path_id: pathId,
      include_items: includeItems
    }).pipe(map(res => res.data));
  }

  getSectionsWithItems(pathId: number): Observable<LearningPathSection[]> {
    return this.http.post<ApiResponse<LearningPathSection[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-sections',
      path_id: pathId,
      include_items: true
    }).pipe(map(res => res.data));
  }

  createSection(data: SectionCreateRequest): Observable<{ section_id: number }> {
    return this.http.post<ApiResponse<{ section_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-section',
      ...data
    }).pipe(map(res => res.data));
  }

  updateSection(sectionId: number, data: SectionUpdateRequest): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-section',
      section_id: sectionId,
      ...data
    }).pipe(map(() => {}));
  }

  deleteSection(sectionId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-section',
      section_id: sectionId
    }).pipe(map(() => {}));
  }

  reorderSections(sections: { section_id: number; sort_order: number }[]): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'reorder-sections',
      sections
    }).pipe(map(() => {}));
  }

  // ============================================
  // ITEMS
  // ============================================

  getItems(pathId: number): Observable<LearningPathItem[]> {
    return this.http.post<ApiResponse<LearningPathItem[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-items',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  createItem(data: ItemCreateRequest): Observable<{ item_id: number }> {
    return this.http.post<ApiResponse<{ item_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-item',
      ...data
    }).pipe(map(res => res.data));
  }

  addCourse(pathId: number, courseId: number, sectionId?: number): Observable<{ item_id: number }> {
    return this.http.post<ApiResponse<{ item_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'add-course',
      path_id: pathId,
      course_id: courseId,
      section_id: sectionId
    }).pipe(map(res => res.data));
  }

  addAssessment(pathId: number, assessmentId: number, sectionId?: number): Observable<{ item_id: number }> {
    return this.http.post<ApiResponse<{ item_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'add-assessments',
      path_id: pathId,
      assessment_id: assessmentId,
      section_id: sectionId
    }).pipe(map(res => res.data));
  }

  addExternalLink(pathId: number, url: string, title: string, description?: string, sectionId?: number): Observable<{ item_id: number }> {
    return this.http.post<ApiResponse<{ item_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'add-external-link',
      path_id: pathId,
      url,
      title,
      description,
      section_id: sectionId
    }).pipe(map(res => res.data));
  }

  updateItem(itemId: number, data: ItemUpdateRequest): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-item',
      item_id: itemId,
      ...data
    }).pipe(map(() => {}));
  }

  deleteItem(pathId: number, itemId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-item',
      path_id: pathId,
      item_id: itemId
    }).pipe(map(() => {}));
  }

  reorderItems(pathId: number, items: { item_id: number; section_id?: number | null; sort_order: number }[]): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'reorder-items',
      path_id: pathId,
      items
    }).pipe(map(() => {}));
  }

  // ============================================
  // ENROLLMENTS
  // ============================================

  enroll(pathId: number): Observable<{ enrollment_id: number }> {
    return this.http.post<ApiResponse<{ enrollment_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'enroll',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  enrollUser(pathId: number, userId: number, dueDate?: string): Observable<{ enrollment_id: number }> {
    return this.http.post<ApiResponse<{ enrollment_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'enroll-user',
      path_id: pathId,
      target_user_id: userId,
      due_date: dueDate
    }).pipe(map(res => res.data));
  }

  bulkEnroll(pathId: number, userIds: number[], dueDate?: string): Observable<{ enrolled: number; skipped: number }> {
    return this.http.post<ApiResponse<{ enrolled: number; skipped: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'bulk-enroll',
      path_id: pathId,
      user_ids: userIds,
      due_date: dueDate
    }).pipe(map(res => res.data));
  }

  getEnrollments(pathId: number, params?: EnrollmentListParams): Observable<PaginatedResponse<LearningPathEnrollment>> {
    return this.http.post<ApiResponse<PaginatedResponse<LearningPathEnrollment>>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-enrollments',
      path_id: pathId,
      ...params
    }).pipe(map(res => res.data));
  }

  getMyEnrollment(pathId: number): Observable<LearningPathEnrollment> {
    return this.http.post<ApiResponse<LearningPathEnrollment>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-my-enrollment',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  getMyEnrollments(): Observable<LearningPathEnrollment[]> {
    return this.http.post<ApiResponse<LearningPathEnrollment[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-my-enrollments'
    }).pipe(map(res => res.data));
  }

  startPath(pathId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'start-path',
      path_id: pathId
    }).pipe(map(() => {}));
  }

  withdraw(pathId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'withdraw',
      path_id: pathId
    }).pipe(map(() => {}));
  }

  suspendEnrollment(enrollmentId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'suspend-enrollment',
      enrollment_id: enrollmentId
    }).pipe(map(() => {}));
  }

  resumeEnrollment(enrollmentId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'resume-enrollment',
      enrollment_id: enrollmentId
    }).pipe(map(() => {}));
  }

  extendDueDate(enrollmentId: number, dueDate: string, reason?: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'extend-due-date',
      enrollment_id: enrollmentId,
      due_date: dueDate,
      reason
    }).pipe(map(() => {}));
  }

  removeEnrollment(enrollmentId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'remove-enrollment',
      enrollment_id: enrollmentId
    }).pipe(map(() => {}));
  }

  getEnrollmentStatistics(pathId: number): Observable<EnrollmentStatistics> {
    return this.http.post<ApiResponse<EnrollmentStatistics>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-enrollment-statistics',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  checkPrerequisites(pathId: number): Observable<{ eligible: boolean; missing: any[] }> {
    return this.http.post<ApiResponse<{ eligible: boolean; missing: any[] }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'check-prerequisites',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  searchUsersForEnrollment(pathId: number, filters: any): Observable<any[]> {
    return this.http.post<ApiResponse<any[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'search-users-for-enrollment',
      path_id: pathId,
      ...filters
    }).pipe(map(res => res.data));
  }

  // ============================================
  // PROGRESS
  // ============================================

  getMyProgress(pathId: number): Observable<ProgressBySection> {
    return this.http.post<ApiResponse<ProgressBySection>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-my-progress',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  getUserProgress(pathId: number, targetUserId: number): Observable<ProgressBySection> {
    return this.http.post<ApiResponse<ProgressBySection>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-user-progress',
      path_id: pathId,
      target_user_id: targetUserId
    }).pipe(map(res => res.data));
  }

  startItem(pathId: number, itemId: number): Observable<LearningPathItemProgress> {
    return this.http.post<ApiResponse<LearningPathItemProgress>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'start-item',
      path_id: pathId,
      item_id: itemId
    }).pipe(map(res => res.data));
  }

  completeItem(pathId: number, itemId: number, score?: number): Observable<{
    success: boolean;
    points_earned?: number;
    path_completed?: boolean;
    certificate_id?: number;
  }> {
    return this.http.post<ApiResponse<{
      success: boolean;
      points_earned?: number;
      path_completed?: boolean;
      certificate_id?: number;
    }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'complete-item',
      path_id: pathId,
      item_id: itemId,
      score
    }).pipe(map(res => res.data));
  }

  skipItem(pathId: number, itemId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'skip-item',
      path_id: pathId,
      item_id: itemId
    }).pipe(map(() => {}));
  }

  updateTimeSpent(pathId: number, itemId: number, minutes: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-time-spent',
      path_id: pathId,
      item_id: itemId,
      minutes
    }).pipe(map(() => {}));
  }

  resetItemProgress(enrollmentId: number, itemId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'reset-item-progress',
      enrollment_id: enrollmentId,
      item_id: itemId
    }).pipe(map(() => {}));
  }

  unlockItem(enrollmentId: number, itemId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'unlock-item',
      enrollment_id: enrollmentId,
      item_id: itemId
    }).pipe(map(() => {}));
  }

  // ============================================
  // REVIEWS
  // ============================================

  getReviews(pathId: number, page = 1): Observable<PaginatedResponse<LearningPathReview>> {
    return this.http.post<ApiResponse<PaginatedResponse<LearningPathReview>>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-reviews',
      path_id: pathId,
      page
    }).pipe(map(res => res.data));
  }

  getRatingSummary(pathId: number): Observable<RatingSummary> {
    return this.http.post<ApiResponse<RatingSummary>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-rating-summary',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  createReview(data: ReviewCreateRequest): Observable<{ review_id: number }> {
    return this.http.post<ApiResponse<{ review_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-review',
      ...data
    }).pipe(map(res => res.data));
  }

  updateReview(reviewId: number, data: Partial<ReviewCreateRequest>): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-review',
      review_id: reviewId,
      ...data
    }).pipe(map(() => {}));
  }

  deleteReview(reviewId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-review',
      review_id: reviewId
    }).pipe(map(() => {}));
  }

  approveReview(reviewId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'approve-review',
      review_id: reviewId
    }).pipe(map(() => {}));
  }

  rejectReview(reviewId: number, reason?: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'reject-review',
      review_id: reviewId,
      reason
    }).pipe(map(() => {}));
  }

  respondToReview(reviewId: number, response: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'respond-to-review',
      review_id: reviewId,
      response
    }).pipe(map(() => {}));
  }

  markReviewHelpful(reviewId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'mark-review-helpful',
      review_id: reviewId
    }).pipe(map(() => {}));
  }

  reportReview(reviewId: number, reason: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'report-review',
      review_id: reviewId,
      reason
    }).pipe(map(() => {}));
  }

  getPendingReviews(page = 1): Observable<PaginatedResponse<LearningPathReview>> {
    return this.http.post<ApiResponse<PaginatedResponse<LearningPathReview>>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-pending-reviews',
      page
    }).pipe(map(res => res.data));
  }

  // ============================================
  // ASSIGNMENTS
  // ============================================

  createAssignment(data: AssignmentCreateRequest): Observable<{ assignment_id: number; enrollments_created: number }> {
    return this.http.post<ApiResponse<{ assignment_id: number; enrollments_created: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-assignment',
      ...data
    }).pipe(map(res => res.data));
  }

  getAssignments(pathId: number): Observable<LearningPathAssignment[]> {
    return this.http.post<ApiResponse<LearningPathAssignment[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-assignments',
      path_id: pathId
    }).pipe(map(res => res.data));
  }

  cancelAssignment(assignmentId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'cancel-assignment',
      assignment_id: assignmentId
    }).pipe(map(() => {}));
  }

  pauseAssignment(assignmentId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'pause-assignment',
      assignment_id: assignmentId
    }).pipe(map(() => {}));
  }

  resumeAssignment(assignmentId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'resume-assignment',
      assignment_id: assignmentId
    }).pipe(map(() => {}));
  }

  getAssignmentStatistics(assignmentId: number): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-assignment-statistics',
      assignment_id: assignmentId
    }).pipe(map(res => res.data));
  }

  sendAssignmentReminders(assignmentId: number): Observable<{ sent: number }> {
    return this.http.post<ApiResponse<{ sent: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'send-assignment-reminders',
      assignment_id: assignmentId
    }).pipe(map(res => res.data));
  }

  // ============================================
  // HELPER - Get Users for dropdowns
  // ============================================

  getUsers(search?: string): Observable<any[]> {
    return this.http.post<ApiResponse<any[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-users',
      search: search || ''
    }).pipe(map(res => res.data));
  }

  // ============================================
  // BUILDER HELPERS - Get Courses & Assessments
  // ============================================

  /**
   * Get available courses for adding to learning path
   */
  getAvailableCourses(search?: string): Observable<any[]> {
    return this.http.post<ApiResponse<any[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-available-courses',
      search: search || '',
      status: 'published'
    }).pipe(map(res => res.data));
  }

  /**
   * Get available assessments for adding to learning path
   */
  getAvailableAssessments(search?: string): Observable<any[]> {
    return this.http.post<ApiResponse<any[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-available-assessments',
      search: search || '',
      status: 'published'
    }).pipe(map(res => res.data));
  }
}
