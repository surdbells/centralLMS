/**
 * Every path this application calls, in one place.
 *
 * Replaces `GlobalComponent`, which was 192 lines of string concatenation
 * against a hardcoded production host — `public static baseURL =
 * 'https://api.centrallms.com'` — with the base URL glued on at each of its
 * ~130 constants. Two of them were malformed (`...comutility/shared/user`,
 * `.../shared//mark_notifications`) and nothing noticed, because the strings
 * were never checked against anything.
 *
 * Here a path is a path: no host, no credentials, no query string. {@link ApiRouter}
 * makes it absolute and {@link ApiClient} sends it. Parameterised paths are
 * functions, so an id cannot be forgotten or interpolated in the wrong place.
 */
export const Endpoints = {
  // --- operational ------------------------------------------------------
  platform: {
    health: '/health',
    version: '/version',
  },

  // --- identity ---------------------------------------------------------
  auth: {
    login: '/auth/login',
    refresh: '/auth/refresh',
    logout: '/auth/logout',

    /**
     * Setting a password without being signed in. Public by necessity,
     * and each one deliberately says as little as it can.
     */
    forgotPassword: '/auth/password/forgot',
    checkPasswordToken: '/auth/password/token',
    resetPassword: '/auth/password/reset',
    /** The signed-in person, with their resolved permissions. */
    me: '/me',
  },

  /**
   * Signing in from Google or Microsoft Entra. The first two are public;
   * the rest need `settings.manage`, and none of them ever returns a
   * client secret.
   */
  sso: {
    providers: (tenantId: string) => `/tenants/${tenantId}/sso`,
    start: '/auth/sso/start',
    callback: '/auth/sso/callback',
    list: '/sso',
    save: (provider: string) => `/sso/${provider}`,
    delete: (provider: string) => `/sso/${provider}`,
  },

  /**
   * Registering yourself. The organisation is in the path because an
   * account has to belong to one, and because the link is something an
   * administrator hands out for their own organisation.
   */
  registration: {
    show: (tenantId: string) => `/tenants/${tenantId}/registration`,
    register: (tenantId: string) => `/tenants/${tenantId}/register`,
  },

  users: {
    list: '/users',
    create: '/users',
    statistics: '/users/statistics',
    instructors: '/users/instructors',
    show: (id: string) => `/users/${id}`,
    update: (id: string) => `/users/${id}`,
    status: (id: string) => `/users/${id}/status`,
    delete: (id: string) => `/users/${id}`,
    password: (id: string) => `/users/${id}/password`,
    skills: (id: string) => `/users/${id}/skills`,

    /**
     * Adding a lot of people at once.
     *
     * Nothing is kept on the server between the two: the client sends back
     * the rows the preview showed it, and the commit revalidates them.
     */
    importPreview: '/users/import/preview',
    import: '/users/import',
    /** A colleague's picture, served through the API rather than storage. */
    avatar: (id: string) => `/users/${id}/avatar`,
  },

  /**
   * The audit trail: who did what, to what, and from where.
   *
   * Read-only. There is no route that writes an entry — they come from the
   * API's own middleware, from what the router matched — and none that
   * deletes one.
   */
  audit: {
    list: '/audit',
    /** The actions this organisation's trail actually contains. */
    actions: '/audit/actions',
  },

  /**
   * Roles, and what each may do.
   *
   * Assigning one is not here: a person's roles are set as a set by
   * `users.update`. The legacy had `assign-role` and `remove-role` as well,
   * and the three disagreed about whether somebody could be left with none.
   */
  roles: {
    list: '/roles',
    create: '/roles',
    /** Every permission a role may grant, grouped for the editor. */
    permissions: '/roles/permissions',
    show: (id: string) => `/roles/${id}`,
    update: (id: string) => `/roles/${id}`,
    delete: (id: string) => `/roles/${id}`,
  },

  /**
   * Reference data — branches, departments, teams, levels, job titles,
   * categories.
   *
   * Six routes parameterised by kind, where the legacy had six endpoints per
   * kind across 52 files. `lookupPaths()` below gives one kind's set.
   */
  lookups: {
    list: (kind: LookupKind) => `/lookups/${kind}`,
    active: (kind: LookupKind) => `/lookups/${kind}/active`,
    create: (kind: LookupKind) => `/lookups/${kind}`,
    show: (kind: LookupKind, id: string) => `/lookups/${kind}/${id}`,
    update: (kind: LookupKind, id: string) => `/lookups/${kind}/${id}`,
    status: (kind: LookupKind, id: string) => `/lookups/${kind}/${id}/status`,
  },

  // --- catalogue --------------------------------------------------------
  courses: {
    /** What a learner may browse: published, and visible to them. */
    catalogue: '/courses/catalogue',
    list: '/courses',
    create: '/courses',
    show: (id: string) => `/courses/${id}`,
    update: (id: string) => `/courses/${id}`,
    status: (id: string) => `/courses/${id}/status`,
    statistics: '/courses/statistics',
    /** Soft, and refused while anybody is enrolled. */
    delete: (id: string) => `/courses/${id}`,
    /** The course, its modules and its lessons — as a draft. */
    duplicate: (id: string) => `/courses/${id}/duplicate`,
    /** Modules and lessons in one call, for the player. */
    outline: (id: string) => `/courses/${id}/outline`,
    selfEnroll: (id: string) => `/courses/${id}/enroll`,
    skills: (id: string) => `/courses/${id}/skills`,
  },

  modules: {
    list: (courseId: string) => `/courses/${courseId}/modules`,
    create: (courseId: string) => `/courses/${courseId}/modules`,
    reorder: (courseId: string) => `/courses/${courseId}/modules/order`,
    show: (courseId: string, id: string) => `/courses/${courseId}/modules/${id}`,
    update: (courseId: string, id: string) => `/courses/${courseId}/modules/${id}`,
    status: (courseId: string, id: string) => `/courses/${courseId}/modules/${id}/status`,
    delete: (courseId: string, id: string) => `/courses/${courseId}/modules/${id}`,
  },

  lessons: {
    list: (courseId: string, moduleId: string) => `/courses/${courseId}/modules/${moduleId}/lessons`,
    create: (courseId: string, moduleId: string) =>
      `/courses/${courseId}/modules/${moduleId}/lessons`,
    reorder: (courseId: string, moduleId: string) =>
      `/courses/${courseId}/modules/${moduleId}/lessons/order`,
    show: (courseId: string, moduleId: string, id: string) =>
      `/courses/${courseId}/modules/${moduleId}/lessons/${id}`,
    update: (courseId: string, moduleId: string, id: string) =>
      `/courses/${courseId}/modules/${moduleId}/lessons/${id}`,
    status: (courseId: string, moduleId: string, id: string) =>
      `/courses/${courseId}/modules/${moduleId}/lessons/${id}/status`,
    delete: (courseId: string, moduleId: string, id: string) =>
      `/courses/${courseId}/modules/${moduleId}/lessons/${id}`,
  },

  // --- learning ---------------------------------------------------------
  enrollments: {
    list: (courseId: string) => `/courses/${courseId}/enrollments`,
    assign: (courseId: string) => `/courses/${courseId}/enrollments`,
    unenroll: (courseId: string) => `/courses/${courseId}/enrollments`,
    stats: (courseId: string) => `/courses/${courseId}/enrollments/stats`,
    history: (courseId: string, userId: string) =>
      `/courses/${courseId}/enrollments/${userId}/history`,
  },

  /**
   * The learner's own view. No user id in any of these — the caller is the
   * subject, which is what stops one person reading another's progress by
   * changing a number.
   */
  me: {
    enrollments: '/me/enrollments',
    course: (courseId: string) => `/me/enrollments/${courseId}`,
    recordProgress: (courseId: string, lessonId: string) =>
      `/me/enrollments/${courseId}/lessons/${lessonId}/progress`,

    // The player's Learners and Discuss tabs, both gated on being enrolled.
    classmates: (courseId: string) => `/me/enrollments/${courseId}/classmates`,
    discussion: (courseId: string) => `/me/enrollments/${courseId}/discussion`,
    deleteDiscussionPost: (courseId: string, postId: string) =>
      `/me/enrollments/${courseId}/discussion/${postId}`,
    certificates: '/me/certificates',
    /** The assessments this person has sat — not a marker's queue. */
    attempts: '/me/attempts',
    /** The ILT programmes this person is booked on — not a roster. */
    trainings: '/me/trainings',
    gamification: '/me/gamification',
    gamificationHistory: '/me/gamification/history',
    skills: '/me/skills',

    /**
     * Self-service. No id in any of these paths, so there is nothing to
     * tamper with — the server acts on whoever the token says is calling.
     */
    profile: '/me/profile',
    consents: '/me/consents',
    avatar: '/me/avatar',
    sessions: '/me/sessions',
    revokeSession: (id: string) => `/me/sessions/${id}`,
    dashboard: '/dashboards/me',
  },

  // --- assessment -------------------------------------------------------
  assessments: {
    list: '/assessments',
    create: '/assessments',
    stats: '/assessments/stats',
    show: (id: string) => `/assessments/${id}`,
    update: (id: string) => `/assessments/${id}`,
    delete: (id: string) => `/assessments/${id}`,
    duplicate: (id: string) => `/assessments/${id}/duplicate`,
    status: (id: string) => `/assessments/${id}/status`,

    sections: (id: string) => `/assessments/${id}/sections`,
    createSection: (id: string) => `/assessments/${id}/sections`,
    updateSection: (id: string, sectionId: string) =>
      `/assessments/${id}/sections/${sectionId}`,
    deleteSection: (id: string, sectionId: string) =>
      `/assessments/${id}/sections/${sectionId}`,
    reorderSections: (id: string) => `/assessments/${id}/sections/order`,

    rules: (id: string) => `/assessments/${id}/rules`,
    createRule: (id: string) => `/assessments/${id}/rules`,
    deleteRule: (id: string, ruleId: string) => `/assessments/${id}/rules/${ruleId}`,

    questions: (id: string) => `/assessments/${id}/questions`,
    detachQuestion: (id: string, questionId: string) =>
      `/assessments/${id}/questions/${questionId}`,
    setQuestionPoints: (id: string, questionId: string) =>
      `/assessments/${id}/questions/${questionId}`,
    reorderQuestions: (id: string) => `/assessments/${id}/questions/order`,
    setQuestionSection: (id: string, questionId: string) =>
      `/assessments/${id}/questions/${questionId}/section`,

    assignments: (id: string) => `/assessments/${id}/assignments`,
    deleteAssignment: (id: string, assignmentId: string) =>
      `/assessments/${id}/assignments/${assignmentId}`,
    assignmentTargets: '/assessments/assignment-targets',
  },

  banks: {
    list: '/question-banks',
    create: '/question-banks',
    show: (id: string) => `/question-banks/${id}`,
    update: (id: string) => `/question-banks/${id}`,
    delete: (id: string) => `/question-banks/${id}`,
  },

  questions: {
    list: (bankId: string) => `/question-banks/${bankId}/questions`,
    create: (bankId: string) => `/question-banks/${bankId}/questions`,
    update: (bankId: string, id: string) => `/question-banks/${bankId}/questions/${id}`,
  },

  attempts: {
    start: (assessmentId: string) => `/assessments/${assessmentId}/attempts`,
    show: (id: string) => `/attempts/${id}`,
    answer: (id: string, questionId: string) => `/attempts/${id}/answers/${questionId}`,
    submit: (id: string) => `/attempts/${id}/submit`,
    /** What a marker has waiting on one assessment. */
    queue: (assessmentId: string) => `/assessments/${assessmentId}/attempts`,
    /** What they have waiting everywhere, longest-waiting first. */
    awaiting: '/attempts/awaiting',
    grade: (id: string, questionId: string) => `/attempts/${id}/answers/${questionId}/grade`,
  },

  /**
   * Marking rubrics.
   *
   * Reading one needs `assessment.grade` — a marker has to see what they
   * are marking against. Writing one is authoring, and needs
   * `assessment.manage`.
   */
  rubrics: {
    list: '/rubrics',
    create: '/rubrics',
    show: (id: string) => `/rubrics/${id}`,
    update: (id: string) => `/rubrics/${id}`,
    delete: (id: string) => `/rubrics/${id}`,
    duplicate: (id: string) => `/rubrics/${id}/duplicate`,
  },

  /**
   * Who is marking what.
   *
   * The queue itself is derived from the attempts; these paths are about
   * the decisions a person made on top of it.
   */
  grading: {
    queue: '/grading/queue',
    assignMany: '/grading/assign',
    assign: (attemptId: string) => `/grading/queue/${attemptId}`,
    release: (attemptId: string) => `/grading/queue/${attemptId}`,
    skip: (attemptId: string) => `/grading/queue/${attemptId}/skip`,
    prioritise: (attemptId: string) => `/grading/queue/${attemptId}/priority`,
  },

  proctoring: {
    settings: (assessmentId: string) => `/assessments/${assessmentId}/proctoring`,
    openSession: (attemptId: string) => `/attempts/${attemptId}/proctoring/session`,
    checkSession: (attemptId: string) => `/attempts/${attemptId}/proctoring/session`,
    recordViolation: (attemptId: string) => `/attempts/${attemptId}/proctoring/violations`,
    recordSnapshot: (attemptId: string) => `/attempts/${attemptId}/proctoring/snapshots`,
    /** One assessment's flagged sittings. */
    reviewQueue: (assessmentId: string) => `/assessments/${assessmentId}/proctoring/queue`,
    /** Every flagged sitting. `?pending=1` for the ones not yet signed off. */
    allQueues: '/proctoring/queue',
    review: (attemptId: string) => `/attempts/${attemptId}/proctoring`,
    reviewViolation: (violationId: string) => `/proctoring/violations/${violationId}`,
  },

  // --- instructor-led training -----------------------------------------
  trainings: {
    list: '/trainings',
    create: '/trainings',
    show: (id: string) => `/trainings/${id}`,
    update: (id: string) => `/trainings/${id}`,
    status: (id: string) => `/trainings/${id}/status`,
    delete: (id: string) => `/trainings/${id}`,
    roster: (id: string) => `/trainings/${id}/roster`,
    assign: (id: string) => `/trainings/${id}/roster`,
    /** Takes the people to withdraw in the body, so several go at once. */
    withdraw: (id: string) => `/trainings/${id}/roster`,
    selfEnrol: (id: string) => `/trainings/${id}/enrol`,
    /** The skills the programme confers, and setting them. */
    skills: (id: string) => `/trainings/${id}/skills`,
  },

  sessions: {
    create: (trainingId: string) => `/trainings/${trainingId}/sessions`,
    update: (trainingId: string, id: string) => `/trainings/${trainingId}/sessions/${id}`,
    status: (trainingId: string, id: string) => `/trainings/${trainingId}/sessions/${id}/status`,
    /** Books the real meeting with Zoom, Meet or Teams. */
    meeting: (trainingId: string, id: string) => `/trainings/${trainingId}/sessions/${id}/meeting`,
    qr: (trainingId: string, id: string) => `/trainings/${trainingId}/sessions/${id}/qr`,
    reminders: (trainingId: string, id: string) =>
      `/trainings/${trainingId}/sessions/${id}/reminders`,

    /**
     * Who teaches it: a lead, co-instructors, assistants. One of them holds
     * the meeting's host link, because the provider only issues one.
     */
    instructors: (trainingId: string, id: string) =>
      `/trainings/${trainingId}/sessions/${id}/instructors`,
    removeInstructor: (trainingId: string, id: string, userId: string) =>
      `/trainings/${trainingId}/sessions/${id}/instructors/${userId}`,
  },

  attendance: {
    register: (trainingId: string, sessionId: string) =>
      `/trainings/${trainingId}/sessions/${sessionId}/attendance`,
    mark: (trainingId: string, sessionId: string) =>
      `/trainings/${trainingId}/sessions/${sessionId}/attendance`,
    checkIn: (trainingId: string, sessionId: string) =>
      `/trainings/${trainingId}/sessions/${sessionId}/check-in`,
    /**
     * Checking in with only the code.
     *
     * Somebody standing at a door has a code and nothing else, so the
     * code is what names the session. `checkIn` above needs both ids,
     * which a scanner cannot supply.
     */
    scan: '/me/trainings/check-in',
    sync: (trainingId: string, sessionId: string) =>
      `/trainings/${trainingId}/sessions/${sessionId}/attendance/sync`,
  },

  /**
   * Conferencing accounts, connected per tenant.
   *
   * No key is ever shipped to the browser: the API holds the credentials
   * encrypted and the OAuth exchange happens server-side. The original
   * `environment.ts` had a live Zoom API key committed in it.
   */
  integrations: {
    list: '/ilt/integrations',
    connect: (provider: string) => `/ilt/integrations/${provider}/connect`,
    callback: (provider: string) => `/ilt/integrations/${provider}/callback`,
    update: (provider: string) => `/ilt/integrations/${provider}`,
    disconnect: (provider: string) => `/ilt/integrations/${provider}`,
    providers: '/ilt/providers',
  },

  // --- certificates -----------------------------------------------------
  certificateTemplates: {
    list: '/certificate-templates',
    create: '/certificate-templates',
    show: (id: string) => `/certificate-templates/${id}`,
    update: (id: string) => `/certificate-templates/${id}`,
    delete: (id: string) => `/certificate-templates/${id}`,
    preview: (id: string) => `/certificate-templates/${id}/preview`,
  },

  certificates: {
    list: '/certificates',
    issue: '/certificates',
    show: (id: string) => `/certificates/${id}`,
    /** The rendered document. */
    render: (id: string) => `/certificates/${id}/render`,
    history: (id: string) => `/certificates/${id}/history`,
    revoke: (id: string) => `/certificates/${id}/revoke`,
    reinstate: (id: string) => `/certificates/${id}/reinstate`,
    /** Unauthenticated on purpose — a stranger checks a certificate. */
    verify: (hash: string) => `/verify/${hash}`,
    verifyByCode: '/verify',
  },

  // --- media ------------------------------------------------------------
  media: {
    list: '/media',
    upload: '/media',
    statistics: '/media/statistics',
    show: (id: string) => `/media/${id}`,
    update: (id: string) => `/media/${id}`,
    delete: (id: string) => `/media/${id}`,
    /** The bytes, served through the API so every fetch is checked. */
    file: (id: string) => `/media/${id}/file`,
    versions: (id: string) => `/media/${id}/versions`,
    uploadVersion: (id: string) => `/media/${id}/versions`,
    restore: (id: string) => `/media/${id}/restore`,
    ticket: (id: string) => `/media/${id}/ticket`,
  },

  // --- notes ------------------------------------------------------------
  notes: {
    list: '/me/notes',
    create: '/me/notes',
    statistics: '/me/notes/statistics',
    show: (id: string) => `/me/notes/${id}`,
    update: (id: string) => `/me/notes/${id}`,
    pin: (id: string) => `/me/notes/${id}/pin`,
    delete: (id: string) => `/me/notes/${id}`,
  },

  // --- skills -----------------------------------------------------------
  skills: {
    list: '/skills',
    create: '/skills',
    statistics: '/skills/statistics',
    categories: '/skills/categories',
    createCategory: '/skills/categories',
    updateCategory: (id: string) => `/skills/categories/${id}`,
    deleteCategory: (id: string) => `/skills/categories/${id}`,
    show: (id: string) => `/skills/${id}`,
    update: (id: string) => `/skills/${id}`,
    delete: (id: string) => `/skills/${id}`,
    courses: (id: string) => `/skills/${id}/courses`,
    learners: (id: string) => `/skills/${id}/learners`,
  },

  // --- competency assurance ---------------------------------------------
  //
  // The dictionary, the job profiles built from it, and the assessments
  // that measure a person against their profile. The gap between required
  // and demonstrated is computed by the API, never sent up.
  competencies: {
    list: '/competencies',
    create: '/competencies',
    functions: '/competencies/functions',
    /** Uploading the completed JCP workbooks. */
    import: '/competencies/import',
    /** A blank JCP workbook to fill in and upload. */
    importTemplate: '/competencies/import/template',
    show: (id: string) => `/competencies/${id}`,
    update: (id: string) => `/competencies/${id}`,
    delete: (id: string) => `/competencies/${id}`,
  },

  jobProfiles: {
    list: '/job-profiles',
    create: '/job-profiles',
    show: (id: string) => `/job-profiles/${id}`,
    update: (id: string) => `/job-profiles/${id}`,
    delete: (id: string) => `/job-profiles/${id}`,
  },

  competencyAssessments: {
    list: '/competency-assessments',
    create: '/competency-assessments',
    /** The organisation-wide outcome for a cycle. */
    outcome: '/competency-assessments/outcome',
    show: (id: string) => `/competency-assessments/${id}`,
    /** The learner records their own levels. */
    self: (id: string) => `/competency-assessments/${id}/self`,
    /** The expert confirms the levels. */
    review: (id: string) => `/competency-assessments/${id}/review`,
    finalize: (id: string) => `/competency-assessments/${id}/finalize`,
    delete: (id: string) => `/competency-assessments/${id}`,
  },

  // --- learning paths ---------------------------------------------------
  paths: {
    list: '/paths',
    create: '/paths',
    statistics: '/paths/statistics',
    catalogue: '/paths/catalogue',
    show: (id: string) => `/paths/${id}`,
    update: (id: string) => `/paths/${id}`,
    status: (id: string) => `/paths/${id}/status`,
    delete: (id: string) => `/paths/${id}`,

    sections: (id: string) => `/paths/${id}/sections`,
    createSection: (id: string) => `/paths/${id}/sections`,
    updateSection: (id: string, sectionId: string) => `/paths/${id}/sections/${sectionId}`,
    deleteSection: (id: string, sectionId: string) => `/paths/${id}/sections/${sectionId}`,

    items: (id: string) => `/paths/${id}/items`,
    createItem: (id: string) => `/paths/${id}/items`,
    reorderItems: (id: string) => `/paths/${id}/items/order`,
    updateItem: (id: string, itemId: string) => `/paths/${id}/items/${itemId}`,
    deleteItem: (id: string, itemId: string) => `/paths/${id}/items/${itemId}`,

    enrollments: (id: string) => `/paths/${id}/enrollments`,
    enrol: (id: string) => `/paths/${id}/enrollments`,
    withdraw: (id: string, userId: string) => `/paths/${id}/enrollments/${userId}`,

    mine: '/me/paths',
    mineShow: (id: string) => `/me/paths/${id}`,
    selfEnrol: (id: string) => `/me/paths/${id}/enroll`,
    completeItem: (id: string, itemId: string) => `/me/paths/${id}/items/${itemId}/complete`,

    // Reviews. Reading and writing hang off the path; everything a moderator
    // does is addressed by review, because the queue spans every path.
    reviews: (id: string) => `/paths/${id}/reviews`,
    reviewSummary: (id: string) => `/paths/${id}/reviews/summary`,
    createReview: (id: string) => `/paths/${id}/reviews`,
    myReview: (id: string) => `/me/paths/${id}/review`,

  },

  /**
   * Standing enrolment rules, addressed by rule rather than by what they
   * enrol people onto. One set of paths for every subject, because a rule
   * behaves identically whichever it is.
   */
  enrollmentRules: {
    forPath: (pathId: string) => `/paths/${pathId}/assignments`,
    forTraining: (trainingId: string) => `/trainings/${trainingId}/rules`,

    update: (id: string) => `/enrollment-rules/${id}`,
    delete: (id: string) => `/enrollment-rules/${id}`,
    pause: (id: string) => `/enrollment-rules/${id}/pause`,
    resume: (id: string) => `/enrollment-rules/${id}/resume`,
    cancel: (id: string) => `/enrollment-rules/${id}/cancel`,
    apply: (id: string) => `/enrollment-rules/${id}/apply`,
    reminders: (id: string) => `/enrollment-rules/${id}/reminders`,
    preview: (id: string) => `/enrollment-rules/${id}/preview`,
    statistics: (id: string) => `/enrollment-rules/${id}/statistics`,
  },

  pathReviews: {
    pending: '/path-reviews/pending',
    delete: (reviewId: string) => `/path-reviews/${reviewId}`,
    approve: (reviewId: string) => `/path-reviews/${reviewId}/approve`,
    reject: (reviewId: string) => `/path-reviews/${reviewId}/reject`,
    respond: (reviewId: string) => `/path-reviews/${reviewId}/response`,
    helpful: (reviewId: string) => `/path-reviews/${reviewId}/helpful`,
    report: (reviewId: string) => `/path-reviews/${reviewId}/report`,
  },

  // --- messaging --------------------------------------------------------
  conversations: {
    list: '/me/conversations',
    start: '/me/conversations',
    unread: '/me/conversations/unread',
    show: (id: string) => `/me/conversations/${id}`,
    update: (id: string) => `/me/conversations/${id}`,
    read: (id: string) => `/me/conversations/${id}/read`,
    addParticipants: (id: string) => `/me/conversations/${id}/participants`,
    removeParticipant: (id: string, userId: string) =>
      `/me/conversations/${id}/participants/${userId}`,
    messages: (id: string) => `/me/conversations/${id}/messages`,
    send: (id: string) => `/me/conversations/${id}/messages`,
    editMessage: (id: string, messageId: string) =>
      `/me/conversations/${id}/messages/${messageId}`,
    deleteMessage: (id: string, messageId: string) =>
      `/me/conversations/${id}/messages/${messageId}`,

    /** A toggle: the same emoji twice takes it back. */
    react: (id: string, messageId: string) =>
      `/me/conversations/${id}/messages/${messageId}/reactions`,

    /** Pinning is the conversation's; saving is the reader's own. */
    pinned: (id: string) => `/me/conversations/${id}/pinned`,
    pin: (id: string, messageId: string) =>
      `/me/conversations/${id}/messages/${messageId}/pin`,
    save: (id: string, messageId: string) =>
      `/me/conversations/${id}/messages/${messageId}/save`,

    /** What somebody has typed and not sent. One per conversation. */
    draft: (id: string) => `/me/conversations/${id}/draft`,
  },

  /**
   * Announcements.
   *
   * Writing one reaches everybody in an organisation, so it needs
   * `notification.send`. Receiving one needs only to be signed in, and
   * shows only what was addressed to you — hence the two groups.
   */
  broadcasts: {
    list: '/broadcasts',
    create: '/broadcasts',
    show: (id: string) => `/broadcasts/${id}`,
    update: (id: string) => `/broadcasts/${id}`,
    delete: (id: string) => `/broadcasts/${id}`,
    send: (id: string) => `/broadcasts/${id}/send`,
    cancel: (id: string) => `/broadcasts/${id}/cancel`,

    mine: '/me/broadcasts',
    unread: '/me/broadcasts/unread',
    mineShow: (id: string) => `/me/broadcasts/${id}`,
    read: (id: string) => `/me/broadcasts/${id}/read`,
    acknowledge: (id: string) => `/me/broadcasts/${id}/acknowledge`,
  },

  /**
   * Messages across every conversation.
   *
   * Not nested under one, because searching or listing what you have saved
   * is a question about all of them.
   */
  messages: {
    saved: '/me/messages/saved',
    search: '/me/messages/search',
    blocked: '/me/messages/blocked',
    block: (userId: string) => `/me/messages/blocked/${userId}`,
    preferences: '/me/messages/preferences',
  },

  // --- gamification -----------------------------------------------------
  gamification: {
    mine: '/me/gamification',
    history: '/me/gamification/history',
    leaderboard: '/leaderboard',
    levels: '/gamification/levels',
    saveLevel: '/gamification/levels',
    badges: '/badges',
    createBadge: '/badges',
    updateBadge: (id: string) => `/badges/${id}`,
    deleteBadge: (id: string) => `/badges/${id}`,
    awardPoints: (userId: string) => `/users/${userId}/points`,
    awardBadge: (userId: string, badgeId: string) => `/users/${userId}/badges/${badgeId}`,
    revokeBadge: (userId: string, badgeId: string) => `/users/${userId}/badges/${badgeId}`,
  },

  // --- notifications ----------------------------------------------------
  //
  // The inbox routes take no id: the caller comes from the token and goes
  // straight into the WHERE clause, so there is no other inbox to address.
  // Only the last two act on other people, and those carry a permission.
  notifications: {
    list: '/notifications',
    unreadCount: '/notifications/unread-count',
    show: (id: string) => `/notifications/${id}`,
    read: (id: string) => `/notifications/${id}/read`,
    readAll: '/notifications/read-all',
    delete: (id: string) => `/notifications/${id}`,
    preferences: '/notifications/preferences',
    savePreferences: '/notifications/preferences',
    /** Sending to other people; needs `notification.send`. */
    announce: '/notifications/announcements',
    deliveries: '/notifications/deliveries',
  },

  // --- reporting --------------------------------------------------------
  reports: {
    catalogue: '/reports',
    describe: (key: string) => `/reports/${key}/about`,
    run: (key: string) => `/reports/${key}`,
    export: (key: string) => `/reports/${key}/export`,
    learners: '/reports/learners',
    saved: '/reports/saved',
    createSaved: '/reports/saved',
    showSaved: (id: string) => `/reports/saved/${id}`,
    updateSaved: (id: string) => `/reports/saved/${id}`,
    deleteSaved: (id: string) => `/reports/saved/${id}`,
    savedRuns: (id: string) => `/reports/saved/${id}/runs`,
  },

  // --- dashboards -------------------------------------------------------
  dashboards: {
    admin: '/dashboards/admin',
    me: '/dashboards/me',
  },

  // --- organisation settings -------------------------------------------
  /**
   * The organisation's own record — what it is called, how to reach it.
   * Distinct from `settings`, which is preferences with defaults.
   */
  tenant: {
    show: '/tenant',
    update: '/tenant',
  },

  /** What this organisation's own transactional mail says. */
  emailTemplates: {
    list: '/email-templates',
    show: (type: string) => `/email-templates/${type}`,
    save: (type: string) => `/email-templates/${type}`,
    delete: (type: string) => `/email-templates/${type}`,
  },

  settings: {
    show: '/settings',
    update: '/settings',
    catalogue: '/settings/catalogue',
    history: '/settings/history',
    reset: (category: string) => `/settings/${category}/reset`,
    /** Branding and the password rules, before anybody has signed in. */
    public: '/settings/public',
  },
} as const;

/**
 * The kinds of reference data.
 *
 * A closed union, so `lookups/branchs` cannot be typed — which the legacy's
 * 52 hand-written endpoint constants had no way to prevent.
 */
export type LookupKind =
  | 'branch'
  | 'department'
  | 'team'
  | 'level'
  | 'job_title'
  | 'category';

/** One kind's six paths, for a screen that manages a single kind. */
export interface LookupPaths {
  readonly list: string;
  readonly active: string;
  readonly create: string;
  show(id: string): string;
  update(id: string): string;
  status(id: string): string;
}

export function lookupPaths(kind: LookupKind): LookupPaths {
  return {
    list: Endpoints.lookups.list(kind),
    active: Endpoints.lookups.active(kind),
    create: Endpoints.lookups.create(kind),
    show: (id: string) => Endpoints.lookups.show(kind, id),
    update: (id: string) => Endpoints.lookups.update(kind, id),
    status: (id: string) => Endpoints.lookups.status(kind, id),
  };
}
