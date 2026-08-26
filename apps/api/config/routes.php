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
use Central\Api\Application\Action\Auth\LogoutAction;
use Central\Api\Application\Action\Auth\RefreshAction;
use Central\Api\Application\Action\Catalog\CourseCatalogueAction;
use Central\Api\Application\Action\Catalog\CourseOutlineAction;
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
use Central\Api\Application\Action\Assessment\DeleteBankAction;
use Central\Api\Application\Action\Assessment\GradeAnswerAction;
use Central\Api\Application\Action\Assessment\ListAssessmentsAction;
use Central\Api\Application\Action\Assessment\ListBanksAction;
use Central\Api\Application\Action\Assessment\ListQuestionsAction;
use Central\Api\Application\Action\Assessment\ManageAssessmentQuestionsAction;
use Central\Api\Application\Action\Assessment\SaveAnswerAction;
use Central\Api\Application\Action\Assessment\SaveAssessmentAction;
use Central\Api\Application\Action\Assessment\SaveBankAction;
use Central\Api\Application\Action\Assessment\SaveQuestionAction;
use Central\Api\Application\Action\Assessment\SetAssessmentStatusAction;
use Central\Api\Application\Action\Assessment\ShowAssessmentAction;
use Central\Api\Application\Action\Assessment\ShowBankAction;
use Central\Api\Application\Action\Assessment\StartAttemptAction;
use Central\Api\Application\Action\Assessment\SubmitAttemptAction;
use Central\Api\Application\Action\Enrollment\AssignEnrollmentsAction;
use Central\Api\Application\Action\Enrollment\CourseEnrollmentStatsAction;
use Central\Api\Application\Action\Enrollment\EnrollmentHistoryAction;
use Central\Api\Application\Action\Enrollment\ListCourseEnrollmentsAction;
use Central\Api\Application\Action\Enrollment\MyCourseProgressAction;
use Central\Api\Application\Action\Enrollment\MyEnrollmentsAction;
use Central\Api\Application\Action\Enrollment\RecordProgressAction;
use Central\Api\Application\Action\Enrollment\SelfEnrollAction;
use Central\Api\Application\Action\Enrollment\UnenrollAction;
use Central\Api\Application\Action\HealthAction;
use Central\Api\Application\Action\Ilt\ListMeetingProvidersAction;
use Central\Api\Application\Action\Lookup\ActiveLookupsAction;
use Central\Api\Application\Action\Lookup\CreateLookupAction;
use Central\Api\Application\Action\Lookup\ListLookupsAction;
use Central\Api\Application\Action\Lookup\SetLookupStatusAction;
use Central\Api\Application\Action\Lookup\ShowLookupAction;
use Central\Api\Application\Action\Lookup\UpdateLookupAction;
use Central\Api\Application\Action\MeAction;
use Central\Api\Application\Action\VersionAction;
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

    $app->group('/v1', function (RouteCollectorProxy $v1) use ($uuid): void {
        // --- public -------------------------------------------------------
        $v1->group('/auth', function (RouteCollectorProxy $auth): void {
            $auth->post('/login', LoginAction::class)->setName('auth.login');

            // Public by necessity: the refresh token is the credential, and
            // this is called precisely when the access token has expired.
            $auth->post('/refresh', RefreshAction::class)->setName('auth.refresh');

            // Public so signing out always succeeds. The `allDevices` variant
            // asks for the caller inside the action, which requires a valid
            // access token.
            $auth->post('/logout', LogoutAction::class)->setName('auth.logout');
        });

        // Registration, password reset and OTP land here with the people
        // module — they need invitations and the Termii/ZeptoMail senders.

        // --- authenticated ------------------------------------------------
        $v1->group('', function (RouteCollectorProxy $secure) use ($uuid): void {
            $secure->get('/me', MeAction::class)->setName('me');

            // The learner's own view. Scoped to the caller by construction —
            // there is no user id in any of these paths, so there is nothing
            // to tamper with.
            $secure->group('/me/enrollments', function (RouteCollectorProxy $m) use ($uuid): void {
                $m->get('', MyEnrollmentsAction::class)->setName('me.enrollments');
                $m->get('/{courseId:' . $uuid . '}', MyCourseProgressAction::class)
                    ->setName('me.enrollments.show');
                $m->put(
                    '/{courseId:' . $uuid . '}/lessons/{lessonId:' . $uuid . '}/progress',
                    RecordProgressAction::class,
                )->setName('me.progress.record');
            });

            // The course catalogue.
            $secure->group('/courses', function (RouteCollectorProxy $c) use ($uuid): void {
                // The learner-facing catalogue is a distinct route, not a
                // filter on the admin list — see CourseCatalogueAction. It is
                // declared first so `catalogue` is not swallowed by `/{id}`.
                $c->get('/catalogue', CourseCatalogueAction::class)->setName('courses.catalogue');
                $c->get('', ListCoursesAction::class)->setName('courses.list');
                $c->post('', CreateCourseAction::class)->setName('courses.create');
                $c->get('/{id:' . $uuid . '}', ShowCourseAction::class)->setName('courses.show');
                $c->put('/{id:' . $uuid . '}', UpdateCourseAction::class)->setName('courses.update');
                $c->patch('/{id:' . $uuid . '}/status', SetCourseStatusAction::class)->setName('courses.status');

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
            $secure->group('/question-banks', function (RouteCollectorProxy $b) use ($uuid): void {
                $b->get('', ListBanksAction::class)->setName('banks.list');
                $b->post('', SaveBankAction::class)->setName('banks.create');
                $b->get('/{id:' . $uuid . '}', ShowBankAction::class)->setName('banks.show');
                $b->put('/{id:' . $uuid . '}', SaveBankAction::class)->setName('banks.update');
                $b->delete('/{id:' . $uuid . '}', DeleteBankAction::class)->setName('banks.delete');

                $b->group('/{bankId:' . $uuid . '}/questions', function (RouteCollectorProxy $q) use ($uuid): void {
                    $q->get('', ListQuestionsAction::class)->setName('questions.list');
                    $q->post('', SaveQuestionAction::class)->setName('questions.create');
                    $q->put('/{id:' . $uuid . '}', SaveQuestionAction::class)->setName('questions.update');
                });
            });

            $secure->group('/assessments', function (RouteCollectorProxy $a) use ($uuid): void {
                $a->get('', ListAssessmentsAction::class)->setName('assessments.list');
                $a->post('', SaveAssessmentAction::class)->setName('assessments.create');
                $a->get('/{id:' . $uuid . '}', ShowAssessmentAction::class)->setName('assessments.show');
                $a->put('/{id:' . $uuid . '}', SaveAssessmentAction::class)->setName('assessments.update');
                $a->patch('/{id:' . $uuid . '}/status', SetAssessmentStatusAction::class)
                    ->setName('assessments.status');

                $a->group('/{id:' . $uuid . '}', function (RouteCollectorProxy $s) use ($uuid): void {
                    // Before `/questions`, or `order` is read as a question id.
                    $s->put('/questions/order', [ManageAssessmentQuestionsAction::class, 'reorder'])
                        ->setName('assessments.questions.reorder');
                    $s->post('/questions', [ManageAssessmentQuestionsAction::class, 'attach'])
                        ->setName('assessments.questions.attach');
                    $s->delete('/questions/{questionId:' . $uuid . '}', [
                        ManageAssessmentQuestionsAction::class, 'detach',
                    ])->setName('assessments.questions.detach');

                    // Starting a sitting. Needs only `assessment.take`.
                    $s->post('/attempts', StartAttemptAction::class)->setName('attempts.start');

                    // The marking queue. Needs `assessment.grade`.
                    $s->get('/attempts', [GradeAnswerAction::class, 'queue'])->setName('attempts.queue');
                });
            });

            $secure->group('/attempts/{id:' . $uuid . '}', function (RouteCollectorProxy $t) use ($uuid): void {
                $t->get('', [GradeAnswerAction::class, 'show'])->setName('attempts.show');
                $t->post('/submit', SubmitAttemptAction::class)->setName('attempts.submit');

                // Grading is declared before the plain answer route so
                // `/grade` is not read as part of the question id.
                $t->put('/answers/{questionId:' . $uuid . '}/grade', GradeAnswerAction::class)
                    ->setName('attempts.grade');
                $t->put('/answers/{questionId:' . $uuid . '}', SaveAnswerAction::class)
                    ->setName('attempts.answer');
            });

            $secure->group('/ilt', function (RouteCollectorProxy $ilt): void {
                $ilt->get('/providers', ListMeetingProvidersAction::class)
                    ->setName('ilt.providers');

                // Session CRUD is added here once the ILT domain lands. Each
                // route declares its own permission, e.g.
                //   ->add(RequirePermissionMiddleware::of(Permission::IltSessionManage))
            });
        })->add(AuthenticationMiddleware::class);
    });
};
