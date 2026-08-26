import { ApiModule } from './api-module';

/**
 * Every API path this application calls, grouped by the module that owns it.
 *
 * Replaces `GlobalComponent`, which held 97 flat `static` strings each
 * concatenating a hardcoded production URL. Paths here are *relative*: the
 * base URL comes from `ApiRouter`, so a module can be migrated without editing
 * this file at all.
 *
 * Three bugs the old constants shipped with, fixed by construction:
 *
 *  - `utility/shared/user` had no leading slash, producing
 *    `https://api.centrallms.comutility/shared/user`
 *  - `mark_notifications` had a doubled slash
 *  - `updateCourseStatus` and `deleteCourse` were declared and never assigned,
 *    so they were `undefined` at runtime
 */

/** The six operations every legacy dictionary resource exposes. */
export interface CrudPaths {
  readonly create: string;
  readonly update: string;
  readonly list: string;
  readonly single: string;
  readonly status: string;
  readonly active: string;
}

/**
 * Builds the standard six paths for a dictionary resource.
 *
 * The seven dictionary resources are 42 of the 96 live endpoints and are
 * byte-for-byte identical apart from their segment — which is exactly why the
 * new API collapses them to one generic controller. Expressing that here makes
 * the duplication visible instead of copying it 42 times.
 */
function crud(base: string): CrudPaths {
  return {
    create: `${base}/create`,
    update: `${base}/update`,
    list: `${base}/list`,
    single: `${base}/single`,
    status: `${base}/status`,
    active: `${base}/active`,
  };
}

/** The reference-data kinds the new API understands. */
export type LookupKind =
  | 'branch'
  | 'department'
  | 'team'
  | 'level'
  | 'job_title'
  | 'category';

/** REST paths for one reference-data kind on the new API. */
export interface LookupPaths {
  readonly list: string;
  readonly active: string;
  readonly create: string;
  readonly one: (id: string) => string;
  readonly status: (id: string) => string;
}

/**
 * Six routes replace the 36 legacy endpoints — the kind is a path parameter
 * rather than a separate set of files per resource.
 */
function lookupPaths(kind: LookupKind): LookupPaths {
  const base = `/lookups/${kind}`;

  return {
    list: base,
    active: `${base}/active`,
    create: base,
    one: (id: string) => `${base}/${id}`,
    status: (id: string) => `${base}/${id}/status`,
  };
}

export const Endpoints = {
  platform: {
    module: ApiModule.Platform,

    /**
     * `me` is inside the version prefix; `health` and `version` are not — a
     * load balancer probing them should not have to know about `/v1`. So the
     * first two resolve to `/v1/health` here and would 404.
     *
     * Left as-is because nothing calls them: no screen in this application
     * shows a health check. A diagnostics page that wants one should call the
     * host directly rather than through a module base that assumes a version.
     */
    health: '/health',
    version: '/version',
    me: '/me',
  },

  auth: {
    module: ApiModule.Auth,

    /**
     * The new API renamed the segment and split refresh out of login, so both
     * spellings are declared and `ApiRouter` picks. A caller writes the same
     * line either side of the migration.
     */
    login: { legacy: '/authentication/login', v1: '/auth/login' },

    /**
     * No legacy equivalent: the old backend issued one long-lived token and
     * never rotated it. Pointed at the legacy login so a mis-flip fails
     * loudly with a 4xx rather than silently signing somebody out.
     */
    refresh: { legacy: '/authentication/login', v1: '/auth/refresh' },

    /**
     * Also new. The legacy had no logout at all — a token stayed valid until
     * it expired, which is why signing out only cleared the browser.
     */
    logout: { legacy: '/authentication/login', v1: '/auth/logout' },

    register: '/authentication/register',
    validate: '/authentication/validate',
    validateEmail: '/authentication/validate-email',
    reset: '/authentication/reset',
    confirm: '/authentication/confirm',
  },

  users: {
    module: ApiModule.Users,
    list: '/user/user_list',
    collection: '/user/users',
    single: '/user/single',
    create: '/user/create',
    update: '/user/update',
    status: '/user/status',
    changePassword: '/user/change_password',
    statistics: '/user/statistics',
    instructors: '/user/instructor',
    notes: '/user/notes/list',
  },

  /**
   * Reference data.
   *
   * The only module so far whose **path shape** changes on migration, not just
   * its host. The legacy backend has six endpoints per resource; the new API
   * has six routes in total, parameterised by kind.
   *
   * Both shapes live here. A service picks between them with
   * `ApiRouter.isMigrated(ApiModule.Dictionary)` — see `lookupPaths()`.
   */
  dictionary: {
    module: ApiModule.Dictionary,

    /** Legacy: `/dictionary/branches/create`, `/dictionary/branches/list`, … */
    legacy: {
      branches: crud('/dictionary/branches'),
      categories: crud('/dictionary/categories'),
      departments: crud('/dictionary/departments'),
      jobTitles: crud('/dictionary/jobtitles'),
      levels: crud('/dictionary/levels'),
      teams: crud('/dictionary/team'),
    },

    /** New API: `/lookups/{kind}` with real HTTP verbs. */
    v1: (kind: LookupKind) => lookupPaths(kind),

    /**
     * Certificate templates are served under `/dictionary/` by the legacy
     * backend but are not reference data — 12 columns including template
     * bodies. They move with the certificates module, so they keep the legacy
     * shape only.
     */
    certificateTemplates: crud('/dictionary/templates'),
  },

  courses: {
    module: ApiModule.Courses,
    ...crud('/user/courses'),
    root: '/user/courses',
    insights: '/user/courses/insights',
    duplicate: '/user/courses/duplicate',
    content: '/user/course-content',
    player: '/user/course-player',
  },

  enrollments: {
    module: ApiModule.Enrollments,
    course: '/user/course-enrollment',
    training: '/user/training-enrollment',
    learningPath: '/user/learning-path-enrollment',
  },

  assessments: {
    module: ApiModule.Assessments,
    assessment: '/user/assessment',
    attempt: '/user/attempt',
    questionBank: '/user/question-bank',
    grading: '/user/grading',
  },

  proctoring: {
    module: ApiModule.Proctoring,
    proctoring: '/user/proctoring',
  },

  ilt: {
    module: ApiModule.Ilt,
    liveTraining: '/user/live-training',
    integrationCallback: '/user/integration-callback',
    /** New API only — lists the platforms this tenant has connected. */
    providers: '/ilt/providers',
  },

  learningPaths: {
    module: ApiModule.LearningPaths,
    paths: '/user/learning-paths',
    enrollment: '/user/learning-path-enrollment',
  },

  certificates: {
    module: ApiModule.Certificates,
    certificate: '/user/certificate',
  },

  media: {
    module: ApiModule.Media,
    upload: '/user/media/upload',
    storage: '/user/media/storage',
    versions: '/user/media/versions',
    read: '/user/media/read',
    delete: '/user/media/delete',
    uploads: '/user/uploads',
  },

  messaging: {
    module: ApiModule.Messaging,
    messaging: '/user/messaging',
  },

  skills: {
    module: ApiModule.Skills,
    skills: '/user/skills',
  },

  reports: {
    module: ApiModule.Reports,
    /**
     * The live reporting surface. `/api/reports` and `/user/procreports` are
     * deliberately absent — both were declared in `GlobalComponent` and never
     * called, and they are backed by two abandoned parallel implementations.
     * See docs/migration/live-surface.md.
     */
    admin: '/user/reports',
    learner: '/user/learner-report',
  },

  dashboards: {
    module: ApiModule.Dashboards,
    admin: '/user/admin/dashboard',
    learner: '/user/learner/dashboard',
    myLearning: '/user/my-learning',
  },

  tenantSettings: {
    module: ApiModule.TenantSettings,
    settings: '/user/tenant-settings',
  },
} as const;
