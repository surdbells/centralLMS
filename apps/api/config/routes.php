<?php

/**
 * Every route in the application, in one readable file.
 *
 * Authorisation is declared here with RequirePermissionMiddleware rather than
 * inside actions, so this file doubles as the access-control audit: what a
 * route needs is on the same screen as its path and method.
 *
 * Convention: everything under /v1 that is not inside the authenticated group
 * is genuinely public. Adding a route outside that group is a deliberate act.
 */

declare(strict_types=1);

use Central\Api\Application\Action\Auth\LoginAction;
use Central\Api\Application\Action\Auth\PasswordResetAction;
use Central\Api\Application\Action\Auth\RegisterAction;
use Central\Api\Application\Action\Competency\CompetencyAction;
use Central\Api\Application\Action\Competency\CompetencyAssessmentAction;
use Central\Api\Application\Action\Competency\CompetencyImportAction;
use Central\Api\Application\Action\Competency\CompetencyTemplateAction;
use Central\Api\Application\Action\Competency\JobProfileAction;
use Central\Api\Application\Action\Auth\SsoAction;
use Central\Api\Application\Action\Settings\ManageEmailTemplatesAction;
use Central\Api\Application\Action\Settings\ManageSsoAction;
use Central\Api\Application\Action\Settings\TenantProfileAction;
use Central\Api\Application\Action\Auth\LogoutAction;
use Central\Api\Application\Action\Auth\RefreshAction;
use Central\Api\Application\Action\Catalog\CourseCatalogueAction;
use Central\Api\Application\Action\Catalog\CourseStatisticsAction;
use Central\Api\Application\Action\Catalog\DeleteCourseAction;
use Central\Api\Application\Action\Catalog\DuplicateCourseAction;
use Central\Api\Application\Action\Catalog\CourseOutlineAction;
use Central\Api\Application\Action\Catalog\CourseResourceAction;
use Central\Api\Application\Action\Catalog\CreateCourseAction;
use Central\Api\Application\Action\Catalog\CreateLessonAction;
use Central\Api\Application\Action\Catalog\CreateModuleAction;
use Central\Api\Application\Action\Catalog\DeleteLessonAction;
use Central\Api\Application\Action\Catalog\DeleteModuleAction;
use Central\Api\Application\Action\Catalog\ListCoursesAction;
use Central\Api\Application\Action\Catalog\ListLessonsAction;
use Central\Api\Application\Action\Catalog\ListModulesAction;
use Central\Api\Application\Action\Catalog\ReorderLessonsAction;
use Central\Api\Application\Action\Catalog\ReorderModulesAction;
use Central\Api\Application\Action\Catalog\SetCourseStatusAction;
use Central\Api\Application\Action\Catalog\SetLessonStatusAction;
use Central\Api\Application\Action\Catalog\SetModuleStatusAction;
use Central\Api\Application\Action\Catalog\ShowCourseAction;
use Central\Api\Application\Action\Catalog\ShowLessonAction;
use Central\Api\Application\Action\Catalog\ShowModuleAction;
use Central\Api\Application\Action\Catalog\UpdateCourseAction;
use Central\Api\Application\Action\Catalog\UpdateLessonAction;
use Central\Api\Application\Action\Catalog\UpdateModuleAction;
use Central\Api\Application\Action\Assessment\AssessmentAssignmentAction;
use Central\Api\Application\Action\Assessment\AssessmentDashboardAction;
use Central\Api\Application\Action\Assessment\AssessmentReportAction;
use Central\Api\Application\Action\Assessment\AssessmentStatsAction;
use Central\Api\Application\Action\Assessment\DeleteAssessmentAction;
use Central\Api\Application\Action\Assessment\DeleteBankAction;
use Central\Api\Application\Action\Assessment\DeleteQuestionAction;
use Central\Api\Application\Action\Assessment\DuplicateAssessmentAction;
use Central\Api\Application\Action\Assessment\DuplicateQuestionAction;
use Central\Api\Application\Action\Assessment\ImportQuestionsAction;
use Central\Api\Application\Action\Assessment\LearnerCatalogueAction;
use Central\Api\Application\Action\Assessment\ListQuestionTagsAction;
use Central\Api\Application\Action\Assessment\MoveQuestionAction;
use Central\Api\Application\Action\Assessment\QuestionImageAction;
use Central\Api\Application\Action\Assessment\QuestionStatisticsAction;
use Central\Api\Application\Action\Assessment\QuestionTemplateAction;
use Central\Api\Application\Action\Assessment\GradeAnswerAction;
use Central\Api\Application\Action\Assessment\GradingQueueAction;
use Central\Api\Application\Action\Assessment\ManageRubricsAction;
use Central\Api\Application\Action\Assessment\ListAssessmentsAction;
use Central\Api\Application\Action\Assessment\ListBanksAction;
use Central\Api\Application\Action\Assessment\ListQuestionsAction;
use Central\Api\Application\Action\Assessment\ManageAssessmentQuestionsAction;
use Central\Api\Application\Action\Assessment\ManageRulesAction;
use Central\Api\Application\Action\Assessment\ManageSectionsAction;
use Central\Api\Application\Action\Assessment\SaveAnswerAction;
use Central\Api\Application\Action\Assessment\SaveAssessmentAction;
use Central\Api\Application\Action\Assessment\SaveBankAction;
use Central\Api\Application\Action\Assessment\SaveQuestionAction;
use Central\Api\Application\Action\Assessment\SetAssessmentStatusAction;
use Central\Api\Application\Action\Assessment\ShowAssessmentAction;
use Central\Api\Application\Action\Assessment\ShowBankAction;
use Central\Api\Application\Action\Assessment\MyAttemptsAction;
use Central\Api\Application\Action\Assessment\StartAttemptAction;
use Central\Api\Application\Action\Assessment\SubmitAttemptAction;
use Central\Api\Application\Action\Proctoring\ConfigureProctoringAction;
use Central\Api\Application\Action\Proctoring\ProctoringSessionAction;
use Central\Api\Application\Action\Proctoring\ReviewProctoringAction;
use Central\Api\Application\Action\Enrollment\AssignEnrollmentsAction;
use Central\Api\Application\Action\Enrollment\CourseEnrollmentStatsAction;
use Central\Api\Application\Action\Enrollment\CoursePlayerExtrasAction;
use Central\Api\Application\Action\Enrollment\EnrollmentRuleAction;
use Central\Api\Application\Action\Enrollment\EnrollmentHistoryAction;
use Central\Api\Application\Action\Enrollment\ListCourseEnrollmentsAction;
use Central\Api\Application\Action\Enrollment\MyCourseProgressAction;
use Central\Api\Application\Action\Enrollment\MyEnrollmentsAction;
use Central\Api\Application\Action\Enrollment\RecordProgressAction;
use Central\Api\Application\Action\Enrollment\SelfEnrollAction;
use Central\Api\Application\Action\Enrollment\UnenrollAction;
use Central\Api\Application\Action\Report\RunReportAction;
use Central\Api\Application\Action\Report\SavedReportAction;
use Central\Api\Application\Action\Gamification\GamificationAction;
use Central\Api\Application\Action\Dashboard\DashboardAction;
use Central\Api\Application\Action\LearningPath\ManagePathsAction;
use Central\Api\Application\Action\LearningPath\PathReviewAction;
use Central\Api\Application\Action\Media\ManageMediaAction;
use Central\Api\Application\Action\Messaging\BroadcastAction;
use Central\Api\Application\Action\Messaging\MessagingAction;
use Central\Api\Application\Action\Note\MyNotesAction;
use Central\Api\Application\Action\Settings\TenantSettingsAction;
use Central\Api\Application\Action\Skill\ManageSkillsAction;
use Central\Api\Application\Action\Media\StreamMediaAction;
use Central\Api\Application\Action\People\ImportPeopleAction;
use Central\Api\Application\Action\People\ManagePeopleAction;
use Central\Api\Application\Action\People\MyProfileAction;
use Central\Api\Application\Action\People\StreamAvatarAction;
use Central\Api\Application\Action\People\ManageRolesAction;
use Central\Api\Application\Action\Notification\AdminNotificationAction;
use Central\Api\Application\Action\Notification\InboxAction;
use Central\Api\Application\Action\Notification\PreferencesAction;
use Central\Api\Application\Action\Certificate\IssueCertificateAction;
use Central\Api\Application\Action\Certificate\ManageCertificatesAction;
use Central\Api\Application\Action\Certificate\ManageTemplatesAction;
use Central\Api\Application\Action\Certificate\VerifyCertificateAction;
use Central\Api\Application\Action\HealthAction;
use Central\Api\Application\Action\Ilt\AttendanceAction;
use Central\Api\Application\Action\Ilt\ListMeetingProvidersAction;
use Central\Api\Application\Action\Ilt\ManageSessionsAction;
use Central\Api\Application\Action\Ilt\MyTrainingsAction;
use Central\Api\Application\Action\Ilt\ManageTrainingsAction;
use Central\Api\Application\Action\Ilt\TrainingEnrolmentAction;
use Central\Api\Application\Action\Ilt\VideoIntegrationAction;
use Central\Api\Application\Action\Lookup\ActiveLookupsAction;
use Central\Api\Application\Action\Lookup\CreateLookupAction;
use Central\Api\Application\Action\Lookup\ListLookupsAction;
use Central\Api\Application\Action\Lookup\SetLookupStatusAction;
use Central\Api\Application\Action\Lookup\ShowLookupAction;
use Central\Api\Application\Action\Lookup\UpdateLookupAction;
use Central\Api\Application\Action\MeAction;
use Central\Api\Application\Action\VersionAction;
use Central\Api\Application\Action\Audit\AuditTrailAction;
use Central\Api\Application\Middleware\AuditMiddleware;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    /*
     * Path ids must look like a UUID before the router will match them.
     *
     * Without this, `/v1/courses/not-a-uuid` reaches PostgreSQL, which raises
     * SQLSTATE[22P02] and surfaces as a 500 with a driver exception in the
     * body. An id that cannot exist is a 404, and no malformed value should
     * reach the database to find that out.
     */
    $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    // --- operational, unauthenticated -------------------------------------
    $app->get('/health', HealthAction::class)->setName('health');
    $app->get('/version', VersionAction::class)->setName('version');

    // Certificate verification. Unauthenticated on purpose: the point of a
    // certificate is that a stranger can check it without an account here,
    // so the identifier itself is the credential. See
    // VerifyCertificateAction for what that costs and how it is contained.
    $app->get('/verify/{hash}', [VerifyCertificateAction::class, 'byHash'])->setName('certificates.verify');
    $app->post('/verify', [VerifyCertificateAction::class, 'byCode'])->setName('certificates.verifyByCode');

    $app->group('/v1', function (RouteCollectorProxy $v1) use ($uuid): void {
        // --- public -------------------------------------------------------
        //
        // Branding and the password rules, for a page nobody has signed in to
        // yet. Unauthenticated by necessity — the sign-in form needs a logo
        // and a colour before there is a token — and therefore deliberately
        // narrow: it returns those two things and nothing else.
        $v1->get('/settings/public', [TenantSettingsAction::class, 'publicSettings'])
            ->setName('settings.public');

        // Certificate verification, also under /v1 so the signed-in app can
        // reach it: its client prefixes every path with /v1, so a bare
        // /verify came out as /v1/verify and 404ed. The root-level routes
        // above stay for the public link a QR code carries.
        $v1->get('/verify/{hash}', [VerifyCertificateAction::class, 'byHash'])
            ->setName('certificates.verify.v1');
        $v1->post('/verify', [VerifyCertificateAction::class, 'byCode'])
            ->setName('certificates.verifyByCode.v1');

        $v1->group('/auth', function (RouteCollectorProxy $auth): void {
            $auth->post('/login', LoginAction::class)->setName('auth.login');

            // Public by necessity: the refresh token is the credential, and
            // this is called precisely when the access token has expired.
            $auth->post('/refresh', RefreshAction::class)->setName('auth.refresh');

            // Public so signing out always succeeds. The `allDevices` variant
            // asks for the caller inside the action, which requires a valid
            // access token.
            $auth->post('/logout', LogoutAction::class)->setName('auth.logout');

            // Setting a password without being signed in. Public by
            // necessity — somebody who has forgotten theirs cannot
            // authenticate — so each of the three is written to give
            // nothing away about who holds an account here.
            $auth->group('/password', function (RouteCollectorProxy $pw): void {
                $pw->post('/forgot', [PasswordResetAction::class, 'forgot'])
                    ->setName('auth.password.forgot');
                $pw->get('/token', [PasswordResetAction::class, 'check'])
                    ->setName('auth.password.check');
                $pw->post('/reset', [PasswordResetAction::class, 'reset'])
                    ->setName('auth.password.reset');
            });

            // Signing in from Google or Microsoft Entra. Public, because
            // somebody signing in has nothing to authenticate with yet.
            $auth->group('/sso', function (RouteCollectorProxy $sso): void {
                $sso->post('/start', [SsoAction::class, 'start'])->setName('auth.sso.start');
                $sso->post('/callback', [SsoAction::class, 'callback'])
                    ->setName('auth.sso.callback');
            });
        });

        // Registering yourself, in a named organisation.
        //
        // The organisation is in the path because an account has to belong
        // to one: `register.php` asked for none and inserted rows with no
        // `tenant_id`, so a self-registered account belonged to nobody.
        // Public, and closed unless the organisation has opened it.
        $v1->group('/tenants/{tenantId:' . $uuid . '}', function (RouteCollectorProxy $t): void {
            $t->get('/registration', [RegisterAction::class, 'show'])
                ->setName('registration.show');
            $t->post('/register', [RegisterAction::class, 'register'])
                ->setName('registration.register');
            $t->get('/sso', [SsoAction::class, 'providers'])->setName('registration.sso');
        });

        // The bytes of one file, for a browser that cannot send a header.
        //
        // Outside the authenticated group by necessity: an `<img src>`, a
        // seeking `<video src>` and a clicked download link all arrive with
        // no Authorization header, and every one of them was coming back
        // 401. The credential is a ticket in the query string, minted by
        // `POST /media/{id}/ticket`, which is authenticated and checks the
        // permission. The ticket names one file and lasts minutes.
        $v1->get('/media/{id:' . $uuid . '}/file', StreamMediaAction::class)
            ->setName('media.file');

        // --- authenticated ------------------------------------------------
        $v1->group('', function (RouteCollectorProxy $secure) use ($uuid): void {
            $secure->get('/me', MeAction::class)->setName('me');

            // The organisation's own arrangements with Google and Entra.
            // No route here returns a client secret; there is nowhere to
            // read one back from.
            // The organisation's own record — what it is called, how to
            // reach it, what its mark looks like. Distinct from /settings,
            // which is preferences with defaults.
            $secure->get('/tenant', [TenantProfileAction::class, 'show'])->setName('tenant.show');
            $secure->put('/tenant', [TenantProfileAction::class, 'update'])
                ->setName('tenant.update');

            // What this organisation's own mail says.
            $secure->group('/email-templates', function (RouteCollectorProxy $t): void {
                $t->get('', [ManageEmailTemplatesAction::class, 'list'])
                    ->setName('email-templates.list');
                $t->get('/{type:[a-z_]+}', [ManageEmailTemplatesAction::class, 'show'])
                    ->setName('email-templates.show');
                $t->put('/{type:[a-z_]+}', [ManageEmailTemplatesAction::class, 'save'])
                    ->setName('email-templates.save');
                $t->delete('/{type:[a-z_]+}', [ManageEmailTemplatesAction::class, 'delete'])
                    ->setName('email-templates.delete');
            });

            $secure->get('/sso', [ManageSsoAction::class, 'list'])->setName('sso.list');
            $secure->put('/sso/{provider:[a-z]+}', [ManageSsoAction::class, 'save'])
                ->setName('sso.save');
            $secure->delete('/sso/{provider:[a-z]+}', [ManageSsoAction::class, 'delete'])
                ->setName('sso.delete');

            // What somebody may change about themselves. No permission is
            // checked and no id is read from the request — every one of these
            // acts on whoever the token says is calling. `update-profile` took
            // a `user_id` from the body and checked nothing.
            $secure->group('/me', function (RouteCollectorProxy $m) use ($uuid): void {
                $m->get('/profile', [MyProfileAction::class, 'show'])->setName('me.profile');
                $m->put('/profile', [MyProfileAction::class, 'update'])->setName('me.profile.update');

                $m->get('/consents', [MyProfileAction::class, 'consents'])->setName('me.consents');
                $m->put('/consents', [MyProfileAction::class, 'saveConsents'])->setName('me.consents.save');

                $m->post('/avatar', [MyProfileAction::class, 'uploadAvatar'])->setName('me.avatar.set');
                $m->delete('/avatar', [MyProfileAction::class, 'removeAvatar'])
                    ->setName('me.avatar.remove');

                $m->get('/sessions', [MyProfileAction::class, 'sessions'])->setName('me.sessions');
                $m->delete('/sessions/{id:' . $uuid . '}', [MyProfileAction::class, 'revokeSession'])
                    ->setName('me.sessions.revoke');
            });

            // The learner's own view. Scoped to the caller by construction —
            // there is no user id in any of these paths, so there is nothing
            // to tamper with.
            // The assessments this person has sat. Distinct from a marker's
            // queue at `/assessments/{id}/attempts` — see MyAttemptsAction.
            $secure->get('/me/attempts', MyAttemptsAction::class)->setName('me.attempts');
            $secure->get('/me/assessments', LearnerCatalogueAction::class)->setName('me.assessments');

            // What the caller is booked on. Distinct from a programme's
            // roster, which is other people's names — see MyTrainingsAction.
            $secure->get('/me/trainings', MyTrainingsAction::class)->setName('me.trainings');

            // Checking in by scanning the code at a venue.
            //
            // Outside `/trainings/{id}/sessions/{sessionId}` on purpose: a
            // scanner has a code and nothing else, so the code is what names
            // the session. See AttendanceAction::scan().
            $secure->post('/me/trainings/check-in', [AttendanceAction::class, 'scan'])
                ->setName('me.trainings.checkIn');

            $secure->group('/me/enrollments', function (RouteCollectorProxy $m) use ($uuid): void {
                $m->get('', MyEnrollmentsAction::class)->setName('me.enrollments');
                $m->get('/{courseId:' . $uuid . '}', MyCourseProgressAction::class)
                    ->setName('me.enrollments.show');
                $m->put(
                    '/{courseId:' . $uuid . '}/lessons/{lessonId:' . $uuid . '}/progress',
                    RecordProgressAction::class,
                )->setName('me.progress.record');

                // The player's "Learners" and "Discuss" tabs. Both are gated
                // on being enrolled — you see the classmates and join the
                // discussion of a course you are on, and no other.
                $m->get('/{courseId:' . $uuid . '}/classmates', [CoursePlayerExtrasAction::class, 'classmates'])
                    ->setName('me.enrollments.classmates');
                $m->get('/{courseId:' . $uuid . '}/discussion', [CoursePlayerExtrasAction::class, 'discussion'])
                    ->setName('me.enrollments.discussion');
                $m->post('/{courseId:' . $uuid . '}/discussion', [CoursePlayerExtrasAction::class, 'post'])
                    ->setName('me.enrollments.discussion.post');
                $m->delete(
                    '/{courseId:' . $uuid . '}/discussion/{postId:' . $uuid . '}',
                    [CoursePlayerExtrasAction::class, 'deletePost'],
                )->setName('me.enrollments.discussion.delete');
            });

            // The course catalogue.
            $secure->group('/courses', function (RouteCollectorProxy $c) use ($uuid): void {
                // The learner-facing catalogue is a distinct route, not a
                // filter on the admin list — see CourseCatalogueAction. It is
                // declared first so `catalogue` is not swallowed by `/{id}`.
                $c->get('/catalogue', CourseCatalogueAction::class)->setName('courses.catalogue');
                $c->get('/statistics', CourseStatisticsAction::class)->setName('courses.statistics');
                $c->get('', ListCoursesAction::class)->setName('courses.list');
                $c->post('', CreateCourseAction::class)->setName('courses.create');
                $c->get('/{id:' . $uuid . '}', ShowCourseAction::class)->setName('courses.show');
                $c->put('/{id:' . $uuid . '}', UpdateCourseAction::class)->setName('courses.update');
                $c->patch('/{id:' . $uuid . '}/status', SetCourseStatusAction::class)->setName('courses.status');

                // Soft, and refused while anybody is enrolled — see
                // DeleteCourseAction. Archiving is what retires a course that
                // people are on.
                $c->delete('/{id:' . $uuid . '}', DeleteCourseAction::class)->setName('courses.delete');

                // The course, its modules and its lessons, as a draft. Not its
                // enrolments, its rating or the skills it teaches.
                $c->post('/{id:' . $uuid . '}/duplicate', DuplicateCourseAction::class)
                    ->setName('courses.duplicate');

                // Structure. Modules and lessons are addressed through their
                // parent rather than by bare id, so an id from another course
                // cannot be reached even inside the right tenant.
                $c->group('/{courseId:' . $uuid . '}', function (RouteCollectorProxy $s) use ($uuid): void {
                    // Modules with their lessons, in two queries. The whole
                    // point of the endpoint — see CourseOutlineAction.
                    $s->get('/outline', CourseOutlineAction::class)->setName('courses.outline');

                    // A learner putting themselves on the course. Distinct
                    // from /enrollments below, which is an administrator
                    // putting other people on it, and needs only
                    // `enrollment.self`.
                    $s->post('/enroll', SelfEnrollAction::class)->setName('courses.selfEnroll');

                    $s->group('/enrollments', function (RouteCollectorProxy $e) use ($uuid): void {
                        // Before `/{userId}`, or `stats` is read as a user id.
                        $e->get('/stats', CourseEnrollmentStatsAction::class)->setName('enrollments.stats');

                        $e->get('', ListCourseEnrollmentsAction::class)->setName('enrollments.list');
                        $e->post('', AssignEnrollmentsAction::class)->setName('enrollments.assign');
                        $e->delete('', UnenrollAction::class)->setName('enrollments.unenroll');

                        $e->get('/{userId:' . $uuid . '}/history', EnrollmentHistoryAction::class)
                            ->setName('enrollments.history');
                    });

                    // Downloadable file resources — reference material of any
                    // type, added by the course's editor and offered in the
                    // player's Resources tab.
                    $s->group('/resources', function (RouteCollectorProxy $r) use ($uuid): void {
                        $r->get('', [CourseResourceAction::class, 'list'])->setName('courses.resources.list');
                        $r->post('', [CourseResourceAction::class, 'upload'])->setName('courses.resources.add');
                        $r->get('/{id:' . $uuid . '}/file', [CourseResourceAction::class, 'download'])
                            ->setName('courses.resources.download');
                        $r->delete('/{id:' . $uuid . '}', [CourseResourceAction::class, 'delete'])
                            ->setName('courses.resources.delete');
                    });

                    $s->group('/modules', function (RouteCollectorProxy $m) use ($uuid): void {
                        // Before `/{id}`, or `order` is read as a module id.
                        $m->put('/order', ReorderModulesAction::class)->setName('modules.reorder');

                        $m->get('', ListModulesAction::class)->setName('modules.list');
                        $m->post('', CreateModuleAction::class)->setName('modules.create');
                        $m->get('/{id:' . $uuid . '}', ShowModuleAction::class)->setName('modules.show');
                        $m->put('/{id:' . $uuid . '}', UpdateModuleAction::class)->setName('modules.update');
                        $m->patch('/{id:' . $uuid . '}/status', SetModuleStatusAction::class)
                            ->setName('modules.status');
                        $m->delete('/{id:' . $uuid . '}', DeleteModuleAction::class)->setName('modules.delete');

                        $m->group('/{moduleId:' . $uuid . '}/lessons', function (
                            RouteCollectorProxy $l,
                        ) use ($uuid): void {
                            $l->put('/order', ReorderLessonsAction::class)->setName('lessons.reorder');

                            $l->get('', ListLessonsAction::class)->setName('lessons.list');
                            $l->post('', CreateLessonAction::class)->setName('lessons.create');
                            $l->get('/{id:' . $uuid . '}', ShowLessonAction::class)->setName('lessons.show');
                            $l->put('/{id:' . $uuid . '}', UpdateLessonAction::class)->setName('lessons.update');
                            $l->patch('/{id:' . $uuid . '}/status', SetLessonStatusAction::class)
                                ->setName('lessons.status');
                            $l->delete('/{id:' . $uuid . '}', DeleteLessonAction::class)
                                ->setName('lessons.delete');
                        });
                    });
                });
            });

            // Reference data. Six routes replace 36 legacy PHP endpoints —
            // {kind} is branch | department | team | level | job_title |
            // category, singular or plural.
            $secure->group('/lookups/{kind}', function (RouteCollectorProxy $l) use ($uuid): void {
                $l->get('', ListLookupsAction::class)->setName('lookups.list');
                $l->get('/active', ActiveLookupsAction::class)->setName('lookups.active');
                $l->post('', CreateLookupAction::class)->setName('lookups.create');
                $l->get('/{id:' . $uuid . '}', ShowLookupAction::class)->setName('lookups.show');
                $l->put('/{id:' . $uuid . '}', UpdateLookupAction::class)->setName('lookups.update');
                $l->patch('/{id:' . $uuid . '}/status', SetLookupStatusAction::class)->setName('lookups.status');
            });

            // Reusable questions. There is no learner-facing route onto a
            // bank at all: a learner meets a question only through an attempt,
            // where the answer key is stripped.
            $secure->group('/certificate-templates', function (RouteCollectorProxy $t) use ($uuid): void {
                $t->get('', [ManageTemplatesAction::class, 'list'])->setName('certificateTemplates.list');
                $t->post('', [ManageTemplatesAction::class, 'save'])->setName('certificateTemplates.create');
                $t->get('/{id:' . $uuid . '}', [ManageTemplatesAction::class, 'show'])
                    ->setName('certificateTemplates.show');
                $t->put('/{id:' . $uuid . '}', [ManageTemplatesAction::class, 'save'])
                    ->setName('certificateTemplates.update');
                $t->delete('/{id:' . $uuid . '}', [ManageTemplatesAction::class, 'delete'])
                    ->setName('certificateTemplates.delete');
                $t->post('/{id:' . $uuid . '}/preview', [ManageTemplatesAction::class, 'preview'])
                    ->setName('certificateTemplates.preview');
            });

            $secure->group('/certificates', function (RouteCollectorProxy $c) use ($uuid): void {
                $c->get('', [ManageCertificatesAction::class, 'list'])->setName('certificates.list');
                // Issuance asks the owning module whether it was earned;
                // see IssueCertificateAction.
                $c->post('', IssueCertificateAction::class)->setName('certificates.issue');
                $c->get('/{id:' . $uuid . '}', [ManageCertificatesAction::class, 'show'])
                    ->setName('certificates.show');
                $c->get('/{id:' . $uuid . '}/render', [ManageCertificatesAction::class, 'render'])
                    ->setName('certificates.render');
                $c->get('/{id:' . $uuid . '}/history', [ManageCertificatesAction::class, 'history'])
                    ->setName('certificates.history');
                $c->post('/{id:' . $uuid . '}/revoke', [ManageCertificatesAction::class, 'revoke'])
                    ->setName('certificates.revoke');
                $c->post('/{id:' . $uuid . '}/reinstate', [ManageCertificatesAction::class, 'reinstate'])
                    ->setName('certificates.reinstate');
            });

            $secure->get('/me/certificates', [ManageCertificatesAction::class, 'mine'])
                ->setName('me.certificates');

            // --- gamification ---------------------------------------------
            //
            // Reading your own standing needs no permission; granting
            // anything needs `user.manage`, because points and badges are
            // what the leaderboard ranks people by.
            $secure->get('/me/gamification', [GamificationAction::class, 'mine'])
                ->setName('gamification.mine');
            $secure->get('/me/gamification/history', [GamificationAction::class, 'history'])
                ->setName('gamification.history');
            $secure->get('/leaderboard', [GamificationAction::class, 'leaderboard'])
                ->setName('gamification.leaderboard');

            $secure->group('/badges', function (RouteCollectorProxy $b) use ($uuid): void {
                $b->get('', [GamificationAction::class, 'badges'])->setName('badges.list');
                $b->post('', [GamificationAction::class, 'saveBadge'])->setName('badges.create');
                $b->put('/{id:' . $uuid . '}', [GamificationAction::class, 'saveBadge'])
                    ->setName('badges.update');
                $b->delete('/{id:' . $uuid . '}', [GamificationAction::class, 'deleteBadge'])
                    ->setName('badges.delete');
            });

            $secure->group('/gamification/levels', function (RouteCollectorProxy $l): void {
                $l->get('', [GamificationAction::class, 'levels'])->setName('gamification.levels');
                // The whole ladder at once: thresholds have to be consistent
                // with each other, and editing them one at a time goes
                // through states where two rungs need the same points.
                $l->put('', [GamificationAction::class, 'saveLevels'])->setName('gamification.levels.save');
            });

            $secure->post('/users/{userId:' . $uuid . '}/points', [GamificationAction::class, 'awardPoints'])
                ->setName('gamification.awardPoints');
            $secure->post(
                '/users/{userId:' . $uuid . '}/badges/{id:' . $uuid . '}',
                [GamificationAction::class, 'awardBadge'],
            )->setName('gamification.awardBadge');
            $secure->delete(
                '/users/{userId:' . $uuid . '}/badges/{id:' . $uuid . '}',
                [GamificationAction::class, 'revokeBadge'],
            )->setName('gamification.revokeBadge');
            // --- messaging ------------------------------------------------
            //
            // Every route resolves the conversation through the caller's own
            // membership, and one that is not theirs answers 404 rather than
            // 403 — saying a conversation exists but is not yours says who is
            // talking. `unread` is declared before `/{id}`.
            // Announcements. Writing one reaches everybody in an organisation,
            // so it needs `notification.send`; receiving one needs only to be
            // signed in, and shows only what was addressed to you.
            $secure->group('/broadcasts', function (RouteCollectorProxy $b) use ($uuid): void {
                $b->get('', [BroadcastAction::class, 'list'])->setName('broadcasts.list');
                $b->post('', [BroadcastAction::class, 'create'])->setName('broadcasts.create');
                $b->get('/{id:' . $uuid . '}', [BroadcastAction::class, 'show'])
                    ->setName('broadcasts.show');
                $b->put('/{id:' . $uuid . '}', [BroadcastAction::class, 'update'])
                    ->setName('broadcasts.update');
                $b->delete('/{id:' . $uuid . '}', [BroadcastAction::class, 'delete'])
                    ->setName('broadcasts.delete');
                $b->post('/{id:' . $uuid . '}/send', [BroadcastAction::class, 'send'])
                    ->setName('broadcasts.send');
                $b->post('/{id:' . $uuid . '}/cancel', [BroadcastAction::class, 'cancel'])
                    ->setName('broadcasts.cancel');
            });

            $secure->group('/me/broadcasts', function (RouteCollectorProxy $b) use ($uuid): void {
                $b->get('', [BroadcastAction::class, 'mine'])->setName('me.broadcasts');
                $b->get('/unread', [BroadcastAction::class, 'unread'])->setName('me.broadcasts.unread');
                $b->get('/{id:' . $uuid . '}', [BroadcastAction::class, 'mineShow'])
                    ->setName('me.broadcasts.show');
                $b->post('/{id:' . $uuid . '}/read', [BroadcastAction::class, 'markRead'])
                    ->setName('me.broadcasts.read');
                $b->post('/{id:' . $uuid . '}/acknowledge', [BroadcastAction::class, 'acknowledge'])
                    ->setName('me.broadcasts.acknowledge');
            });

            // Messages across every conversation this person is in. Not nested
            // under one, because searching or listing what you have saved is a
            // question about all of them.
            $secure->group('/me/messages', function (RouteCollectorProxy $m) use ($uuid): void {
                $m->get('/saved', [MessagingAction::class, 'saved'])->setName('me.messages.saved');
                $m->get('/search', [MessagingAction::class, 'search'])->setName('me.messages.search');
                $m->get('/blocked', [MessagingAction::class, 'blocked'])->setName('me.messages.blocked');
                $m->put('/blocked/{userId:' . $uuid . '}', [MessagingAction::class, 'block'])
                    ->setName('me.messages.block');
                $m->delete('/blocked/{userId:' . $uuid . '}', [MessagingAction::class, 'unblock'])
                    ->setName('me.messages.unblock');
                $m->get('/preferences', [MessagingAction::class, 'preferences'])
                    ->setName('me.messages.preferences');
                $m->put('/preferences', [MessagingAction::class, 'savePreferences'])
                    ->setName('me.messages.preferences.save');
            });

            $secure->group('/me/conversations', function (RouteCollectorProxy $m) use ($uuid): void {
                $m->get('', [MessagingAction::class, 'inbox'])->setName('me.conversations');
                $m->post('', [MessagingAction::class, 'start'])->setName('me.conversations.start');

                $m->get('/unread', [MessagingAction::class, 'unread'])->setName('me.conversations.unread');

                $m->get('/{id:' . $uuid . '}', [MessagingAction::class, 'show'])
                    ->setName('me.conversations.show');
                $m->patch('/{id:' . $uuid . '}', [MessagingAction::class, 'update'])
                    ->setName('me.conversations.update');
                $m->post('/{id:' . $uuid . '}/read', [MessagingAction::class, 'markRead'])
                    ->setName('me.conversations.read');

                $m->post('/{id:' . $uuid . '}/participants', [MessagingAction::class, 'addParticipants'])
                    ->setName('me.conversations.participants.add');
                $m->delete(
                    '/{id:' . $uuid . '}/participants/{userId:' . $uuid . '}',
                    [MessagingAction::class, 'removeParticipant'],
                )->setName('me.conversations.participants.remove');

                $m->get('/{id:' . $uuid . '}/messages', [MessagingAction::class, 'messages'])
                    ->setName('me.conversations.messages');
                $m->post('/{id:' . $uuid . '}/messages', [MessagingAction::class, 'send'])
                    ->setName('me.conversations.messages.send');
                $m->put(
                    '/{id:' . $uuid . '}/messages/{messageId:' . $uuid . '}',
                    [MessagingAction::class, 'edit'],
                )->setName('me.conversations.messages.edit');
                $m->delete(
                    '/{id:' . $uuid . '}/messages/{messageId:' . $uuid . '}',
                    [MessagingAction::class, 'delete'],
                )->setName('me.conversations.messages.delete');

                // Reacting is a toggle: the same emoji twice takes it back.
                $m->post(
                    '/{id:' . $uuid . '}/messages/{messageId:' . $uuid . '}/reactions',
                    [MessagingAction::class, 'react'],
                )->setName('me.conversations.messages.react');

                // Pinning is the conversation's; saving is the reader's own.
                $m->get('/{id:' . $uuid . '}/pinned', [MessagingAction::class, 'pinned'])
                    ->setName('me.conversations.pinned');
                $m->put(
                    '/{id:' . $uuid . '}/messages/{messageId:' . $uuid . '}/pin',
                    [MessagingAction::class, 'pin'],
                )->setName('me.conversations.messages.pin');
                $m->put(
                    '/{id:' . $uuid . '}/messages/{messageId:' . $uuid . '}/save',
                    [MessagingAction::class, 'save'],
                )->setName('me.conversations.messages.save');
                $m->delete(
                    '/{id:' . $uuid . '}/messages/{messageId:' . $uuid . '}/save',
                    [MessagingAction::class, 'unsave'],
                )->setName('me.conversations.messages.unsave');

                // What somebody has typed and not sent. One per conversation.
                $m->get('/{id:' . $uuid . '}/draft', [MessagingAction::class, 'draft'])
                    ->setName('me.conversations.draft');
                $m->put('/{id:' . $uuid . '}/draft', [MessagingAction::class, 'saveDraft'])
                    ->setName('me.conversations.draft.save');
                $m->delete('/{id:' . $uuid . '}/draft', [MessagingAction::class, 'deleteDraft'])
                    ->setName('me.conversations.draft.delete');
            });

            // --- learning paths -------------------------------------------
            //
            // Nothing under here writes progress: a learner's position is
            // derived from the course enrolments, assessment attempts and
            // attendance records that already exist. See PathProgress.
            $secure->group('/paths', function (RouteCollectorProxy $p) use ($uuid): void {
                $p->get('', [ManagePathsAction::class, 'list'])->setName('paths.list');
                $p->post('', [ManagePathsAction::class, 'create'])->setName('paths.create');

                $p->get('/statistics', [ManagePathsAction::class, 'statistics'])
                    ->setName('paths.statistics');
                $p->get('/catalogue', [ManagePathsAction::class, 'catalogue'])
                    ->setName('paths.catalogue');

                $p->get('/{id:' . $uuid . '}', [ManagePathsAction::class, 'show'])->setName('paths.show');
                $p->put('/{id:' . $uuid . '}', [ManagePathsAction::class, 'update'])->setName('paths.update');
                $p->patch('/{id:' . $uuid . '}/status', [ManagePathsAction::class, 'setStatus'])
                    ->setName('paths.status');
                $p->delete('/{id:' . $uuid . '}', [ManagePathsAction::class, 'delete'])->setName('paths.delete');

                $p->get('/{id:' . $uuid . '}/sections', [ManagePathsAction::class, 'sections'])
                    ->setName('paths.sections');
                $p->post('/{id:' . $uuid . '}/sections', [ManagePathsAction::class, 'saveSection'])
                    ->setName('paths.sections.create');
                $p->put(
                    '/{id:' . $uuid . '}/sections/{sectionId:' . $uuid . '}',
                    [ManagePathsAction::class, 'saveSection'],
                )->setName('paths.sections.update');
                $p->delete(
                    '/{id:' . $uuid . '}/sections/{sectionId:' . $uuid . '}',
                    [ManagePathsAction::class, 'deleteSection'],
                )->setName('paths.sections.delete');

                // `order` before `{itemId}`, or it is read as an item id.
                $p->put('/{id:' . $uuid . '}/items/order', [ManagePathsAction::class, 'reorder'])
                    ->setName('paths.items.reorder');
                $p->get('/{id:' . $uuid . '}/items', [ManagePathsAction::class, 'items'])
                    ->setName('paths.items');
                $p->post('/{id:' . $uuid . '}/items', [ManagePathsAction::class, 'saveItem'])
                    ->setName('paths.items.create');
                $p->put(
                    '/{id:' . $uuid . '}/items/{itemId:' . $uuid . '}',
                    [ManagePathsAction::class, 'saveItem'],
                )->setName('paths.items.update');
                $p->delete(
                    '/{id:' . $uuid . '}/items/{itemId:' . $uuid . '}',
                    [ManagePathsAction::class, 'deleteItem'],
                )->setName('paths.items.delete');

                $p->get('/{id:' . $uuid . '}/enrollments', [ManagePathsAction::class, 'enrolments'])
                    ->setName('paths.enrollments');
                $p->post('/{id:' . $uuid . '}/enrollments', [ManagePathsAction::class, 'enrol'])
                    ->setName('paths.enrollments.create');
                $p->delete(
                    '/{id:' . $uuid . '}/enrollments/{userId:' . $uuid . '}',
                    [ManagePathsAction::class, 'withdraw'],
                )->setName('paths.enrollments.withdraw');

                // What learners thought of it. Reading is part of the path's
                // page; writing needs an enrolment, which the action checks.
                $p->get('/{id:' . $uuid . '}/reviews', [PathReviewAction::class, 'list'])
                    ->setName('paths.reviews');
                $p->get('/{id:' . $uuid . '}/reviews/summary', [PathReviewAction::class, 'summary'])
                    ->setName('paths.reviews.summary');
                $p->post('/{id:' . $uuid . '}/reviews', [PathReviewAction::class, 'create'])
                    ->setName('paths.reviews.create');

                // Standing enrolment rules. Not the same as the enrolment
                // endpoints above: those put people on the path today, these
                // keep doing it as people join the group named.
                $p->get('/{id:' . $uuid . '}/assignments', [EnrollmentRuleAction::class, 'listForPath'])
                    ->setName('paths.assignments');
                $p->post('/{id:' . $uuid . '}/assignments', [EnrollmentRuleAction::class, 'createForPath'])
                    ->setName('paths.assignments.create');
            });

            // Everything addressed by rule rather than by what it enrols
            // people onto. One set of paths for both subjects, because a rule
            // behaves identically whichever it is.
            $secure->group('/enrollment-rules', function (RouteCollectorProxy $a) use ($uuid): void {
                $a->put('/{ruleId:' . $uuid . '}', [EnrollmentRuleAction::class, 'update'])
                    ->setName('enrollment-rules.update');
                $a->delete('/{ruleId:' . $uuid . '}', [EnrollmentRuleAction::class, 'delete'])
                    ->setName('enrollment-rules.delete');

                $a->post('/{ruleId:' . $uuid . '}/pause', [EnrollmentRuleAction::class, 'pause'])
                    ->setName('enrollment-rules.pause');
                $a->post('/{ruleId:' . $uuid . '}/resume', [EnrollmentRuleAction::class, 'resume'])
                    ->setName('enrollment-rules.resume');
                $a->post('/{ruleId:' . $uuid . '}/cancel', [EnrollmentRuleAction::class, 'cancel'])
                    ->setName('enrollment-rules.cancel');
                $a->post('/{ruleId:' . $uuid . '}/apply', [EnrollmentRuleAction::class, 'apply'])
                    ->setName('enrollment-rules.apply');

                $a->post('/{ruleId:' . $uuid . '}/reminders', [EnrollmentRuleAction::class, 'remind'])
                    ->setName('enrollment-rules.reminders');

                $a->get('/{ruleId:' . $uuid . '}/preview', [EnrollmentRuleAction::class, 'preview'])
                    ->setName('enrollment-rules.preview');
                $a->get('/{ruleId:' . $uuid . '}/statistics', [EnrollmentRuleAction::class, 'statistics'])
                    ->setName('enrollment-rules.statistics');
            });

            // Moderation, and everything addressed by review rather than by
            // path. `/pending` is declared first so it is never read as an id.
            $secure->group('/path-reviews', function (RouteCollectorProxy $r) use ($uuid): void {
                $r->get('/pending', [PathReviewAction::class, 'pending'])
                    ->setName('path-reviews.pending');

                $r->delete('/{reviewId:' . $uuid . '}', [PathReviewAction::class, 'delete'])
                    ->setName('path-reviews.delete');
                $r->post('/{reviewId:' . $uuid . '}/approve', [PathReviewAction::class, 'approve'])
                    ->setName('path-reviews.approve');
                $r->post('/{reviewId:' . $uuid . '}/reject', [PathReviewAction::class, 'reject'])
                    ->setName('path-reviews.reject');
                $r->post('/{reviewId:' . $uuid . '}/response', [PathReviewAction::class, 'respond'])
                    ->setName('path-reviews.respond');
                $r->post('/{reviewId:' . $uuid . '}/helpful', [PathReviewAction::class, 'helpful'])
                    ->setName('path-reviews.helpful');
                $r->post('/{reviewId:' . $uuid . '}/report', [PathReviewAction::class, 'report'])
                    ->setName('path-reviews.report');
            });

            // The learner's own. No user id in any of these paths.
            $secure->group('/me/paths', function (RouteCollectorProxy $p) use ($uuid): void {
                $p->get('', [ManagePathsAction::class, 'mine'])->setName('me.paths');
                $p->get('/{id:' . $uuid . '}', [ManagePathsAction::class, 'mineShow'])
                    ->setName('me.paths.show');
                $p->post('/{id:' . $uuid . '}/enroll', [ManagePathsAction::class, 'selfEnrol'])
                    ->setName('me.paths.enroll');
                $p->post(
                    '/{id:' . $uuid . '}/items/{itemId:' . $uuid . '}/complete',
                    [ManagePathsAction::class, 'completeItem'],
                )->setName('me.paths.items.complete');

                // Their own review, whatever state it is in. A review waiting
                // to be checked is on no page, and its author is the one
                // person entitled to see it there.
                $p->get('/{id:' . $uuid . '}/review', [PathReviewAction::class, 'mine'])
                    ->setName('me.paths.review');
            });

            // --- skills ---------------------------------------------------
            //
            // `statistics` and `categories` are declared before `/{id}` so
            // neither is read as a skill id.
            $secure->group('/skills', function (RouteCollectorProxy $s) use ($uuid): void {
                $s->get('', [ManageSkillsAction::class, 'list'])->setName('skills.list');
                $s->post('', [ManageSkillsAction::class, 'create'])->setName('skills.create');

                $s->get('/statistics', [ManageSkillsAction::class, 'statistics'])
                    ->setName('skills.statistics');

                $s->get('/categories', [ManageSkillsAction::class, 'categories'])
                    ->setName('skills.categories');
                $s->post('/categories', [ManageSkillsAction::class, 'createCategory'])
                    ->setName('skills.categories.create');
                $s->put('/categories/{id:' . $uuid . '}', [ManageSkillsAction::class, 'updateCategory'])
                    ->setName('skills.categories.update');
                $s->delete('/categories/{id:' . $uuid . '}', [ManageSkillsAction::class, 'deleteCategory'])
                    ->setName('skills.categories.delete');

                $s->get('/{id:' . $uuid . '}', [ManageSkillsAction::class, 'show'])->setName('skills.show');
                $s->put('/{id:' . $uuid . '}', [ManageSkillsAction::class, 'update'])->setName('skills.update');
                $s->delete('/{id:' . $uuid . '}', [ManageSkillsAction::class, 'delete'])->setName('skills.delete');

                // Everything that teaches it, of any kind. Still `/courses`
                // in the path, because that is what the screen asks for.
                $s->get('/{id:' . $uuid . '}/courses', [ManageSkillsAction::class, 'coursesTeaching'])
                    ->setName('skills.courses');
                $s->get('/{id:' . $uuid . '}/learners', [ManageSkillsAction::class, 'learners'])
                    ->setName('skills.learners');
            });

            // --- competency assurance -------------------------------------
            //
            // The dictionary, the job profiles built from it, and the
            // assessments measuring people against those profiles.
            $secure->group('/competencies', function (RouteCollectorProxy $c) use ($uuid): void {
                $c->get('', [CompetencyAction::class, 'list'])->setName('competencies.list');
                $c->post('', [CompetencyAction::class, 'create'])->setName('competencies.create');
                $c->get('/functions', [CompetencyAction::class, 'functions'])
                    ->setName('competencies.functions');
                // Importing the completed JCP workbooks straight from the browser.
                $c->post('/import', [CompetencyImportAction::class, 'import'])
                    ->setName('competencies.import');
                // A blank workbook laid out the way the importer reads.
                $c->get('/import/template', [CompetencyTemplateAction::class, 'import'])
                    ->setName('competencies.import.template');
                $c->get('/{id:' . $uuid . '}', [CompetencyAction::class, 'show'])->setName('competencies.show');
                $c->put('/{id:' . $uuid . '}', [CompetencyAction::class, 'update'])->setName('competencies.update');
                $c->delete('/{id:' . $uuid . '}', [CompetencyAction::class, 'delete'])
                    ->setName('competencies.delete');
            });

            $secure->group('/job-profiles', function (RouteCollectorProxy $p) use ($uuid): void {
                $p->get('', [JobProfileAction::class, 'list'])->setName('job-profiles.list');
                $p->post('', [JobProfileAction::class, 'create'])->setName('job-profiles.create');
                $p->get('/{id:' . $uuid . '}', [JobProfileAction::class, 'show'])->setName('job-profiles.show');
                $p->put('/{id:' . $uuid . '}', [JobProfileAction::class, 'update'])->setName('job-profiles.update');
                $p->delete('/{id:' . $uuid . '}', [JobProfileAction::class, 'delete'])
                    ->setName('job-profiles.delete');
            });

            $secure->group('/competency-assessments', function (RouteCollectorProxy $a) use ($uuid): void {
                // `/outcome` before `/{id}` so it is not read as an id.
                $a->get('/outcome', [CompetencyAssessmentAction::class, 'outcome'])
                    ->setName('competency-assessments.outcome');
                // The caller's own, for self-assessment. Before `/{id}` so it
                // is not read as an id.
                $a->get('/mine', [CompetencyAssessmentAction::class, 'mine'])
                    ->setName('competency-assessments.mine');

                $a->get('', [CompetencyAssessmentAction::class, 'list'])
                    ->setName('competency-assessments.list');
                $a->post('', [CompetencyAssessmentAction::class, 'create'])
                    ->setName('competency-assessments.create');
                // Assign a profile to many people at once — the bulk link.
                $a->post('/bulk', [CompetencyAssessmentAction::class, 'bulkStart'])
                    ->setName('competency-assessments.bulk');
                $a->get('/{id:' . $uuid . '}', [CompetencyAssessmentAction::class, 'show'])
                    ->setName('competency-assessments.show');
                // The individual report for one assessment, and its narrative.
                $a->get('/{id:' . $uuid . '}/report', [CompetencyAssessmentAction::class, 'report'])
                    ->setName('competency-assessments.report');
                $a->put('/{id:' . $uuid . '}/report', [CompetencyAssessmentAction::class, 'saveReport'])
                    ->setName('competency-assessments.report.save');
                $a->post('/{id:' . $uuid . '}/self', [CompetencyAssessmentAction::class, 'selfAssess'])
                    ->setName('competency-assessments.self');
                $a->post('/{id:' . $uuid . '}/review', [CompetencyAssessmentAction::class, 'review'])
                    ->setName('competency-assessments.review');
                $a->post('/{id:' . $uuid . '}/finalize', [CompetencyAssessmentAction::class, 'finalize'])
                    ->setName('competency-assessments.finalize');
                $a->delete('/{id:' . $uuid . '}', [CompetencyAssessmentAction::class, 'delete'])
                    ->setName('competency-assessments.delete');
            });

            // What something teaches, and what somebody has learned. Three
            // kinds, one shape: a skill is held because the person finished
            // something that teaches it, whatever that something was.
            $secure->get('/courses/{id:' . $uuid . '}/skills', [ManageSkillsAction::class, 'forCourse'])
                ->setName('courses.skills');
            $secure->put('/courses/{id:' . $uuid . '}/skills', [ManageSkillsAction::class, 'setCourseSkills'])
                ->setName('courses.skills.set');

            $secure->get('/trainings/{id:' . $uuid . '}/skills', [ManageSkillsAction::class, 'forTraining'])
                ->setName('trainings.skills');
            $secure->put('/trainings/{id:' . $uuid . '}/skills', [ManageSkillsAction::class, 'setTrainingSkills'])
                ->setName('trainings.skills.set');

            $secure->get('/paths/{id:' . $uuid . '}/skills', [ManageSkillsAction::class, 'forPath'])
                ->setName('paths.skills');
            $secure->put('/paths/{id:' . $uuid . '}/skills', [ManageSkillsAction::class, 'setPathSkills'])
                ->setName('paths.skills.set');
            $secure->get('/me/skills', [ManageSkillsAction::class, 'mine'])->setName('me.skills');
            $secure->get('/users/{id:' . $uuid . '}/skills', [ManageSkillsAction::class, 'forUser'])
                ->setName('users.skills');

            // --- tenant settings ------------------------------------------
            //
            // The tenant comes from the token. `user/tenant-settings.php` read
            // it from the request body and checked only that the caller
            // administers *something* — see action 0d.
            $secure->group('/settings', function (RouteCollectorProxy $s): void {
                $s->get('', [TenantSettingsAction::class, 'show'])->setName('settings.show');
                $s->put('', [TenantSettingsAction::class, 'update'])->setName('settings.update');

                $s->get('/catalogue', [TenantSettingsAction::class, 'catalogue'])
                    ->setName('settings.catalogue');
                $s->get('/history', [TenantSettingsAction::class, 'history'])
                    ->setName('settings.history');
                $s->post('/{category}/reset', [TenantSettingsAction::class, 'reset'])
                    ->setName('settings.reset');
            });

            // --- notes ----------------------------------------------------
            //
            // Under `/me`, because a note is always the caller's own. There is
            // no user id in any of these paths. `statistics` is declared
            // before `/{id}` so it is not read as a note id.
            $secure->group('/me/notes', function (RouteCollectorProxy $n) use ($uuid): void {
                $n->get('', [MyNotesAction::class, 'list'])->setName('me.notes.list');
                $n->post('', [MyNotesAction::class, 'create'])->setName('me.notes.create');

                $n->get('/statistics', [MyNotesAction::class, 'statistics'])
                    ->setName('me.notes.statistics');

                $n->get('/{id:' . $uuid . '}', [MyNotesAction::class, 'show'])->setName('me.notes.show');
                $n->put('/{id:' . $uuid . '}', [MyNotesAction::class, 'update'])->setName('me.notes.update');
                $n->patch('/{id:' . $uuid . '}/pin', [MyNotesAction::class, 'pin'])->setName('me.notes.pin');
                $n->delete('/{id:' . $uuid . '}', [MyNotesAction::class, 'delete'])->setName('me.notes.delete');
            });

            // --- dashboards -----------------------------------------------
            //
            // `/me` takes no id: the learner dashboard is about whoever is
            // holding the token. The legacy took `user_id` from the body.
            $secure->get('/dashboards/admin', [DashboardAction::class, 'forAdmin'])
                ->setName('dashboards.admin');
            $secure->get('/dashboards/me', [DashboardAction::class, 'forMe'])
                ->setName('dashboards.me');

            // --- media library --------------------------------------------
            //
            // The bytes are served from `/{id}/file` rather than from a link
            // to storage, so every fetch is checked against the token that
            // asked for it. See StreamMediaAction.
            $secure->group('/media', function (RouteCollectorProxy $m) use ($uuid): void {
                $m->get('', [ManageMediaAction::class, 'list'])->setName('media.list');
                $m->post('', [ManageMediaAction::class, 'upload'])->setName('media.upload');

                $m->get('/statistics', [ManageMediaAction::class, 'statistics'])
                    ->setName('media.statistics');

                $m->get('/{id:' . $uuid . '}', [ManageMediaAction::class, 'show'])
                    ->setName('media.show');
                $m->put('/{id:' . $uuid . '}', [ManageMediaAction::class, 'update'])
                    ->setName('media.update');
                $m->delete('/{id:' . $uuid . '}', [ManageMediaAction::class, 'delete'])
                    ->setName('media.delete');

                $m->get('/{id:' . $uuid . '}/versions', [ManageMediaAction::class, 'versions'])
                    ->setName('media.versions');
                $m->post('/{id:' . $uuid . '}/versions', [ManageMediaAction::class, 'uploadVersion'])
                    ->setName('media.uploadVersion');
                $m->post('/{id:' . $uuid . '}/restore', [ManageMediaAction::class, 'restore'])
                    ->setName('media.restore');

                // The URL that goes in a `src`. Authenticated and
                // permission-checked here, because the fetch it enables
                // cannot be — see StreamMediaAction.
                $m->post('/{id:' . $uuid . '}/ticket', [ManageMediaAction::class, 'ticket'])
                    ->setName('media.ticket');
            });
            // --- people ---------------------------------------------------
            //
            // `statistics`, `instructors` and `roles` are declared before
            // `/{id}` so none of them is read as a user id.
            $secure->group('/users', function (RouteCollectorProxy $u) use ($uuid): void {
                $u->get('', [ManagePeopleAction::class, 'list'])->setName('users.list');
                $u->post('', [ManagePeopleAction::class, 'create'])->setName('users.create');

                $u->get('/statistics', [ManagePeopleAction::class, 'statistics'])
                    ->setName('users.statistics');
                $u->get('/instructors', [ManagePeopleAction::class, 'instructors'])
                    ->setName('users.instructors');
                $u->get('/roles', [ManagePeopleAction::class, 'roles'])->setName('users.roles');

                // Adding a lot of people at once. Nothing is kept between the
                // two calls — the client sends back the rows it was shown, and
                // the commit revalidates them from nothing. The legacy parsed
                // the upload into the PHP session, so two administrators
                // importing at once overwrote each other.
                $u->post('/import/preview', [ImportPeopleAction::class, 'preview'])
                    ->setName('users.import.preview');
                $u->post('/import', [ImportPeopleAction::class, 'commit'])
                    ->setName('users.import');

                $u->get('/{id:' . $uuid . '}', [ManagePeopleAction::class, 'show'])
                    ->setName('users.show');
                $u->put('/{id:' . $uuid . '}', [ManagePeopleAction::class, 'update'])
                    ->setName('users.update');
                $u->patch('/{id:' . $uuid . '}/status', [ManagePeopleAction::class, 'setStatus'])
                    ->setName('users.status');
                $u->delete('/{id:' . $uuid . '}', [ManagePeopleAction::class, 'delete'])
                    ->setName('users.delete');

                // Both an administrator resetting an account and a person
                // changing their own — the action tells them apart and asks
                // for the current password in the second case.
                $u->post('/{id:' . $uuid . '}/password', [ManagePeopleAction::class, 'setPassword'])
                    ->setName('users.password');

                // A colleague's picture. Any signed-in member of the same
                // tenant may fetch one — a face beside a message is the
                // ordinary case — but it is served through the API rather
                // than from a storage URL that would work for anybody.
                $u->get('/{id:' . $uuid . '}/avatar', StreamAvatarAction::class)
                    ->setName('users.avatar');
            });

            // --- the audit trail ------------------------------------------
            //
            // Read-only, and there is no route that writes one: entries come
            // from AuditMiddleware, from what the router actually matched. An
            // endpoint that let a client post its own entry would let a client
            // post somebody else's.
            $secure->get('/audit', [AuditTrailAction::class, 'list'])->setName('audit.list');
            $secure->get('/audit/actions', [AuditTrailAction::class, 'actions'])
                ->setName('audit.actions');

            // --- roles ----------------------------------------------------
            //
            // Assigning a role is not here: a person's roles are set as a set
            // by `PUT /users/{id}`. The legacy had `assign-role` and
            // `remove-role` as well, and the three disagreed about whether
            // somebody could be left with none.
            //
            // `permissions` is declared before `/{id}` so it is not read as a
            // role id.
            $secure->group('/roles', function (RouteCollectorProxy $r) use ($uuid): void {
                $r->get('', [ManageRolesAction::class, 'list'])->setName('roles.list');
                $r->post('', [ManageRolesAction::class, 'create'])->setName('roles.create');

                $r->get('/permissions', [ManageRolesAction::class, 'permissions'])
                    ->setName('roles.permissions');

                $r->get('/{id:' . $uuid . '}', [ManageRolesAction::class, 'show'])->setName('roles.show');
                $r->put('/{id:' . $uuid . '}', [ManageRolesAction::class, 'update'])->setName('roles.update');
                $r->delete('/{id:' . $uuid . '}', [ManageRolesAction::class, 'delete'])
                    ->setName('roles.delete');
            });
            // --- reporting ----------------------------------------------
            //
            // The tenant is never in a path or a body here: it comes from the
            // token, inside a ReportScope. See ReportAction for what that
            // fixes. `saved` and `learners` are declared before `{key}` so
            // neither is read as a report key.
            $secure->group('/reports', function (RouteCollectorProxy $r) use ($uuid): void {
                $r->get('', [RunReportAction::class, 'catalogue'])->setName('reports.catalogue');
                $r->get('/learners', [RunReportAction::class, 'learners'])->setName('reports.learners');

                $r->group('/saved', function (RouteCollectorProxy $sv) use ($uuid): void {
                    $sv->get('', [SavedReportAction::class, 'list'])->setName('reports.saved.list');
                    $sv->post('', [SavedReportAction::class, 'create'])->setName('reports.saved.create');
                    $sv->get('/{id:' . $uuid . '}', [SavedReportAction::class, 'show'])
                        ->setName('reports.saved.show');
                    $sv->put('/{id:' . $uuid . '}', [SavedReportAction::class, 'update'])
                        ->setName('reports.saved.update');
                    $sv->delete('/{id:' . $uuid . '}', [SavedReportAction::class, 'delete'])
                        ->setName('reports.saved.delete');
                    $sv->get('/{id:' . $uuid . '}/runs', [SavedReportAction::class, 'runs'])
                        ->setName('reports.saved.runs');
                });

                // Report keys are lower-case words and underscores; the
                // pattern keeps them from colliding with anything above.
                $r->get('/{key:[a-z_]+}', [RunReportAction::class, 'run'])->setName('reports.run');
                $r->post('/{key:[a-z_]+}', [RunReportAction::class, 'run'])->setName('reports.runPost');
                $r->get('/{key:[a-z_]+}/about', [RunReportAction::class, 'describe'])
                    ->setName('reports.describe');
                $r->get('/{key:[a-z_]+}/export', [RunReportAction::class, 'export'])
                    ->setName('reports.export');
            });
            // --- notifications ------------------------------------------
            //
            // The inbox routes carry no permission and no user id: the caller
            // comes from the token and goes straight into the WHERE clause,
            // so there is no other inbox to address. The two admin routes
            // underneath act on other people and do carry one.
            //
            // The static segments are declared before `/{id}` so that
            // `/notifications/preferences` is not read as an id.
            $secure->group('/notifications', function (RouteCollectorProxy $n) use ($uuid): void {
                $n->get('', [InboxAction::class, 'list'])->setName('notifications.list');
                $n->get('/unread-count', [InboxAction::class, 'unreadCount'])
                    ->setName('notifications.unreadCount');
                $n->post('/read-all', [InboxAction::class, 'markAllRead'])
                    ->setName('notifications.readAll');

                $n->get('/preferences', [PreferencesAction::class, 'show'])
                    ->setName('notifications.preferences');
                $n->put('/preferences', [PreferencesAction::class, 'save'])
                    ->setName('notifications.preferences.save');

                // Sending to other people, and seeing whether it arrived.
                $n->post('/announcements', [AdminNotificationAction::class, 'announce'])
                    ->setName('notifications.announce');
                $n->get('/deliveries', [AdminNotificationAction::class, 'deliveries'])
                    ->setName('notifications.deliveries');

                $n->get('/{id:' . $uuid . '}', [AdminNotificationAction::class, 'show'])
                    ->setName('notifications.show');
                $n->post('/{id:' . $uuid . '}/read', [InboxAction::class, 'markRead'])
                    ->setName('notifications.read');
                $n->delete('/{id:' . $uuid . '}', [InboxAction::class, 'delete'])
                    ->setName('notifications.delete');
            });

            $secure->group('/question-banks', function (RouteCollectorProxy $b) use ($uuid): void {
                $b->get('', ListBanksAction::class)->setName('banks.list');
                // Before `/{id}`, or `statistics` reads as a bank id.
                $b->get('/statistics', [QuestionStatisticsAction::class, 'overall'])
                    ->setName('banks.statistics.all');
                $b->post('', SaveBankAction::class)->setName('banks.create');
                $b->get('/{id:' . $uuid . '}', ShowBankAction::class)->setName('banks.show');
                $b->put('/{id:' . $uuid . '}', SaveBankAction::class)->setName('banks.update');
                $b->delete('/{id:' . $uuid . '}', DeleteBankAction::class)->setName('banks.delete');

                $b->group('/{bankId:' . $uuid . '}/questions', function (RouteCollectorProxy $q) use ($uuid): void {
                    $q->get('', ListQuestionsAction::class)->setName('questions.list');
                    // Literal segments before `/{id}`; the uuid constraint would
                    // reject them anyway, declared first for hygiene.
                    $q->get('/tags', ListQuestionTagsAction::class)->setName('questions.tags');
                    $q->get('/statistics', [QuestionStatisticsAction::class, 'bank'])
                        ->setName('questions.statistics');
                    $q->post('/import', ImportQuestionsAction::class)->setName('questions.import');
                    $q->post('', SaveQuestionAction::class)->setName('questions.create');
                    $q->put('/{id:' . $uuid . '}', SaveQuestionAction::class)->setName('questions.update');
                    $q->delete('/{id:' . $uuid . '}', DeleteQuestionAction::class)->setName('questions.delete');
                    $q->post('/{id:' . $uuid . '}/duplicate', DuplicateQuestionAction::class)
                        ->setName('questions.duplicate');
                    $q->put('/{id:' . $uuid . '}/move', MoveQuestionAction::class)->setName('questions.move');
                });
            });

            // Question images: stored and served like avatars, header-auth'd, so
            // `appAuthImg` fetches them with the bearer token.
            $secure->post('/question-images', [QuestionImageAction::class, 'upload'])
                ->setName('question-images.upload');
            $secure->get('/question-images/{imageId:' . $uuid . '}', [QuestionImageAction::class, 'stream'])
                ->setName('question-images.file');
            $secure->get('/question-import-template', QuestionTemplateAction::class)
                ->setName('questions.import.template');

            $secure->group('/assessments', function (RouteCollectorProxy $a) use ($uuid): void {
                $a->get('', ListAssessmentsAction::class)->setName('assessments.list');
                // Before `/{id}`, or the literal segments read as ids.
                $a->get('/stats', AssessmentStatsAction::class)->setName('assessments.stats');
                $a->get('/dashboard', AssessmentDashboardAction::class)->setName('assessments.dashboard');
                $a->get('/report/learners', [AssessmentReportAction::class, 'learners'])
                    ->setName('assessments.report.learners');
                $a->get('/assignment-targets', [AssessmentAssignmentAction::class, 'targets'])
                    ->setName('assessments.assignment-targets');
                $a->post('', SaveAssessmentAction::class)->setName('assessments.create');
                $a->get('/{id:' . $uuid . '}', ShowAssessmentAction::class)->setName('assessments.show');
                $a->put('/{id:' . $uuid . '}', SaveAssessmentAction::class)->setName('assessments.update');
                $a->delete('/{id:' . $uuid . '}', DeleteAssessmentAction::class)->setName('assessments.delete');
                $a->post('/{id:' . $uuid . '}/duplicate', DuplicateAssessmentAction::class)
                    ->setName('assessments.duplicate');
                $a->patch('/{id:' . $uuid . '}/status', SetAssessmentStatusAction::class)
                    ->setName('assessments.status');

                $a->group('/{id:' . $uuid . '}', function (RouteCollectorProxy $s) use ($uuid): void {
                    // Who must take it.
                    $s->get('/assignments', [AssessmentAssignmentAction::class, 'list'])
                        ->setName('assessments.assignments.list');
                    $s->post('/assignments', [AssessmentAssignmentAction::class, 'create'])
                        ->setName('assessments.assignments.create');
                    $s->delete('/assignments/{assignmentId:' . $uuid . '}', [
                        AssessmentAssignmentAction::class, 'delete',
                    ])->setName('assessments.assignments.delete');

                    // Before `/questions`, or `order` is read as a question id.
                    $s->put('/questions/order', [ManageAssessmentQuestionsAction::class, 'reorder'])
                        ->setName('assessments.questions.reorder');
                    $s->post('/questions', [ManageAssessmentQuestionsAction::class, 'attach'])
                        ->setName('assessments.questions.attach');
                    $s->put('/questions/{questionId:' . $uuid . '}/section', [
                        ManageSectionsAction::class, 'assignQuestion',
                    ])->setName('assessments.questions.section');
                    $s->put('/questions/{questionId:' . $uuid . '}', [
                        ManageAssessmentQuestionsAction::class, 'updatePoints',
                    ])->setName('assessments.questions.points');
                    $s->delete('/questions/{questionId:' . $uuid . '}', [
                        ManageAssessmentQuestionsAction::class, 'detach',
                    ])->setName('assessments.questions.detach');

                    // Sections divide the paper into named parts; optional.
                    $s->put('/sections/order', [ManageSectionsAction::class, 'reorder'])
                        ->setName('assessments.sections.reorder');
                    $s->get('/sections', [ManageSectionsAction::class, 'list'])
                        ->setName('assessments.sections.list');
                    $s->post('/sections', [ManageSectionsAction::class, 'save'])
                        ->setName('assessments.sections.create');
                    $s->put('/sections/{sectionId:' . $uuid . '}', [ManageSectionsAction::class, 'save'])
                        ->setName('assessments.sections.update');
                    $s->delete('/sections/{sectionId:' . $uuid . '}', [ManageSectionsAction::class, 'delete'])
                        ->setName('assessments.sections.delete');

                    // Proctoring configuration and the review queue.
                    $s->get('/proctoring', [ConfigureProctoringAction::class, 'show'])
                        ->setName('proctoring.settings.show');
                    $s->put('/proctoring', [ConfigureProctoringAction::class, 'save'])
                        ->setName('proctoring.settings.save');
                    $s->get('/proctoring/queue', [ReviewProctoringAction::class, 'queue'])
                        ->setName('proctoring.review.queue');

                    // Rules drive `pool` selection.
                    $s->get('/rules', [ManageRulesAction::class, 'list'])->setName('assessments.rules.list');
                    $s->post('/rules', [ManageRulesAction::class, 'create'])->setName('assessments.rules.create');
                    $s->delete('/rules/{ruleId:' . $uuid . '}', [ManageRulesAction::class, 'delete'])
                        ->setName('assessments.rules.delete');

                    // Starting a sitting. Needs only `assessment.take`.
                    $s->post('/attempts', StartAttemptAction::class)->setName('attempts.start');

                    // The marking queue. Needs `assessment.grade`.
                    $s->get('/attempts', [GradeAnswerAction::class, 'queue'])->setName('attempts.queue');

                    // Per-question difficulty/discrimination for this assessment.
                    $s->get('/item-analysis', [AssessmentReportAction::class, 'itemAnalysis'])
                        ->setName('assessments.item-analysis');
                });
            });

            // Declared before `/attempts/{id}` so `awaiting` is not read as
            // an id — the uuid constraint would reject it, and a 404 on a
            // route that exists is the worst kind.
            // Marking rubrics. Reading one needs `assessment.grade`, because a
            // marker has to see what they are marking against; writing one is
            // authoring, and needs `assessment.manage`.
            $secure->group('/rubrics', function (RouteCollectorProxy $r) use ($uuid): void {
                $r->get('', [ManageRubricsAction::class, 'list'])->setName('rubrics.list');
                $r->post('', [ManageRubricsAction::class, 'create'])->setName('rubrics.create');
                $r->get('/{id:' . $uuid . '}', [ManageRubricsAction::class, 'show'])
                    ->setName('rubrics.show');
                $r->put('/{id:' . $uuid . '}', [ManageRubricsAction::class, 'update'])
                    ->setName('rubrics.update');
                $r->delete('/{id:' . $uuid . '}', [ManageRubricsAction::class, 'delete'])
                    ->setName('rubrics.delete');
                $r->post('/{id:' . $uuid . '}/duplicate', [ManageRubricsAction::class, 'duplicate'])
                    ->setName('rubrics.duplicate');
            });

            // The marking queue. The list of what needs marking is derived from
            // the attempts; these routes are about who is doing it — see
            // GradingQueueAction.
            $secure->get('/grading/queue', [GradingQueueAction::class, 'queue'])
                ->setName('grading.queue');
            $secure->post('/grading/assign', [GradingQueueAction::class, 'assignMany'])
                ->setName('grading.assignMany');
            $secure->put('/grading/queue/{id:' . $uuid . '}', [GradingQueueAction::class, 'assign'])
                ->setName('grading.assign');
            $secure->delete('/grading/queue/{id:' . $uuid . '}', [GradingQueueAction::class, 'release'])
                ->setName('grading.release');
            $secure->post('/grading/queue/{id:' . $uuid . '}/skip', [GradingQueueAction::class, 'skip'])
                ->setName('grading.skip');
            $secure->patch(
                '/grading/queue/{id:' . $uuid . '}/priority',
                [GradingQueueAction::class, 'prioritise'],
            )->setName('grading.prioritise');

            $secure->get('/attempts/awaiting', [GradeAnswerAction::class, 'awaiting'])
                ->setName('attempts.awaiting');

            $secure->group('/attempts/{id:' . $uuid . '}', function (RouteCollectorProxy $t) use ($uuid): void {
                $t->get('', [GradeAnswerAction::class, 'show'])->setName('attempts.show');
                $t->post('/submit', SubmitAttemptAction::class)->setName('attempts.submit');

                // The proctoring extension. Every route here is gated twice:
                // the tenant must be entitled to the module, and the caller
                // must hold the permission.
                $t->group('/proctoring', function (RouteCollectorProxy $p): void {
                    // Called by the browser of the person being examined, so
                    // these need only `assessment.take` — but each is scoped
                    // to that person's own attempt.
                    $p->post('/session', [ProctoringSessionAction::class, 'open'])
                        ->setName('proctoring.session.open');
                    $p->post('/check', [ProctoringSessionAction::class, 'check'])
                        ->setName('proctoring.session.check');
                    $p->post('/violations', [ProctoringSessionAction::class, 'violation'])
                        ->setName('proctoring.violations.record');
                    $p->post('/snapshots', [ProctoringSessionAction::class, 'snapshot'])
                        ->setName('proctoring.snapshots.record');

                    // The reviewer's view, and the only route that returns
                    // recordings. Needs `proctoring.review`.
                    $p->get('', [ReviewProctoringAction::class, 'show'])->setName('proctoring.review.show');
                    $p->get('/analytics', [ReviewProctoringAction::class, 'analytics'])
                        ->setName('proctoring.review.analytics');
                    $p->get('/export', [ReviewProctoringAction::class, 'export'])
                        ->setName('proctoring.review.export');
                    $p->put('/review', [ReviewProctoringAction::class, 'reviewSession'])
                        ->setName('proctoring.review.session');
                });

                // Grading is declared before the plain answer route so
                // `/grade` is not read as part of the question id.
                $t->put('/answers/{questionId:' . $uuid . '}/grade', GradeAnswerAction::class)
                    ->setName('attempts.grade');
                $t->put('/answers/{questionId:' . $uuid . '}', SaveAnswerAction::class)
                    ->setName('attempts.answer');
            });

            // Everything waiting on a reviewer, across every assessment.
            $secure->get('/proctoring/queue', [ReviewProctoringAction::class, 'allQueues'])
                ->setName('proctoring.queue');

            $secure->put('/proctoring/violations/{violationId:' . $uuid . '}', [
                ReviewProctoringAction::class, 'review',
            ])->setName('proctoring.violations.review');

            $secure->put('/proctoring/snapshots/{snapshotId:' . $uuid . '}/flag', [
                ReviewProctoringAction::class, 'flagSnapshot',
            ])->setName('proctoring.snapshots.flag');

            // Instructor-led training. Scheduling an online session books a
            // real meeting through the provider layer — the wiring the legacy
            // code never had; see SessionScheduler.
            $secure->group('/trainings', function (RouteCollectorProxy $t) use ($uuid): void {
                $t->get('', [ManageTrainingsAction::class, 'list'])->setName('trainings.list');
                $t->post('', [ManageTrainingsAction::class, 'save'])->setName('trainings.create');
                $t->get('/{id:' . $uuid . '}', [ManageTrainingsAction::class, 'show'])->setName('trainings.show');
                $t->put('/{id:' . $uuid . '}', [ManageTrainingsAction::class, 'save'])->setName('trainings.update');
                $t->patch('/{id:' . $uuid . '}/status', [ManageTrainingsAction::class, 'setStatus'])
                    ->setName('trainings.status');
                $t->delete('/{id:' . $uuid . '}', [ManageTrainingsAction::class, 'delete'])
                    ->setName('trainings.delete');

                $t->group('/{id:' . $uuid . '}', function (RouteCollectorProxy $one) use ($uuid): void {
                    // Enrolment.
                    $one->get('/roster', [TrainingEnrolmentAction::class, 'roster'])->setName('trainings.roster');
                    $one->post('/roster', [TrainingEnrolmentAction::class, 'assign'])->setName('trainings.assign');
                    $one->delete('/roster', [TrainingEnrolmentAction::class, 'withdraw'])
                        ->setName('trainings.withdraw');
                    $one->post('/enrol', [TrainingEnrolmentAction::class, 'selfEnrol'])
                        ->setName('trainings.selfEnrol');

                    // Standing enrolment rules. `assign` above puts people on
                    // this programme today; these keep doing it as people
                    // join the group named.
                    $one->get('/rules', [EnrollmentRuleAction::class, 'listForTraining'])
                        ->setName('trainings.rules');
                    $one->post('/rules', [EnrollmentRuleAction::class, 'createForTraining'])
                        ->setName('trainings.rules.create');

                    // Sessions.
                    $one->post('/sessions', [ManageSessionsAction::class, 'create'])->setName('sessions.create');

                    $one->group('/sessions/{sessionId:' . $uuid . '}', function (RouteCollectorProxy $s) use ($uuid): void {
                        $s->put('', [ManageSessionsAction::class, 'update'])->setName('sessions.update');
                        $s->patch('/status', [ManageSessionsAction::class, 'setStatus'])->setName('sessions.status');
                        $s->post('/meeting', [ManageSessionsAction::class, 'meeting'])->setName('sessions.meeting');
                        $s->post('/qr', [ManageSessionsAction::class, 'issueQr'])->setName('sessions.qr');

                        // A session can be taught by more than one person: a
                        // lead, co-instructors, assistants. One of them holds
                        // the meeting's host link, because the provider only
                        // issues one.
                        $s->get('/instructors', [ManageSessionsAction::class, 'instructors'])
                            ->setName('sessions.instructors');
                        $s->post('/instructors', [ManageSessionsAction::class, 'assignInstructor'])
                            ->setName('sessions.instructors.assign');
                        $s->delete(
                            '/instructors/{userId:' . $uuid . '}',
                            [ManageSessionsAction::class, 'removeInstructor'],
                        )->setName('sessions.instructors.remove');

                        // What reminders are planned, and whether they went
                        // out. The legacy could not answer either question.
                        $s->get('/reminders', [AdminNotificationAction::class, 'reminders'])
                            ->setName('sessions.reminders');

                        // Attendance. `sync` reads it back from the provider
                        // rather than trusting a learner's own browser.
                        $s->get('/attendance', [AttendanceAction::class, 'register'])->setName('attendance.register');
                        $s->put('/attendance', [AttendanceAction::class, 'mark'])->setName('attendance.mark');
                        $s->post('/attendance/sync', [AttendanceAction::class, 'sync'])->setName('attendance.sync');
                        $s->post('/check-in', [AttendanceAction::class, 'checkIn'])->setName('attendance.checkIn');
                    });
                });
            });

            $secure->group('/ilt', function (RouteCollectorProxy $ilt): void {
                $ilt->get('/providers', ListMeetingProvidersAction::class)
                    ->setName('ilt.providers');

                // Each tenant connects its own Zoom, Google or Teams
                // account, so meetings appear in their own console.
                $ilt->get('/integrations', [VideoIntegrationAction::class, 'list'])
                    ->setName('ilt.integrations');
                $ilt->post('/integrations/{platform}/connect', [VideoIntegrationAction::class, 'connect'])
                    ->setName('ilt.integrations.connect');
                $ilt->post('/integrations/{platform}/callback', [VideoIntegrationAction::class, 'callback'])
                    ->setName('ilt.integrations.callback');
                $ilt->patch('/integrations/{platform}', [VideoIntegrationAction::class, 'update'])
                    ->setName('ilt.integrations.update');
                $ilt->delete('/integrations/{platform}', [VideoIntegrationAction::class, 'disconnect'])
                    ->setName('ilt.integrations.disconnect');

                // Session CRUD is added here once the ILT domain lands. Each
                // route declares its own permission, e.g.
                //   ->add(RequirePermissionMiddleware::of(Permission::IltSessionManage))
            });
            // Added before authentication, so it runs inside it — Slim
            // executes the last-added middleware first. Audit therefore sees a
            // request that has already been authenticated and a route that has
            // already been matched, which is what it records.
        })->add(AuditMiddleware::class)->add(AuthenticationMiddleware::class);
    });
};
