// learning-path/learning-path.service.ts
import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, of, throwError } from 'rxjs';
import { catchError, map, switchMap } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../core/api';
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
  ReviewStatus,
  AssignmentCreateRequest,
  LearningPathListParams,
  EnrollmentListParams,
  PaginatedResponse,
  LearningPathStatistics,
  RatingSummary,
  ProgressBySection,
  EnrollmentStatistics
} from '../interfaces/learning-path.interfaces';

/**
 * Learning paths, over the new API.
 *
 * It used to post to one legacy endpoint with sixty-odd `?action=` variants
 * and `token`, `user_id` and `tenant_id` decoded from
 * `sessionStorage.SESSION`. Ten components depend on this service, so the
 * method names and the shapes they return are kept exactly — the change is
 * entirely underneath.
 *
 * **Assignment rules have no server.** No table for them survived the
 * migration, and the methods that need one are listed at the bottom of this
 * file rather than quietly returning empty — a rule list that shows nothing
 * because the feature was never built looks exactly like one with no rules.
 *
 * A path's structure is two calls, sections and items, joined here. The API
 * keeps them apart because an item may sit outside every section, which a
 * nested response cannot express without inventing a section to hold them.
 */
@Injectable({
  providedIn: 'root',
})
export class LearningPathService {
  private readonly api = inject(ApiClient);

  // ==========================================
  // PATHS
  // ==========================================

  list(params?: LearningPathListParams): Observable<PaginatedResponse<LearningPath>> {
    return this.api
      .getPaged<PathPayload>(Endpoints.paths.list, toQuery(params))
      .pipe(map((page) => ({
        data: page.items.map(toPath),
        pagination: {
          page: page.meta.page,
          per_page: page.meta.perPage,
          total: page.meta.total,
          total_pages: page.meta.totalPages,
        },
      })));
  }

  getCatalog(params?: LearningPathListParams): Observable<PaginatedResponse<LearningPath>> {
    return this.api
      .getPaged<PathPayload>(Endpoints.paths.catalogue, toQuery(params))
      .pipe(map((page) => ({
        data: page.items.map(toPath),
        pagination: {
          page: page.meta.page,
          per_page: page.meta.perPage,
          total: page.meta.total,
          total_pages: page.meta.totalPages,
        },
      })));
  }

  getById(pathId: string): Observable<LearningPath> {
    return this.api.get<PathPayload>(Endpoints.paths.show(pathId)).pipe(map(toPath));
  }

  /**
   * A path by its slug.
   *
   * `show` takes either — the API resolves a slug the same way it resolves an
   * id, so a shared link keeps working after a rename.
   */
  getBySlug(slug: string): Observable<LearningPath> {
    return this.api.get<PathPayload>(Endpoints.paths.show(slug)).pipe(map(toPath));
  }

  /**
   * A path, its structure, and the caller's own way through it.
   *
   * The progress half fails softly: an administrator looking at a path they
   * are not enrolled on has none, and that is not an error worth failing the
   * whole screen for.
   */
  getPathDetails(pathId: string): Observable<{
    path: LearningPath;
    sections: LearningPathSection[];
    items: LearningPathItem[];
    progress: ProgressBySection | null;
    enrollment?: LearningPathEnrollment;
  }> {
    return forkJoin({
      path: this.getById(pathId),
      sections: this.getSectionsWithItems(pathId),
      items: this.getItems(pathId),
      progress: this.getMyProgress(pathId).pipe(catchError(() => of(null))),
    });
  }

  create(data: LearningPathCreateRequest): Observable<{ path_id: string }> {
    return this.api
      .post<PathPayload>(Endpoints.paths.create, fromPathRequest(data))
      .pipe(map((path) => ({ path_id: path.id })));
  }

  update(pathId: string, data: LearningPathUpdateRequest): Observable<void> {
    return this.api
      .put(Endpoints.paths.update(pathId), fromPathRequest(data))
      .pipe(map(() => undefined));
  }

  delete(pathId: string): Observable<void> {
    return this.api.delete(Endpoints.paths.delete(pathId)).pipe(map(() => undefined));
  }

  publish(pathId: string): Observable<{ errors?: string[] }> {
    // The API refuses to publish an empty path and says why, so a failure
    // here is the answer rather than a list to render beside a success.
    return this.api
      .patch(Endpoints.paths.status(pathId), { status: 'published' })
      .pipe(map(() => ({})));
  }

  archive(pathId: string): Observable<void> {
    return this.api
      .patch(Endpoints.paths.status(pathId), { status: 'archived' })
      .pipe(map(() => undefined));
  }

  /**
   * Copies a path, its sections and its items.
   *
   * There is no duplicate endpoint for paths as there is for courses, so this
   * does it in the open: create the path, recreate each section, then each
   * item against the section it landed in. Sequential on purpose — an item
   * cannot name a section that has not been created yet.
   */
  duplicate(pathId: string, newTitle?: string): Observable<{ path_id: string }> {
    return forkJoin({
      path: this.getById(pathId),
      sections: this.getSections(pathId),
      items: this.getItems(pathId),
    }).pipe(switchMap(({ path, sections, items }) =>
      this.api
        .post<PathPayload>(Endpoints.paths.create, {
          title: newTitle ?? `${path.title} (copy)`,
          summary: path.short_description,
          description: path.description,
          level: path.skill_level,
          categoryId: path.category_id,
          isMandatory: path.is_mandatory,
          // A copy arrives as a draft. One that arrived published would put an
          // unreviewed path in front of learners.
          status: 'draft',
        })
        .pipe(switchMap((copy) => this.copyInto(copy.id, sections, items)))));
  }

  private copyInto(
    pathId: string,
    sections: LearningPathSection[],
    items: LearningPathItem[],
  ): Observable<{ path_id: string }> {
    const madeSections = sections.length === 0
      ? of([] as Array<{ from: string; to: string }>)
      : forkJoin(sections.map((section) =>
        this.api
          .post<SectionPayload>(Endpoints.paths.createSection(pathId), {
            title: section.title,
            description: section.description,
            sortOrder: section.sort_order,
            isMilestone: section.is_milestone,
            minItemsToComplete: section.min_items_to_complete,
          })
          .pipe(map((made) => ({ from: section.section_id, to: made.id })))));

    return madeSections.pipe(switchMap((mapping) => {
      const sectionFor = new Map(mapping.map((m) => [m.from, m.to]));

      if (items.length === 0) {
        return of({ path_id: pathId });
      }

      return forkJoin(items.map((item) =>
        this.api.post(Endpoints.paths.createItem(pathId), {
          kind: item.item_type,
          subjectId: item.course_id ?? item.assessment_id ?? null,
          externalUrl: item.external_url === '' ? null : item.external_url,
          title: item.external_title === '' ? null : item.external_title,
          description: item.external_description === '' ? null : item.external_description,
          sectionId: item.section_id === null ? null : sectionFor.get(item.section_id) ?? null,
          sortOrder: item.sort_order,
          isRequired: item.is_required,
          pointsValue: item.points_value,
        }))).pipe(map(() => ({ path_id: pathId })));
    }));
  }

  getStatistics(pathId: string): Observable<LearningPathStatistics> {
    return forkJoin({
      path: this.getById(pathId),
      enrolments: this.api.getPaged<PathEnrolmentPayload>(
        Endpoints.paths.enrollments(pathId),
        { perPage: 200 },
      ),
    }).pipe(map(({ path, enrolments }) => toStatistics(path, enrolments.items)));
  }

  // ==========================================
  // SECTIONS
  // ==========================================

  getSections(pathId: string, _includeItems = false): Observable<LearningPathSection[]> {
    return this.api
      .get<SectionPayload[]>(Endpoints.paths.sections(pathId))
      .pipe(map((rows) => rows.map((row) => toSection(row, pathId))));
  }

  /**
   * Sections with their items nested inside them.
   *
   * The API returns the two separately because an item may belong to no
   * section at all, and a nested-only response cannot say that without
   * inventing a section to hold the orphans. The nesting happens here, where
   * the screen that wants it lives.
   */
  getSectionsWithItems(pathId: string): Observable<LearningPathSection[]> {
    return forkJoin({
      sections: this.getSections(pathId),
      items: this.getItems(pathId),
    }).pipe(map(({ sections, items }) => sections.map((section) => ({
      ...section,
      items: items.filter((item) => item.section_id === section.section_id),
    }))));
  }

  createSection(data: SectionCreateRequest): Observable<{ section_id: string }> {
    return this.api
      .post<SectionPayload>(Endpoints.paths.createSection(data.path_id), {
        title: data.title,
        description: data.description ?? null,
        sortOrder: data.sort_order ?? 0,
        isMilestone: data.is_milestone ?? false,
        minItemsToComplete: data.min_items_to_complete ?? null,
      })
      .pipe(map((section) => ({ section_id: section.id })));
  }

  updateSection(
    sectionId: string,
    data: SectionUpdateRequest & { path_id?: string },
  ): Observable<void> {
    const pathId = data.path_id ?? '';

    return this.api
      .put(Endpoints.paths.updateSection(pathId, sectionId), {
        title: data.title,
        description: data.description,
        sortOrder: data.sort_order,
        isMilestone: data.is_milestone,
        minItemsToComplete: data.min_items_to_complete,
      })
      .pipe(map(() => undefined));
  }

  deleteSection(sectionId: string, pathId = ''): Observable<void> {
    return this.api
      .delete(Endpoints.paths.deleteSection(pathId, sectionId))
      .pipe(map(() => undefined));
  }

  /**
   * Reordering sections.
   *
   * One request per section rather than a single order call: the API has no
   * section-order endpoint, only per-section updates, and `sortOrder` is what
   * a section update takes.
   */
  reorderSections(
    sections: { section_id: string; sort_order: number; path_id?: string }[],
  ): Observable<void> {
    if (sections.length === 0) {
      return of(undefined);
    }

    return forkJoin(sections.map((section) =>
      this.api.put(
        Endpoints.paths.updateSection(section.path_id ?? '', section.section_id),
        { sortOrder: section.sort_order },
      ))).pipe(map(() => undefined));
  }

  // ==========================================
  // ITEMS
  // ==========================================

  getItems(pathId: string): Observable<LearningPathItem[]> {
    return this.api
      .get<ItemPayload[]>(Endpoints.paths.items(pathId))
      .pipe(map((rows) => rows.map((row) => toItem(row, pathId))));
  }

  createItem(data: ItemCreateRequest): Observable<{ item_id: string }> {
    return this.api
      .post<ItemPayload>(Endpoints.paths.createItem(data.path_id), {
        kind: data.item_type,
        subjectId: data.course_id ?? data.assessment_id ?? null,
        externalUrl: data.external_url ?? null,
        title: data.external_title ?? null,
        description: data.external_description ?? null,
        sectionId: data.section_id ?? null,
        sortOrder: data.sort_order ?? 0,
        isRequired: data.is_required ?? true,
        pointsValue: data.points_value ?? 0,
      })
      .pipe(map((item) => ({ item_id: item.id })));
  }

  addCourse(pathId: string, courseId: string, sectionId?: string): Observable<{ item_id: string }> {
    return this.createItem({
      path_id: pathId,
      item_type: 'course',
      course_id: courseId,
      section_id: sectionId ?? null,
    } as unknown as ItemCreateRequest);
  }

  addAssessment(
    pathId: string,
    assessmentId: string,
    sectionId?: string,
  ): Observable<{ item_id: string }> {
    return this.createItem({
      path_id: pathId,
      item_type: 'assessment',
      assessment_id: assessmentId,
      section_id: sectionId ?? null,
    } as unknown as ItemCreateRequest);
  }

  addExternalLink(
    pathId: string,
    url: string,
    title: string,
    description?: string,
    sectionId?: string,
  ): Observable<{ item_id: string }> {
    return this.createItem({
      path_id: pathId,
      item_type: 'external_link',
      external_url: url,
      external_title: title,
      external_description: description ?? '',
      section_id: sectionId ?? null,
    } as unknown as ItemCreateRequest);
  }

  updateItem(
    itemId: string,
    data: ItemUpdateRequest & { path_id?: string },
  ): Observable<void> {
    return this.api
      .put(Endpoints.paths.updateItem(data.path_id ?? '', itemId), {
        title: data.external_title,
        description: data.external_description,
        externalUrl: data.external_url,
        sectionId: data.section_id,
        sortOrder: data.sort_order,
        isRequired: data.is_required,
        pointsValue: data.points_value,
      })
      .pipe(map(() => undefined));
  }

  deleteItem(pathId: string, itemId: string): Observable<void> {
    return this.api
      .delete(Endpoints.paths.deleteItem(pathId, itemId))
      .pipe(map(() => undefined));
  }

  reorderItems(
    pathId: string,
    items: { item_id: string; section_id?: string | null; sort_order: number }[],
  ): Observable<void> {
    return this.api
      .put(Endpoints.paths.reorderItems(pathId), {
        items: items.map((item) => ({
          id: item.item_id,
          sectionId: item.section_id ?? null,
          sortOrder: item.sort_order,
        })),
      })
      .pipe(map(() => undefined));
  }

  // ==========================================
  // ENROLMENT
  // ==========================================

  enroll(pathId: string): Observable<{ enrollment_id: string }> {
    return this.api
      .post<PathEnrolmentPayload>(Endpoints.paths.selfEnrol(pathId), {})
      .pipe(map((enrolment) => ({ enrollment_id: enrolment.id })));
  }

  enrollUser(
    pathId: string,
    userId: string,
    dueDate?: string,
  ): Observable<{ enrollment_id: string }> {
    return this.api
      .post<{ enrolled: number }>(Endpoints.paths.enrol(pathId), {
        userIds: [userId],
        dueDate: dueDate ?? null,
      })
      .pipe(map(() => ({ enrollment_id: '' })));
  }

  bulkEnroll(
    pathId: string,
    userIds: string[],
    dueDate?: string,
  ): Observable<{ enrolled: number; skipped: number }> {
    return this.api
      .post<{ enrolled: number; alreadyEnrolled: number }>(Endpoints.paths.enrol(pathId), {
        userIds,
        dueDate: dueDate ?? null,
      })
      .pipe(map((result) => ({
        enrolled: result.enrolled,
        skipped: result.alreadyEnrolled ?? 0,
      })));
  }

  getEnrollments(
    pathId: string,
    params?: EnrollmentListParams,
  ): Observable<PaginatedResponse<LearningPathEnrollment>> {
    return this.api
      .getPaged<PathEnrolmentPayload>(Endpoints.paths.enrollments(pathId), { ...params })
      .pipe(map((page) => ({
        data: page.items.map(toEnrolment),
        pagination: {
          page: page.meta.page,
          per_page: page.meta.perPage,
          total: page.meta.total,
          total_pages: page.meta.totalPages,
        },
      })));
  }

  getMyEnrollment(pathId: string): Observable<LearningPathEnrollment> {
    return this.api
      .get<MyPathPayload>(Endpoints.paths.mineShow(pathId))
      .pipe(map((mine) => toEnrolment(mine.enrollment)));
  }

  getMyEnrollments(): Observable<LearningPathEnrollment[]> {
    return this.api
      .getPaged<PathEnrolmentPayload>(Endpoints.paths.mine, { perPage: 100 })
      .pipe(map((page) => page.items.map(toEnrolment)));
  }

  /**
   * Starting a path.
   *
   * There is nothing to announce: an enrolment moves to "in progress" when
   * the first item is completed, in the transaction that records it. A
   * separate call saying so could only disagree.
   */
  startPath(_pathId: string): Observable<void> {
    return of(undefined);
  }

  withdraw(pathId: string, userId = ''): Observable<void> {
    return this.api
      .delete(Endpoints.paths.withdraw(pathId, userId))
      .pipe(map(() => undefined));
  }

  getEnrollmentStatistics(pathId: string): Observable<EnrollmentStatistics> {
    return this.api
      .getPaged<PathEnrolmentPayload>(Endpoints.paths.enrollments(pathId), { perPage: 200 })
      .pipe(map((page) => toEnrolmentStatistics(page.items)));
  }

  // ==========================================
  // PROGRESS
  // ==========================================

  getMyProgress(pathId: string): Observable<ProgressBySection> {
    return forkJoin({
      mine: this.api.get<MyPathPayload>(Endpoints.paths.mineShow(pathId)),
      sections: this.getSections(pathId),
      items: this.getItems(pathId),
    }).pipe(map(({ mine, sections, items }) => toProgressBySection(mine, sections, items)));
  }

  /** Someone else's progress. Not readable: `/me/paths` is the only view. */
  getUserProgress(_pathId: string, _targetUserId: string): Observable<ProgressBySection> {
    return notAvailable('Another learner’s path progress');
  }

  /** Opening an item is not recorded separately; completing it is. */
  startItem(_pathId: string, _itemId: string): Observable<LearningPathItemProgress> {
    return notAvailable('Marking a path item as started');
  }

  completeItem(pathId: string, itemId: string, score?: number): Observable<{
    success: boolean;
    path_completed?: boolean;
    points_earned?: number;
  }> {
    return this.api
      .post<CompletionPayload>(Endpoints.paths.completeItem(pathId, itemId), {
        score: score ?? null,
      })
      .pipe(map((result) => ({
        success: true,
        path_completed: result.enrollment?.['status'] === 'completed',
        points_earned: Number(result['pointsEarned'] ?? 0),
      })));
  }

  skipItem(_pathId: string, _itemId: string): Observable<void> {
    return notAvailable('Skipping a path item');
  }

  updateTimeSpent(_pathId: string, _itemId: string, _minutes: number): Observable<void> {
    // Time on a path item is the time on the course or assessment behind it,
    // which those record themselves. A second figure kept here would be a
    // second answer to the same question.
    return of(undefined);
  }

  resetItemProgress(_enrollmentId: string, _itemId: string): Observable<void> {
    return notAvailable('Resetting a learner’s progress on an item');
  }

  unlockItem(_enrollmentId: string, _itemId: string): Observable<void> {
    return notAvailable('Unlocking an item by hand');
  }

  // ==========================================
  // WHAT MAY GO IN A PATH
  // ==========================================

  getAvailableCourses(search?: string): Observable<PickableCourse[]> {
    return this.api
      .getPaged<Record<string, any>>(Endpoints.courses.list, { search, perPage: 100 })
      .pipe(map((page) => page.items.map((course) => ({
        course_id: String(course['id']),
        title: String(course['title'] ?? ''),
        short_description: String(course['summary'] ?? ''),
        thumbnail_url: course['thumbnailUrl'] ?? undefined,
        estimated_duration_minutes: Number(course['durationMinutes'] ?? 0),
        category_name: course['categoryName'] ?? undefined,
        skill_level: course['level'] ?? undefined,
        status: course['status'] ?? undefined,
      }))));
  }

  getAvailableAssessments(search?: string): Observable<PickableAssessment[]> {
    return this.api
      .getPaged<Record<string, any>>(Endpoints.assessments.list, { search, perPage: 100 })
      .pipe(map((page) => page.items.map((assessment) => ({
        assessment_id: String(assessment['id']),
        title: String(assessment['title'] ?? ''),
        description: String(assessment['description'] ?? ''),
        time_limit_minutes: Number(assessment['timeLimitMinutes'] ?? 0),
        passing_score: Number(assessment['passingScore'] ?? 0),
        questions_count: Number(assessment['questionCount'] ?? 0),
        category_name: assessment['categoryName'] ?? undefined,
        assessment_type: assessment['kind'] ?? undefined,
        status: assessment['status'] ?? undefined,
      }))));
  }

  getUsers(search?: string): Observable<Array<Record<string, any>>> {
    return this.api
      .getPaged<Record<string, any>>(Endpoints.users.list, { search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  searchUsersForEnrollment(
    _pathId: string,
    filters: Record<string, unknown>,
  ): Observable<Array<Record<string, any>>> {
    return this.getUsers(filters['search'] as string | undefined);
  }

  checkPrerequisites(_pathId: string): Observable<{ eligible: boolean; missing: unknown[] }> {
    // Prerequisites between paths are not modelled. Saying "yes" is the
    // honest answer to "is anything blocking this", because nothing is.
    return of({ eligible: true, missing: [] });
  }

  // ==========================================
  // REVIEWS
  // ==========================================
  //
  // A review names the enrolment it came from, and one enrolment leaves one
  // review — which is why `createReview` sends no user or enrolment id: the
  // API works out both from the caller and refuses if they are not on the
  // path. A rating anybody could post as often as they liked would make the
  // average beside a path meaningless.

  /**
   * The reviews on one path.
   *
   * `status` is honoured for moderators only, and an empty one means every
   * state. A learner asking gets what has been approved and published,
   * whatever they send.
   */
  getReviews(
    pathId: string,
    page = 1,
    status = '',
  ): Observable<PaginatedResponse<LearningPathReview>> {
    return this.api
      .getPaged<ReviewPayload>(Endpoints.paths.reviews(pathId), { page, status })
      .pipe(map((result) => ({
        data: result.items.map(toReview),
        pagination: {
          page: result.meta.page,
          per_page: result.meta.perPage,
          total: result.meta.total,
          total_pages: result.meta.totalPages,
        },
      })));
  }

  /**
   * The caller's own review of a path, whatever state it is in.
   *
   * Null when they have not written one, or are not on the path. It is not in
   * {@link getReviews} until a moderator approves it, and its author is the
   * one person entitled to see it before then.
   */
  getMyReview(pathId: string): Observable<LearningPathReview | null> {
    return this.api
      .get<ReviewPayload | null>(Endpoints.paths.myReview(pathId))
      .pipe(map((review) => (review ? toReview(review) : null)));
  }

  getRatingSummary(pathId: string): Observable<RatingSummary> {
    return this.api
      .get<RatingSummaryPayload>(Endpoints.paths.reviewSummary(pathId))
      .pipe(map(toRatingSummary));
  }

  createReview(data: ReviewCreateRequest): Observable<{ review_id: string }> {
    return this.api
      .post<ReviewPayload>(Endpoints.paths.createReview(data.path_id), fromReviewRequest(data))
      .pipe(map((review) => ({ review_id: review.id })));
  }

  /**
   * Edits a review.
   *
   * The same endpoint as writing one: the API replaces whatever this
   * enrolment left, and sends it back to be checked, because the words a
   * moderator approved are not the words that would be shown afterwards.
   *
   * The review id is not needed — one enrolment has one review, and the API
   * finds it from the caller — but the parameter stays, because callers name
   * the review they are editing.
   */
  updateReview(_reviewId: string, data: Partial<ReviewCreateRequest>): Observable<void> {
    if (!data.path_id) {
      return throwError(() => new Error('A review is edited against the path it is on.'));
    }

    return this.api
      .post(Endpoints.paths.createReview(data.path_id), fromReviewRequest(data))
      .pipe(map(() => undefined));
  }

  deleteReview(reviewId: string): Observable<void> {
    return this.api.delete(Endpoints.pathReviews.delete(reviewId)).pipe(map(() => undefined));
  }

  approveReview(reviewId: string): Observable<void> {
    return this.api.post(Endpoints.pathReviews.approve(reviewId), {}).pipe(map(() => undefined));
  }

  rejectReview(reviewId: string, reason?: string): Observable<void> {
    return this.api
      .post(Endpoints.pathReviews.reject(reviewId), { reason: reason ?? '' })
      .pipe(map(() => undefined));
  }

  respondToReview(reviewId: string, response: string): Observable<void> {
    return this.api
      .post(Endpoints.pathReviews.respond(reviewId), { response })
      .pipe(map(() => undefined));
  }

  /** A toggle — the same gesture again takes the mark back. */
  markReviewHelpful(reviewId: string): Observable<void> {
    return this.api.post(Endpoints.pathReviews.helpful(reviewId), {}).pipe(map(() => undefined));
  }

  reportReview(reviewId: string, reason: string): Observable<void> {
    return this.api
      .post(Endpoints.pathReviews.report(reviewId), { reason })
      .pipe(map(() => undefined));
  }

  /** The moderation queue, across every path, oldest first. */
  getPendingReviews(page = 1): Observable<PaginatedResponse<LearningPathReview>> {
    return this.api
      .getPaged<ReviewPayload>(Endpoints.pathReviews.pending, { page })
      .pipe(map((result) => ({
        data: result.items.map(toReview),
        pagination: {
          page: result.meta.page,
          per_page: result.meta.perPage,
          total: result.meta.total,
          total_pages: result.meta.totalPages,
        },
      })));
  }

  // ==========================================
  // NOT BUILT
  // ==========================================
  //
  // Path assignment rules had a legacy action and no table on this side.
  // These fail with a message saying so rather than returning empty — a rule
  // list that renders empty because the feature does not exist is
  // indistinguishable from one with no rules in it.

  createAssignment(
    _data: AssignmentCreateRequest,
  ): Observable<{ assignment_id: string; enrollments_created: number }> {
    return notAvailable('Path assignment rules');
  }

  getAssignments(_pathId: string): Observable<LearningPathAssignment[]> {
    return notAvailable('Path assignment rules');
  }

  cancelAssignment(_assignmentId: string): Observable<void> {
    return notAvailable('Path assignment rules');
  }

  pauseAssignment(_assignmentId: string): Observable<void> {
    return notAvailable('Path assignment rules');
  }

  resumeAssignment(_assignmentId: string): Observable<void> {
    return notAvailable('Path assignment rules');
  }

  getAssignmentStatistics(_assignmentId: string): Observable<unknown> {
    return notAvailable('Path assignment rules');
  }

  sendAssignmentReminders(_assignmentId: string): Observable<{ sent: number }> {
    return notAvailable('Path assignment reminders');
  }
}

/**
 * A course or an assessment as the path builder's picker lists it.
 *
 * Named rather than left as a loose record: the builder holds them in
 * typed arrays, and `Record<string, unknown>` is assignable to nothing.
 */
export interface PickableCourse {
  course_id: string;
  title: string;
  short_description?: string;
  thumbnail_url?: string;
  estimated_duration_minutes?: number;
  lessons_count?: number;
  category_name?: string;
  skill_level?: string;
  status?: string;
}

export interface PickableAssessment {
  assessment_id: string;
  title: string;
  description?: string;
  time_limit_minutes?: number;
  passing_score?: number;
  questions_count?: number;
  category_name?: string;
  assessment_type?: string;
  status?: string;
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface PathPayload extends Record<string, any> {
  id: string;
  title: string;
}

interface SectionPayload extends Record<string, any> {
  id: string;
}

interface ItemPayload extends Record<string, any> {
  id: string;
  kind: string;
}

interface PathEnrolmentPayload extends Record<string, any> {
  id: string;
}

interface MyPathPayload {
  enrollment: PathEnrolmentPayload;
  items: Record<string, Record<string, any>>;
}

interface CompletionPayload extends Record<string, any> {
  enrollment?: Record<string, any>;
}

interface ReviewPayload extends Record<string, any> {
  id: string;
  rating: number;
}

interface RatingSummaryPayload {
  total: number;
  average: number | null;
  averageContent: number | null;
  averageDifficulty: number | null;
  averagePractical: number | null;
  distribution: Record<string, number>;
}

/**
 * A review as the screens have always read one.
 *
 * `status` and `moderation_status` are the same thing twice: the screens ask
 * for both and one of them is what each template happens to use. They are
 * filled from the single field the API sends rather than allowed to drift.
 */
function toReview(review: ReviewPayload): LearningPathReview {
  const [firstName, ...rest] = String(review['authorName'] ?? '').trim().split(' ');
  const status = (review['moderationStatus'] ?? 'pending') as ReviewStatus;

  return {
    review_id: review.id,
    path_id: review['pathId'] ?? '',
    user_id: review['userId'] ?? '',
    enrollment_id: review['enrollmentId'] ?? '',
    tenant_id: '',
    rating: review.rating,
    title: review['title'] ?? '',
    review_text: review['reviewText'] ?? '',
    content_rating: review['contentRating'] ?? null,
    difficulty_rating: review['difficultyRating'] ?? null,
    practical_value_rating: review['practicalValueRating'] ?? null,
    status,
    moderation_status: status,
    moderated_by: null,
    moderated_at: review['moderatedAt'] ?? null,
    rejection_reason: review['rejectionReason'] ?? '',
    helpful_count: review['helpfulCount'] ?? 0,
    reported_count: review['reportedCount'] ?? 0,
    instructor_response: review['responseText'] ?? null,
    response_text: review['responseText'] ?? '',
    response_by: null,
    response_at: review['responseAt'] ?? null,
    created_at: review['createdAt'] ?? '',
    updated_at: review['updatedAt'] ?? null,
    // Published, not merely undeleted: a review waiting to be checked is on
    // no page, and a screen that reads this flag is asking about that.
    is_active: review['isPublished'] === true,
    first_name: firstName || undefined,
    last_name: rest.join(' ') || undefined,
    profile_image_url: review['authorAvatarUrl'] ?? undefined,
    path_title: review['pathTitle'] ?? undefined,
  };
}

function toRatingSummary(summary: RatingSummaryPayload): RatingSummary {
  const counts = summary.distribution ?? {};
  const at = (star: number): number => Number(counts[String(star)] ?? 0);
  const total = summary.total ?? 0;
  const pct = (star: number): number => (total === 0 ? 0 : Math.round((at(star) / total) * 100));

  return {
    total_reviews: total,
    // Null rather than zero when nobody has reviewed it: a zero would draw
    // five empty stars beside a path nobody has rated yet.
    avg_rating: summary.average,
    avg_content_rating: summary.averageContent,
    avg_difficulty_rating: summary.averageDifficulty,
    avg_practical_value_rating: summary.averagePractical,
    five_star: at(5),
    four_star: at(4),
    three_star: at(3),
    two_star: at(2),
    one_star: at(1),
    five_star_pct: pct(5),
    four_star_pct: pct(4),
    three_star_pct: pct(3),
    two_star_pct: pct(2),
    one_star_pct: pct(1),
    distribution: { 5: at(5), 4: at(4), 3: at(3), 2: at(2), 1: at(1) },
  };
}

function fromReviewRequest(data: Partial<ReviewCreateRequest>): Record<string, unknown> {
  return {
    rating: data.rating,
    title: data.title,
    reviewText: data.review_text,
    contentRating: data.content_rating,
    difficultyRating: data.difficulty_rating,
    practicalValueRating: data.practical_value_rating,
  };
}

/**
 * The list filters, in the API's spelling.
 *
 * The screens ask in snake_case because that is what they have always
 * bound to. Spreading those keys onto the query string silently loses
 * every one of them: the API reads `perPage`, not `per_page`, so a page
 * size and a sort order were being asked for and never applied.
 */
function toQuery(
  params?: LearningPathListParams,
): Record<string, string | number | boolean | null | undefined> {
  return {
    page: params?.page,
    perPage: params?.per_page,
    sort: params?.sort_by,
    direction: params?.sort_dir,
    search: params?.search,
    status: params?.status,
    level: params?.skill_level,
    categoryId: params?.category_id,
    visibility: params?.visibility,
    isMandatory: params?.is_mandatory,
  };
}

function toPath(path: PathPayload): LearningPath {
  return {
    path_id: path.id,
    tenant_id: '',
    title: path.title ?? '',
    slug: String(path['slug'] ?? ''),
    short_description: String(path['summary'] ?? ''),
    description: String(path['description'] ?? ''),
    thumbnail_url: path['thumbnailUrl'] ?? null,
    banner_url: null,
    category_id: path['categoryId'] ?? null,
    category_name: path['categoryName'] ?? undefined,
    skill_level: (path['level'] ?? 'beginner') as LearningPath['skill_level'],
    path_type: (path['pacing'] ?? 'self_paced') as LearningPath['path_type'],
    is_mandatory: Boolean(path['isMandatory']),
    enforce_prerequisites: false,
    allow_skip: false,
    estimated_duration_hours: path['estimatedHours'] ?? null,
    estimated_weeks: null,
    weekly_commitment_hours: null,
    completion_type: (path['completionRule'] ?? 'all_items') as LearningPath['completion_type'],
    completion_threshold: Number(path['completionThreshold'] ?? 100),
    passing_score: null,
    certificate_enabled: path['certificateTemplateId'] !== null,
    certificate_template_id: path['certificateTemplateId'] ?? null,
    certificate_validity_months: path['certificateValidityMonths'] ?? null,
    total_points: 0,
    badge_id: null,
    status: (path['status'] ?? 'draft') as LearningPath['status'],
    visibility: (path['visibility'] ?? 'public') as LearningPath['visibility'],
    publish_date: path['publishedAt'] ?? null,
    unpublish_date: null,
    enrollment_type: (path['enrolmentType'] ?? 'open') as LearningPath['enrollment_type'],
    max_enrollments: path['maxEnrolments'] ?? null,
    enrollment_start_date: null,
    enrollment_end_date: null,
    is_free: true,
    price: 0,
    currency: 'NGN',
    tags: (path['tags'] ?? []) as string[],
    target_audience: null,
    learning_objectives: (path['objectives'] ?? []) as string[],
    // Counted at read time on the server. The legacy stored all three and
    // every one of them drifted.
    total_items: Number(path['itemCount'] ?? 0),
    total_enrollments: Number(path['enrolmentCount'] ?? 0),
    total_completions: Number(path['completionCount'] ?? 0),
    avg_rating: null,
    total_reviews: 0,
    created_by: path['createdBy'] ?? null,
    created_at: String(path['createdAt'] ?? ''),
    updated_at: path['updatedAt'] ?? null,
    is_active: path['status'] !== 'archived',
  } as unknown as LearningPath;
}

function fromPathRequest(
  data: LearningPathCreateRequest | LearningPathUpdateRequest,
): Record<string, unknown> {
  const d = data as Record<string, any>;

  return {
    title: d['title'],
    summary: d['short_description'],
    description: d['description'],
    thumbnailUrl: d['thumbnail_url'],
    level: d['skill_level'],
    pacing: d['path_type'],
    categoryId: d['category_id'],
    isMandatory: d['is_mandatory'],
    visibility: d['visibility'],
    enrolmentType: d['enrollment_type'],
    completionRule: d['completion_type'],
    completionThreshold: d['completion_threshold'],
    certificateTemplateId: d['certificate_template_id'],
    certificateValidityMonths: d['certificate_validity_months'],
    estimatedHours: d['estimated_duration_hours'],
    maxEnrolments: d['max_enrollments'],
    tags: d['tags'],
    objectives: d['learning_objectives'],
  };
}

function toSection(section: SectionPayload, pathId: string): LearningPathSection {
  return {
    section_id: section.id,
    path_id: String(section['pathId'] ?? pathId),
    tenant_id: '',
    title: String(section['title'] ?? ''),
    description: String(section['description'] ?? ''),
    sort_order: Number(section['sortOrder'] ?? 0),
    is_milestone: Boolean(section['isMilestone']),
    unlock_type: 'immediate',
    unlock_after_section_id: null,
    unlock_date: null,
    completion_required: true,
    min_items_to_complete: section['minItemsToComplete'] ?? null,
    total_items: Number(section['itemCount'] ?? 0),
    estimated_duration_hours: null,
    created_at: String(section['createdAt'] ?? ''),
    updated_at: null,
    is_active: true,
  } as unknown as LearningPathSection;
}

function toItem(item: ItemPayload, pathId: string): LearningPathItem {
  const kind = String(item.kind ?? 'course');
  const subject = item['subjectId'] ?? null;

  return {
    item_id: item.id,
    path_id: pathId,
    section_id: item['sectionId'] ?? null,
    tenant_id: '',
    item_type: kind as LearningPathItem['item_type'],
    // One `subjectId` on the wire, because an item points at exactly one
    // thing. Which column it lands in here is decided by the kind.
    course_id: kind === 'course' ? subject : null,
    assessment_id: kind === 'assessment' ? subject : null,
    live_session_id: kind === 'training' ? subject : null,
    media_id: kind === 'media' ? subject : null,
    external_url: String(item['externalUrl'] ?? ''),
    external_title: String(item['title'] ?? ''),
    external_description: String(item['description'] ?? ''),
    sort_order: Number(item['sortOrder'] ?? 0),
    is_required: item['isRequired'] !== false,
    is_locked: false,
    unlock_type: 'immediate',
    unlock_after_item_ids: null,
    unlock_date: null,
    unlock_min_score: null,
    completion_type: 'view',
    min_time_spent_minutes: item['estimatedMinutes'] ?? null,
    passing_score: null,
    max_attempts: null,
    points_value: Number(item['pointsValue'] ?? 0),
    bonus_points: 0,
    bonus_deadline: null,
    due_date: item['dueDate'] ?? null,
    due_days_from_enrollment: item['dueDaysFromEnrolment'] ?? null,
    title: String(item['title'] ?? ''),
    is_active: true,
  } as unknown as LearningPathItem;
}

function toEnrolment(enrolment: PathEnrolmentPayload): LearningPathEnrollment {
  return {
    enrollment_id: enrolment.id,
    path_id: String(enrolment['pathId'] ?? ''),
    user_id: String(enrolment['userId'] ?? ''),
    tenant_id: '',
    status: (enrolment['status'] ?? 'enrolled') as LearningPathEnrollment['status'],
    enrollment_type: (enrolment['source'] ?? 'self') as LearningPathEnrollment['enrollment_type'],
    enrolled_by: enrolment['assignedBy'] ?? null,
    enrolled_at: String(enrolment['enrolledAt'] ?? ''),
    started_at: enrolment['startedAt'] ?? null,
    completed_at: enrolment['completedAt'] ?? null,
    due_date: enrolment['dueDate'] ?? null,
    expiry_date: null,
    withdrawn_at: null,
    progress_percentage: Number(enrolment['progressPercentage'] ?? 0),
    items_completed: Number(enrolment['itemsCompleted'] ?? 0),
    items_total: Number(enrolment['itemsTotal'] ?? 0),
    current_item_id: null,
    current_section_id: null,
    total_time_spent_minutes: 0,
    last_accessed_at: enrolment['lastActivityAt'] ?? null,
    total_points_earned: Number(enrolment['pointsEarned'] ?? 0),
    avg_assessment_score: null,
    completion_type: null,
    completed_by: null,
    certificate_id: enrolment['certificateId'] ?? null,
    certificate_issued_at: null,
    certificate_expiry_date: null,
    admin_notes: '',
    learner_notes: '',
    created_at: String(enrolment['enrolledAt'] ?? ''),
    user_name: enrolment['userName'] ?? null,
    user_email: enrolment['userEmail'] ?? null,
    path_title: enrolment['pathTitle'] ?? null,
  } as unknown as LearningPathEnrollment;
}

/**
 * One learner's way through a path, section by section.
 *
 * Assembled here from the three things the API returns separately: the
 * enrolment with its per-item completions, the sections, and the items. Items
 * belonging to no section come back in `unsectioned_items`, which is why the
 * API does not nest them in the first place.
 */
function toProgressBySection(
  mine: MyPathPayload,
  sections: LearningPathSection[],
  items: LearningPathItem[],
): ProgressBySection {
  const completions = new Map<string, Record<string, any>>(Object.entries(mine.items ?? {}));

  const progressFor = (item: LearningPathItem): LearningPathItemProgress => {
    const done = completions.get(item.item_id);

    return {
      progress_id: '',
      enrollment_id: mine.enrollment?.id ?? '',
      item_id: item.item_id,
      user_id: String(mine.enrollment?.['userId'] ?? ''),
      tenant_id: '',
      status: done === undefined ? 'not_started' : 'completed',
      progress_percentage: done === undefined ? 0 : 100,
      unlocked_at: null,
      started_at: done?.['startedAt'] ?? null,
      completed_at: done?.['completedAt'] ?? null,
      due_date: item.due_date,
      time_spent_minutes: 0,
      last_accessed_at: done?.['completedAt'] ?? null,
      attempts: 0,
      best_score: done?.['score'] ?? null,
      last_score: done?.['score'] ?? null,
      passed: done === undefined ? null : true,
      points_earned: done === undefined ? 0 : item.points_value,
      bonus_earned: 0,
      course_enrollment_id: null,
      assessment_attempt_id: null,
      notes: '',
      feedback: '',
      item,
    } as unknown as LearningPathItemProgress;
  };

  return {
    sections: sections.map((section) => ({
      section: {
        ...section,
        items_completed: items
          .filter((i) => i.section_id === section.section_id)
          .filter((i) => completions.has(i.item_id)).length,
      } as never,
      items: items.filter((i) => i.section_id === section.section_id).map(progressFor),
    })),
    unsectioned_items: items.filter((i) => i.section_id === null).map(progressFor),
  };
}

function toStatistics(path: LearningPath, enrolments: PathEnrolmentPayload[]): LearningPathStatistics {
  const completed = enrolments.filter((e) => e['status'] === 'completed').length;

  return {
    total_enrollments: enrolments.length,
    active_enrollments: enrolments.filter((e) => e['status'] === 'in_progress').length,
    completed_enrollments: completed,
    completion_rate: enrolments.length === 0
      ? 0
      : Math.round((completed / enrolments.length) * 100),
    avg_progress: enrolments.length === 0
      ? 0
      : Math.round(
        enrolments.reduce((total, e) => total + Number(e['progressPercentage'] ?? 0), 0)
        / enrolments.length,
      ),
    total_items: path.total_items,
    avg_rating: null,
    total_reviews: 0,
  } as unknown as LearningPathStatistics;
}

function toEnrolmentStatistics(enrolments: PathEnrolmentPayload[]): EnrollmentStatistics {
  const by = (status: string): number =>
    enrolments.filter((e) => e['status'] === status).length;

  return {
    total: enrolments.length,
    enrolled: by('enrolled'),
    in_progress: by('in_progress'),
    completed: by('completed'),
    overdue: enrolments.filter((e) => e['isOverdue'] === true).length,
    avg_progress: enrolments.length === 0
      ? 0
      : Math.round(
        enrolments.reduce((total, e) => total + Number(e['progressPercentage'] ?? 0), 0)
        / enrolments.length,
      ),
  } as unknown as EnrollmentStatistics;
}

/**
 * A feature that has no server behind it.
 *
 * Loud rather than empty: a screen that renders nothing because the thing was
 * never built looks exactly like one with nothing to show, and the difference
 * matters to whoever is waiting for a review to moderate.
 */
function notAvailable(what: string): Observable<never> {
  return throwError(() => new Error(`${what} are not available yet.`));
}
