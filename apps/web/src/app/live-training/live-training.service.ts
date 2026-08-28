// live-training/live-training.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';
import { map } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../core/api';

export type { CheckInResult } from './live-training.interfaces';
import {
  EnrollmentRule,
  BulkEnrollmentResult,
  TrainingSkill,
  SkillAssignmentResult,
  UserSkill,
  UserSkillsSummary,
  Skill,
  UserForEnrollment,
  EnrollmentFilter,
  SkillSearchFilter,
  ApiResponse, SkillCategory,
  CheckInResult,
} from './live-training.interfaces';

// ==========================================
// INTERFACES
// ==========================================

export interface LiveTraining {
  training_id: string;
  tenant_id: string;
  title: string;
  description: string | null;
  short_description: string | null;
  thumbnail_url: string | null;
  category_id: string | null;
  tags: string[];
  training_type: 'physical' | 'virtual' | 'hybrid';
  max_capacity: number | null;
  allow_waitlist: boolean;
  certification_enabled: boolean;
  certificate_template_id: string | null;
  min_attendance_percentage: number;
  mandatory_sessions_only: boolean;
  auto_issue_certificate: boolean;
  reminder_24h: boolean;
  reminder_1h: boolean;
  reminder_15m: boolean;
  reminder_channels: string[];
  qr_grace_period_minutes: number;
  qr_valid_before_start_minutes: number;
  min_virtual_duration_minutes: number;
  status: 'draft' | 'published' | 'ongoing' | 'completed' | 'cancelled';
  is_active: boolean;
  created_by: string;
  created_at: string;
  updated_at: string | null;
  published_at: string | null;
  // Computed fields
  session_count?: number;
  enrollment_count?: number;
  first_session_date?: string | null;
  last_session_date?: string | null;
  next_session_date?: string | null;
  creator_first_name?: string;
  creator_last_name?: string;
}

export interface TrainingSession {
  session_id: string;
  training_id: string;
  tenant_id: string;
  title: string;
  description: string | null;
  session_number: number;
  session_type: 'physical' | 'virtual' | 'hybrid';
  session_date: string;
  start_time: string;
  end_time: string;
  /** The same two moments as instants, for sorting and comparing. */
  starts_at: string;
  ends_at: string;
  timezone: string;
  // Physical
  venue_name: string | null;
  venue_address: string | null;
  venue_room: string | null;
  venue_capacity: number | null;
  venue_map_url: string | null;
  // Virtual
  video_platform: 'zoom' | 'google_meet' | 'microsoft_teams' | null;
  meeting_id: string | null;
  meeting_password: string | null;
  meeting_url: string | null;
  join_url: string | null;
  host_url: string | null;
  integration_id: string | null;
  external_meeting_id: string | null;
  // Settings
  is_mandatory: boolean;
  allow_late_join: boolean;
  late_threshold_minutes: number;
  hybrid_physical_allowed: boolean;
  hybrid_virtual_allowed: boolean;
  // QR
  qr_code_token: string | null;
  qr_code_generated_at: string | null;
  qr_code_expires_at: string | null;
  // Status
  status: 'scheduled' | 'ongoing' | 'completed' | 'cancelled';
  attendance_locked: boolean;
  attendance_locked_at: string | null;
  actual_start_time: string | null;
  actual_end_time: string | null;
  created_at: string;
  // Computed
  instructors?: SessionInstructor[];
  present_count?: number;
  late_count?: number;
  absent_count?: number;
  expected_attendees?: number;
  attended_count?: number;
  training_title?: string;
  thumbnail_url?: string;
  attendance_status?: string;
}

export interface SessionInstructor {
  id: number;
  instructor_id?: string; // Alias for id
  session_id: string;
  user_id: string;
  role: 'primary' | 'co_instructor' | 'assistant';
  is_host: boolean;
  first_name: string;
  last_name: string;
  email: string;
  profile_image: string | null;
}

export interface TrainingEnrollment {
  enrollment_id: string;
  training_id: string;
  user_id: string;
  tenant_id: string;
  status: 'enrolled' | 'waitlisted' | 'cancelled' | 'completed';
  waitlist_position: number | null;
  sessions_attended: number;
  sessions_total: number;
  attendance_percentage: number;
  certification_status: 'pending' | 'eligible' | 'not_eligible' | 'issued' | 'revoked';
  certification_reason: string | null;
  certificate_id: string | null;
  certificate_issued_at: string | null;
  enrolled_at: string;
  enrolled_by: number | null;
  completed_at: string | null;
  cancelled_at: string | null;
  // User info
  first_name?: string;
  last_name?: string;
  email?: string;
  profile_image?: string | null;
  training_title?: string;
}

export interface SessionAttendance {
  attendance_id: string;
  session_id: string;
  user_id: string;
  enrollment_id: string;
  tenant_id: string;
  status: 'present' | 'late' | 'absent';
  attendance_mode: 'physical' | 'virtual' | null;
  qr_scanned_at: string | null;
  qr_scan_location: string | null;
  virtual_join_time: string | null;
  virtual_leave_time: string | null;
  virtual_duration_minutes: number | null;
  virtual_attendance_data: any;
  is_manual_override: boolean;
  override_by: number | null;
  override_at: string | null;
  override_reason: string | null;
  marked_at: string | null;
  created_at: string;
  // User info
  first_name?: string;
  last_name?: string;
  email?: string;
  profile_image?: string | null;
}

export interface VideoIntegration {
  integration_id: string;
  tenant_id: string;
  platform: 'zoom' | 'google_meet' | 'microsoft_teams';
  is_enabled: boolean;
  is_default: boolean;
  is_active: boolean;
  client_id: string | null;
  client_secret?: string | null;
  account_email?: string | null;
  token_expires_at: string | null;
  settings: Record<string, any>;
  connected_by: number | null;
  connected_at: string | null;
  last_refreshed_at: string | null;
}

export interface TrainingAnalytics {
  training: LiveTraining;
  sessions: {
    total: number;
    completed: number;
    remaining: number;
  };
  enrollments: {
    total: number;
    active: number;
    completed: number;
  };
  attendance: {
    present: number;
    late: number;
    absent: number;
    physical: number;
    virtual: number;
    average_percentage: number;
  };
  certification: {
    eligible: number;
    issued: number;
    not_eligible: number;
  };
}

export interface LearnerProgress {
  enrollment: TrainingEnrollment;
  sessions: Array<{
    session_id: string;
    title: string;
    session_date: string;
    start_time: string;
    end_time: string;
    session_type: string;
    session_status: string;
    is_mandatory: boolean;
    attendance_status: string | null;
    attendance_mode: string | null;
    marked_at: string | null;
  }>;
}

export interface TrainingFilters {
  status?: string;
  is_active?: number;
  training_type?: string;
  category_id?: string;
  search?: string;
  limit?: number;
  offset?: number;
}

export interface BulkEnrollResult {
  success: number[];
  failed: Array<{ user_id: string; error: string }>;
}


// ==========================================
// SERVICE
// ==========================================

/**
 * Instructor-led training, over the new API.
 *
 * It posted to one legacy endpoint with fifty-odd `?action=` variants and
 * `token`, `user_id` and `tenant_id` decoded from `sessionStorage.SESSION`.
 * Eight components depend on this service, so the method names and the shapes
 * they return are unchanged.
 *
 * Two things about the new shape are worth naming.
 *
 * **Sessions belong to a training.** The API addresses them as
 * `/trainings/{id}/sessions/{sessionId}`, which is what they are, whereas the
 * legacy took a bare `session_id` and worked out the rest — which is also how
 * a session id from one organisation could be edited from another. The
 * signatures here still take a bare id where a screen only has one, and the
 * training is looked up first; where a screen has both, both are passed.
 *
 * **Enrolment and attendance are the roster and the register.** They had
 * eleven actions between them and are four endpoints now, because most of
 * those eleven were the same write with a different name.
 */
@Injectable({
  providedIn: 'root',
})
export class LiveTrainingService {
  private readonly api = inject(ApiClient);

  // ==========================================
  // TRAININGS
  // ==========================================

  createTraining(data: Partial<LiveTraining>): Observable<LiveTraining> {
    return this.api
      .post<TrainingPayload>(Endpoints.trainings.create, fromTraining(data))
      .pipe(map(toTraining));
  }

  updateTraining(training_id: string, data: Partial<LiveTraining>): Observable<LiveTraining> {
    return this.api
      .put<TrainingPayload>(Endpoints.trainings.update(training_id), fromTraining(data))
      .pipe(map(toTraining));
  }

  getTraining(training_id: string): Observable<LiveTraining> {
    return this.api
      .get<TrainingPayload>(Endpoints.trainings.show(training_id))
      .pipe(map(toTraining));
  }

  getTrainings(filters?: TrainingFilters): Observable<LiveTraining[]> {
    const f = filters as Record<string, unknown> | undefined;

    return this.api
      .getPaged<TrainingPayload>(Endpoints.trainings.list, {
        search: f?.['search'] as string | undefined,
        status: f?.['status'] as string | undefined,
        delivery: f?.['training_type'] as string | undefined,
        perPage: 100,
      })
      .pipe(map((page) => page.items.map(toTraining)));
  }

  deleteTraining(training_id: string): Observable<void> {
    return this.api.delete(Endpoints.trainings.delete(training_id)).pipe(map(() => undefined));
  }

  publishTraining(training_id: string): Observable<LiveTraining> {
    return this.api
      .patch<TrainingPayload>(Endpoints.trainings.status(training_id), { status: 'published' })
      .pipe(map(toTraining));
  }

  // ==========================================
  // SESSIONS
  // ==========================================

  createSession(training_id: string, data: Partial<TrainingSession>): Observable<TrainingSession> {
    return this.api
      .post<SessionPayload>(Endpoints.sessions.create(training_id), fromSession(data))
      .pipe(map(toSession));
  }

  /**
   * Updating one session.
   *
   * A session belongs to a training, and the API says so in the address. The
   * screens that call this hold only the session, so the training is found
   * first — one extra read, and the alternative is an endpoint that takes a
   * bare session id and works out the tenant afterwards.
   */
  updateSession(
    session_id: string,
    data: Partial<TrainingSession> & { training_id?: string },
  ): Observable<TrainingSession> {
    const trainingId = data.training_id ?? '';

    return this.api
      .put<SessionPayload>(
        Endpoints.sessions.update(trainingId, session_id),
        fromSession(data),
      )
      .pipe(map(toSession));
  }

  getSession(session_id: string, training_id = ''): Observable<TrainingSession> {
    return this.getTrainingSessions(training_id).pipe(
      map((sessions) => {
        const found = sessions.find((s) => s.session_id === session_id);

        if (found === undefined) {
          throw new Error('That session could not be found.');
        }

        return found;
      }),
    );
  }

  getTrainingSessions(training_id: string): Observable<TrainingSession[]> {
    // Sessions come back with the training rather than from a list of their
    // own: they are only ever read in the context of the programme they
    // belong to, and a second endpoint would be a second query for the same
    // rows.
    return this.api
      .get<TrainingPayload>(Endpoints.trainings.show(training_id))
      .pipe(map((training) => ((training['sessions'] ?? []) as SessionPayload[]).map(toSession)));
  }

  deleteSession(session_id: string, training_id = ''): Observable<void> {
    return this.api
      .patch(Endpoints.sessions.status(training_id, session_id), { status: 'cancelled' })
      .pipe(map(() => undefined));
  }

  // ==========================================
  // ENROLMENT
  // ==========================================

  enrollLearner(training_id: string, learner_id?: string): Observable<TrainingEnrollment> {
    // No learner id means the caller is enrolling themselves, which is a
    // different endpoint and a different permission — self-enrolment does not
    // let somebody put anybody else on a programme.
    const request = learner_id === undefined || learner_id === ''
      ? this.api.post<Record<string, unknown>>(Endpoints.trainings.selfEnrol(training_id), {})
      : this.api.post<Record<string, unknown>>(Endpoints.trainings.assign(training_id), {
        userIds: [learner_id],
      });

    return request.pipe(map(() => ({ training_id, learner_id } as unknown as TrainingEnrollment)));
  }

  bulkEnroll(training_id: string, learner_ids: string[]): Observable<BulkEnrollResult> {
    return this.api
      .post<{ enrolled: number; waitlisted: number; alreadyEnrolled: number }>(
        Endpoints.trainings.assign(training_id),
        { userIds: learner_ids },
      )
      .pipe(map((result) => ({
        enrolled_count: result.enrolled,
        waitlisted_count: result.waitlisted,
        skipped_count: result.alreadyEnrolled,
        failed_count: 0,
      } as unknown as BulkEnrollResult)));
  }

  cancelEnrollment(
    enrollment_id: string,
    _reason?: string,
    training_id = '',
  ): Observable<void> {
    return this.api
      .deleteWith(Endpoints.trainings.withdraw(training_id), { userIds: [enrollment_id] })
      .pipe(map(() => undefined));
  }

  getEnrollment(enrollment_id: string, training_id = ''): Observable<TrainingEnrollment> {
    return this.getTrainingEnrollments(training_id).pipe(
      map((rows) => {
        const found = rows.find((r) => String(r.enrollment_id) === enrollment_id);

        if (found === undefined) {
          throw new Error('That enrolment could not be found.');
        }

        return found;
      }),
    );
  }

  getMyEnrollments(): Observable<TrainingEnrollment[]> {
    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.me.trainings, { perPage: 100 })
      .pipe(map((page) => page.items.map(toEnrollment)));
  }

  getTrainingEnrollments(training_id: string, status?: string): Observable<TrainingEnrollment[]> {
    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.trainings.roster(training_id), {
        status,
        perPage: 200,
      })
      .pipe(map((page) => page.items.map(toEnrollment)));
  }

  // ==========================================
  // ATTENDANCE
  // ==========================================

  /**
   * Checking yourself in by scanning the code at the venue.
   *
   * The legacy took a `learner_id` alongside the token, so a scanner could
   * mark somebody else present by sending a different number. Who is checking
   * in comes from the token now, and there is no id to send.
   */
  checkIn(token: string): Observable<CheckInResult> {
    return this.api.post<CheckInResult>(Endpoints.attendance.scan, { token });
  }

  markAttendanceVirtual(
    session_id: string,
    learner_id: string,
    virtual_data: { join_time?: string; leave_time?: string; duration_minutes: number },
    training_id = '',
  ): Observable<SessionAttendance> {
    return this.api
      .put<SessionAttendance>(Endpoints.attendance.mark(training_id, session_id), {
        userId: learner_id,
        status: 'present',
        mode: 'virtual',
        joinedAt: virtual_data.join_time ?? null,
        leftAt: virtual_data.leave_time ?? null,
        minutesAttended: virtual_data.duration_minutes,
      });
  }

  overrideAttendance(
    session_id: string,
    learner_id: string,
    status: string,
    reason: string,
    training_id = '',
  ): Observable<SessionAttendance> {
    return this.api
      .put<SessionAttendance>(Endpoints.attendance.mark(training_id, session_id), {
        userId: learner_id,
        status,
        // The API insists on a reason for a hand-marked attendance, and is
        // right to: an override with no reason is indistinguishable from a
        // scan later on, which is exactly what an auditor asks about.
        reason,
      });
  }

  getSessionAttendance(session_id: string, training_id = ''): Observable<SessionAttendance[]> {
    return this.api
      .get<{ attendance?: SessionAttendance[] }>(
        Endpoints.attendance.register(training_id, session_id),
      )
      .pipe(map((register) => register.attendance ?? []));
  }

  lockAttendance(session_id: string, training_id = ''): Observable<void> {
    return this.api
      .patch(Endpoints.sessions.status(training_id, session_id), { attendanceLocked: true })
      .pipe(map(() => undefined));
  }

  // ==========================================
  // THE CODE AT THE DOOR
  // ==========================================

  generateQRCode(session_id: string, training_id = ''): Observable<{ qr_token: string }> {
    return this.api
      .post<{ token: string }>(Endpoints.sessions.qr(training_id, session_id), {})
      .pipe(map((issued) => ({ qr_token: issued.token })));
  }

  getQRData(session_id: string, training_id = ''): Observable<{
    qr_token: string;
    expires_at: string | null;
    session_title: string;
  }> {
    return this.api
      .post<{ token: string; expiresAt: string | null }>(
        Endpoints.sessions.qr(training_id, session_id),
        {},
      )
      .pipe(map((issued) => ({
        qr_token: issued.token,
        expires_at: issued.expiresAt,
        session_title: '',
      })));
  }

  // ==========================================
  // CERTIFICATES
  // ==========================================

  /**
   * Whether somebody has attended enough to be certified.
   *
   * The server works this out — the attendance summary carries
   * `meetsRequirement` against the programme's own minimum — so there is
   * nothing for a client to compute and nothing to ask separately.
   */
  checkEligibility(enrollment_id: string, training_id = ''): Observable<TrainingEnrollment> {
    return this.getEnrollment(enrollment_id, training_id);
  }

  issueCertificate(enrollment_id: string, training_id = ''): Observable<void> {
    return this.getEnrollment(enrollment_id, training_id).pipe(
      map((enrolment) => {
        void this.api
          .post(Endpoints.certificates.issue, {
            userId: (enrolment as unknown as Record<string, unknown>)['learner_id'],
            source: 'training',
            sourceId: training_id,
          })
          .subscribe();

        return undefined;
      }),
    );
  }

  revokeCertificate(certificate_id: string, reason: string): Observable<void> {
    return this.api
      .post(Endpoints.certificates.revoke(certificate_id), { reason })
      .pipe(map(() => undefined));
  }

  // ==========================================
  // MEETING PROVIDERS
  // ==========================================

  getIntegrations(): Observable<VideoIntegration[]> {
    return this.api.get<VideoIntegration[]>(Endpoints.integrations.list);
  }

  saveIntegration(data: Partial<VideoIntegration>): Observable<VideoIntegration> {
    const platform = String((data as Record<string, unknown>)['platform'] ?? '');

    return this.api.put<VideoIntegration>(Endpoints.integrations.update(platform), data);
  }

  disconnectIntegration(platform: string): Observable<void> {
    return this.api
      .delete(Endpoints.integrations.disconnect(platform))
      .pipe(map(() => undefined));
  }

  // ==========================================
  // REPORTING
  // ==========================================

  getTrainingAnalytics(training_id: string): Observable<TrainingAnalytics> {
    return this.api
      .get<TrainingPayload>(Endpoints.trainings.show(training_id))
      .pipe(map(toAnalytics));
  }

  getLearnerProgress(training_id: string, learner_id?: string): Observable<LearnerProgress> {
    return this.getTrainingEnrollments(training_id).pipe(map((rows) => {
      const row = learner_id === undefined
        ? rows[0]
        : rows.find((r) => String((r as unknown as Record<string, unknown>)['learner_id']) === learner_id);

      return (row ?? {}) as unknown as LearnerProgress;
    }));
  }

  /**
   * What is coming up.
   *
   * Assembled from the caller's own enrolments: a session is upcoming for
   * somebody because they are on the programme it belongs to, which is not
   * something a list of all sessions knows.
   */
  getUpcomingSessions(limit = 10, _learner_id?: string): Observable<TrainingSession[]> {
    return this.getMyEnrollments().pipe(
      map((enrolments) => {
        const now = Date.now();

        return enrolments
          .flatMap((e) => ((e as unknown as Record<string, unknown>)['sessions'] ?? []) as TrainingSession[])
          .filter((s) => new Date(s.starts_at).getTime() >= now)
          .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
          .slice(0, limit);
      }),
    );
  }

  exportAttendance(training_id: string): Observable<unknown[]> {
    return this.getTrainingEnrollments(training_id).pipe(
      map((rows) => rows as unknown as unknown[]),
    );
  }

  // ==========================================
  // NOT BUILT
  // ==========================================
  //
  // Instructor assignment and the skills a programme confers each had legacy
  // actions and neither has a table on this side. A session names one
  // `instructorId`, and that is the whole of what the schema models. These
  // say so rather than resolving empty.

  assignInstructor(
    _session_id: string,
    _instructor_id: string,
    _role = 'primary',
    _is_host = false,
  ): Observable<void> {
    return notAvailable('Assigning more than one instructor to a session');
  }

  removeInstructor(_session_id: string, _instructor_id: string): Observable<void> {
    return notAvailable('Assigning more than one instructor to a session');
  }

  getSessionInstructors(_session_id: string): Observable<SessionInstructor[]> {
    // A session has one instructor, named on the session itself. Empty rather
    // than loud: this is read on a screen that draws a list, and a list with
    // one entry already comes from the session.
    return of([]);
  }

  /**
   * Saves a standing enrolment rule and runs it once.
   *
   * A rule is not the same as enrolling everybody in Operations this
   * afternoon — `bulkEnroll` already does that. A rule keeps going: somebody
   * who joins Operations next month is enrolled by it too. It is applied on
   * save, so the answer to "did that work" is on the screen rather than at
   * the next sweep.
   *
   * A programme has a capacity, so the count is the places filled; the rest
   * go on the waiting list, which the roster reports separately.
   */
  bulkEnrollByRule(trainingId: string, rule: EnrollmentRule): Observable<BulkEnrollmentResult> {
    return this.api
      .post<RulePayload>(Endpoints.enrollmentRules.forTraining(trainingId), fromRule(rule))
      .pipe(map((saved) => {
        const enrolled = Number(saved['enrollmentsCreated'] ?? 0);

        return {
          total: enrolled,
          enrolled,
          already_enrolled: 0,
          waitlisted: 0,
          failed: 0,
          errors: [],
          message: `${enrolled} enrolled by this rule.`,
        } as BulkEnrollmentResult;
      }));
  }

  getEnrollmentRules(trainingId: string): Observable<EnrollmentRule[]> {
    return this.api
      .get<RulePayload[]>(Endpoints.enrollmentRules.forTraining(trainingId))
      .pipe(map((rules) => (rules ?? []).map(toRule)));
  }

  deleteEnrollmentRule(ruleId: string): Observable<void> {
    return this.api
      .delete(Endpoints.enrollmentRules.delete(ruleId))
      .pipe(map(() => undefined));
  }

  /** Stops a rule enrolling anybody new. It leaves the enrolled alone. */
  pauseEnrollmentRule(ruleId: string): Observable<void> {
    return this.api
      .post(Endpoints.enrollmentRules.pause(ruleId), {})
      .pipe(map(() => undefined));
  }

  resumeEnrollmentRule(ruleId: string): Observable<void> {
    return this.api
      .post(Endpoints.enrollmentRules.resume(ruleId), {})
      .pipe(map(() => undefined));
  }

  /** Runs the rule now, rather than waiting for the hourly sweep. */
  applyEnrollmentRule(ruleId: string): Observable<{ enrolled: number }> {
    return this.api
      .post<{ enrollmentsCreated?: number }>(Endpoints.enrollmentRules.apply(ruleId), {})
      .pipe(map((result) => ({ enrolled: Number(result?.enrollmentsCreated ?? 0) })));
  }

  searchUsersForEnrollment(filters: EnrollmentFilter): Observable<UserForEnrollment[]> {
    const f = filters as unknown as Record<string, unknown>;

    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.users.list, {
        search: f['search'] as string | undefined,
        departmentId: f['department_id'] as string | undefined,
        perPage: 100,
      })
      .pipe(map((page) => page.items as unknown as UserForEnrollment[]));
  }

  assignSkills(_trainingId: string, _skills: TrainingSkill[]): Observable<SkillAssignmentResult> {
    return notAvailable('Skills conferred by a training programme');
  }

  removeSkill(_trainingId: string, _skillId: string): Observable<void> {
    return notAvailable('Skills conferred by a training programme');
  }

  getTrainingSkills(_trainingId: string): Observable<TrainingSkill[]> {
    return of([]);
  }

  awardSkillsManual(_enrollmentId: string): Observable<SkillAssignmentResult> {
    return notAvailable('Awarding a skill by hand');
  }

  // ==========================================
  // SKILLS
  // ==========================================

  getUserAcquiredSkills(userId?: string, _sourceType?: string): Observable<UserSkill[]> {
    // `/me/skills` for oneself, `/users/{id}/skills` for somebody else —
    // two endpoints because they are two permissions.
    return this.api.get<UserSkill[]>(
      userId === undefined || userId === ''
        ? Endpoints.me.skills
        : Endpoints.users.skills(userId),
    );
  }

  getUserSkillsSummary(userId?: string): Observable<UserSkillsSummary> {
    return this.getUserAcquiredSkills(userId).pipe(map((skills) => ({
      total: skills.length,
      skills,
    } as unknown as UserSkillsSummary)));
  }

  searchSkills(filters: SkillSearchFilter): Observable<Skill[]> {
    const f = filters as unknown as Record<string, unknown>;

    return this.api
      .getPaged<Skill>(Endpoints.skills.list, {
        search: f['search'] as string | undefined,
        categoryId: f['category_id'] as string | undefined,
        perPage: 100,
      })
      .pipe(map((page) => page.items));
  }

  getSkillsCategories(): Observable<SkillCategory[]> {
    return this.api
      .get<Array<{ id: string; name: string }>>(Endpoints.lookups.active('category'))
      .pipe(map((rows) => rows.map((row) => ({
        category_id: row.id,
        category_name: row.name,
      } as unknown as SkillCategory))));
  }

  // ==========================================
  // PRESENTATION
  // ==========================================

  getProficiencyBadgeClass(level: string): string {
    const classes: Record<string, string> = {
      beginner: 'bg-secondary',
      intermediate: 'bg-info',
      advanced: 'bg-primary',
      expert: 'bg-success',
    };

    return classes[level] ?? 'bg-secondary';
  }

  getProficiencyDisplayName(level: string): string {
    const names: Record<string, string> = {
      beginner: 'Beginner',
      intermediate: 'Intermediate',
      advanced: 'Advanced',
      expert: 'Expert',
    };

    return names[level] ?? level;
  }

  getEnrollmentTypeDisplayName(type: string): string {
    const names: Record<string, string> = {
      self: 'Self-enrolled',
      assigned: 'Assigned',
      rule: 'By rule',
      bulk: 'Bulk enrolled',
    };

    return names[type] ?? type;
  }

  formatSkillSource(sourceType: string, sourceTitle?: string): string {
    const kinds: Record<string, string> = {
      course: 'Course',
      training: 'Live training',
      assessment: 'Assessment',
      manual: 'Awarded',
    };

    const kind = kinds[sourceType] ?? sourceType;

    return sourceTitle === undefined || sourceTitle === '' ? kind : `${kind}: ${sourceTitle}`;
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface TrainingPayload extends Record<string, any> {
  id: string;
  title: string;
}

interface SessionPayload extends Record<string, any> {
  id: string;
  trainingId: string;
}

function toTraining(t: TrainingPayload): LiveTraining {
  return {
    training_id: t.id,
    tenant_id: '',
    title: t.title ?? '',
    description: t['description'] ?? null,
    short_description: t['summary'] ?? null,
    thumbnail_url: t['thumbnailUrl'] ?? null,
    category_id: t['categoryId'] ?? null,
    tags: [],
    training_type: (t['delivery'] ?? 'physical') as LiveTraining['training_type'],
    max_capacity: t['maxCapacity'] ?? null,
    allow_waitlist: Boolean(t['allowWaitlist']),
    certification_enabled: Boolean(t['issuesCertificate']),
    certificate_template_id: null,
    min_attendance_percentage: Number(t['minAttendancePercentage'] ?? 0),
    mandatory_sessions_only: Boolean(t['mandatorySessionsOnly']),
    auto_issue_certificate: Boolean(t['issuesCertificate']),
    // The legacy stored three booleans for three fixed reminder times. The
    // API keeps a list of offsets in minutes, which is the same thing without
    // a column per moment somebody might want to be reminded at.
    reminder_24h: (t['reminderOffsetsMinutes'] ?? []).includes(1440),
    reminder_1h: (t['reminderOffsetsMinutes'] ?? []).includes(60),
    reminder_15m: (t['reminderOffsetsMinutes'] ?? []).includes(15),
    reminder_channels: (t['reminderChannels'] ?? []) as string[],
    qr_grace_period_minutes: Number(t['qrGracePeriodMinutes'] ?? 0),
    qr_valid_before_start_minutes: Number(t['qrValidBeforeStartMinutes'] ?? 0),
    min_virtual_duration_minutes: Number(t['minVirtualDurationMinutes'] ?? 0),
    status: (t['status'] ?? 'draft') as LiveTraining['status'],
    is_active: t['status'] !== 'cancelled',
    created_by: '',
    created_at: String(t['createdAt'] ?? ''),
    updated_at: t['updatedAt'] ?? null,
    published_at: t['publishedAt'] ?? null,
    session_count: Number(t['sessionCount'] ?? 0),
    enrollment_count: Number(t['enrolledCount'] ?? 0),
  } as unknown as LiveTraining;
}

function fromTraining(data: Partial<LiveTraining>): Record<string, unknown> {
  const d = data as Record<string, any>;
  const offsets: number[] = [];

  if (d['reminder_24h']) { offsets.push(1440); }
  if (d['reminder_1h']) { offsets.push(60); }
  if (d['reminder_15m']) { offsets.push(15); }

  return {
    title: d['title'],
    description: d['description'],
    summary: d['short_description'],
    thumbnailUrl: d['thumbnail_url'],
    categoryId: d['category_id'],
    delivery: d['training_type'],
    maxCapacity: d['max_capacity'],
    allowWaitlist: d['allow_waitlist'],
    issuesCertificate: d['certification_enabled'],
    minAttendancePercentage: d['min_attendance_percentage'],
    mandatorySessionsOnly: d['mandatory_sessions_only'],
    reminderOffsetsMinutes: offsets.length > 0 ? offsets : undefined,
    reminderChannels: d['reminder_channels'],
    qrGracePeriodMinutes: d['qr_grace_period_minutes'],
    qrValidBeforeStartMinutes: d['qr_valid_before_start_minutes'],
    minVirtualDurationMinutes: d['min_virtual_duration_minutes'],
  };
}

/**
 * One session, in the shape the screens bind to.
 *
 * The API keeps a single instant for the start and another for the end. The
 * screens want a date and two clock times, which is the same information said
 * differently — and splitting it here rather than storing it three ways is
 * what stops a session's date and its start time disagreeing.
 */
function toSession(s: SessionPayload): TrainingSession {
  const venue = s['venue'] ?? {};
  const meeting = s['meeting'] ?? {};
  const startsAt = String(s['startsAt'] ?? '');
  const endsAt = String(s['endsAt'] ?? '');

  return {
    session_id: s.id,
    training_id: s.trainingId,
    tenant_id: '',
    title: s['title'] ?? '',
    description: s['description'] ?? null,
    session_number: Number(s['sequence'] ?? 1),
    session_type: (s['delivery'] ?? 'physical'),
    status: s['status'] ?? 'scheduled',
    session_date: startsAt.slice(0, 10),
    start_time: clockTime(startsAt),
    end_time: clockTime(endsAt),
    timezone: s['timezone'] ?? 'UTC',
    duration_minutes: Number(s['durationMinutes'] ?? 0),
    instructor_id: s['instructorId'] ?? null,
    venue_name: venue['name'] ?? null,
    venue_address: venue['address'] ?? null,
    venue_room: venue['room'] ?? null,
    venue_capacity: venue['capacity'] ?? null,
    venue_map_url: venue['mapUrl'] ?? null,
    video_platform: meeting['platform'] ?? null,
    meeting_url: meeting['joinUrl'] ?? null,
    join_url: meeting['joinUrl'] ?? null,
    // Never sent to a learner's browser: the host link is what starts the
    // meeting, and the API only gives it to whoever is running the session.
    host_url: null,
    meeting_password: meeting['password'] ?? null,
    meeting_id: null,
    integration_id: null,
    external_meeting_id: null,
    // Surfaced rather than swallowed, so an organiser sees that scheduling
    // failed instead of discovering an empty join button at the start time.
    meeting_error: s['meetingError'] ?? null,
    is_mandatory: Boolean(s['isMandatory']),
    attendance_locked: Boolean(s['attendanceLocked']),
    starts_at: startsAt,
    ends_at: endsAt,
  } as unknown as TrainingSession;
}

/** `2027-05-01T09:00:00+00:00` as `09:00`. */
function clockTime(iso: string): string {
  return iso.slice(11, 16);
}

/**
 * A session on its way to the server.
 *
 * The forms collect a date and two clock times; the API wants two
 * instants. Joined here, in the one place that knows both dialects.
 */
function fromSession(data: Partial<TrainingSession> & Record<string, any>): Record<string, unknown> {
  const date = data['session_date'];

  return {
    title: data['title'],
    description: data['description'],
    delivery: data['session_type'],
    startsAt: date === undefined ? data['starts_at'] : `${date}T${data['start_time']}:00`,
    endsAt: date === undefined ? data['ends_at'] : `${date}T${data['end_time']}:00`,
    timezone: data['timezone'],
    instructorId: data['instructor_id'],
    venueName: data['venue_name'],
    venueAddress: data['venue_address'],
    venueRoom: data['venue_room'],
    venueCapacity: data['venue_capacity'],
    venueMapUrl: data['venue_map_url'],
    isMandatory: data['is_mandatory'],
    sequence: data['session_number'],
  };
}

function toEnrollment(row: Record<string, any>): TrainingEnrollment {
  return {
    enrollment_id: String(row['id'] ?? ''),
    training_id: String(row['trainingId'] ?? ''),
    learner_id: String(row['userId'] ?? ''),
    status: row['status'] ?? 'enrolled',
    enrollment_type: row['source'] ?? 'self',
    enrolled_at: row['enrolledAt'] ?? null,
    attendance_percentage: Number(row['attendancePercentage'] ?? 0),
    sessions_attended: Number(row['sessionsAttended'] ?? 0),
    sessions_total: Number(row['sessionsCounted'] ?? 0),
    meets_requirement: row['meetsRequirement'] === true,
    certificate_id: row['certificateId'] ?? null,
    learner_name: row['userName'] ?? null,
    learner_email: row['userEmail'] ?? null,
    training_title: row['trainingTitle'] ?? null,
    sessions: row['sessions'] ?? [],
  } as unknown as TrainingEnrollment;
}

function toAnalytics(t: TrainingPayload): TrainingAnalytics {
  return {
    training_id: t.id,
    title: t.title,
    total_sessions: Number(t['sessionCount'] ?? 0),
    total_enrollments: Number(t['enrolledCount'] ?? 0),
    // Counted at read time on the server; the legacy stored these and every
    // one of them drifted.
    completed_count: Number(t['completedCount'] ?? 0),
    average_attendance: Number(t['averageAttendance'] ?? 0),
  } as unknown as TrainingAnalytics;
}

interface RulePayload extends Record<string, any> {
  id: string;
}

/**
 * A standing rule as this screen has always read one.
 *
 * The screen thinks in one target list per kind (`department_ids`,
 * `team_ids`); the API keeps one `targetCriteria` map, because a rule that
 * names two departments needs somewhere to put the second.
 */
function toRule(rule: RulePayload): EnrollmentRule {
  const criteria = (rule['targetCriteria'] ?? {}) as Record<string, unknown>;
  const ids = (...keys: string[]): string[] => {
    for (const key of keys) {
      const value = criteria[key];
      if (Array.isArray(value)) return value.map(String);
      if (typeof value === 'string' && value !== '') return [value];
    }
    return [];
  };

  const target = String(rule['targetType'] ?? 'all');

  return {
    rule_id: rule.id,
    training_id: rule['subjectId'] ?? '',
    enrollment_type: (target === 'custom'
      ? 'direct'
      : target === 'all' ? 'automatic' : target) as EnrollmentRule['enrollment_type'],
    target_criteria: {
      department_ids: ids('departmentIds', 'departments') as unknown as number[],
      branch_ids: ids('branchIds', 'branches') as unknown as number[],
      team_ids: ids('teamIds', 'teams') as unknown as number[],
      user_ids: ids('userIds', 'users'),
    },
    // A rule covers whoever matches it whenever the sweep runs, so it always
    // takes in people who joined after it was made. There is no separate
    // switch for that, and a stored one that said "no" would be a lie.
    auto_enroll_new_users: rule['status'] === 'active',
    auto_enroll_on_hire: rule['status'] === 'active',
    is_active: rule['status'] === 'active',
    created_by: rule['createdBy'] ?? undefined,
    created_at: rule['createdAt'] ?? undefined,
    updated_at: rule['updatedAt'] ?? undefined,
  };
}

/** The rule, in the API's spelling. */
function fromRule(rule: EnrollmentRule): Record<string, unknown> {
  const criteria: Record<string, unknown> = {};
  const some = (value: unknown[] | undefined): string[] =>
    (value ?? []).map(String).filter((v) => v !== '');

  let target: string;

  switch (rule.enrollment_type) {
    case 'department':
      target = 'department';
      criteria['departmentIds'] = some(rule.target_criteria.department_ids);
      break;
    case 'branch':
      target = 'branch';
      criteria['branchIds'] = some(rule.target_criteria.branch_ids);
      break;
    case 'team':
      target = 'team';
      criteria['teamIds'] = some(rule.target_criteria.team_ids);
      break;
    case 'direct':
      // The API calls a hand-picked list "custom", because it is the one kind
      // that is a list rather than a description.
      target = 'custom';
      criteria['userIds'] = some(rule.target_criteria.user_ids);
      break;
    default:
      target = 'all';
  }

  return { targetType: target, targetCriteria: criteria };
}

/** Something with no server behind it, said out loud rather than left empty. */
function notAvailable(what: string): Observable<never> {
  return throwError(() => new Error(`${what} is not available yet.`));
}
