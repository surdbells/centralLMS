<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Certificate;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Certificate\CertificateEligibility;
use Central\Api\Domain\Certificate\CertificateIdentity;
use Central\Api\Domain\Certificate\CertificateRenderer;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Certificate\CertificateSource;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Ilt\TrainingRepository;
use Central\Api\Domain\Identity\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/certificates
 *
 * Issues a certificate for a course, a training programme or an assessment.
 *
 * **The eligibility check is the substance of this endpoint.** Issuing is not
 * a write of whatever the caller sends: it goes back to the module that
 * earned it and asks whether this learner actually did. A certificate that
 * can be conjured by POSTing a course id and a name is not evidence of
 * anything, and a verification endpoint that vouches for such a thing is
 * worse than none.
 *
 * `force: true` lets someone with the issuing permission override a failed
 * check — a paper record, an exemption — but it is recorded as an override in
 * the history rather than being indistinguishable from an earned one.
 */
final class IssueCertificateAction extends CertificateAction
{
    public function __construct(
        CertificateRepository $certificates,
        CertificateRenderer $renderer,
        Notifier $notifier,
        private readonly CourseRepository $courses,
        private readonly EnrollmentRepository $enrollments,
        private readonly TrainingRepository $trainings,
        private readonly AssessmentRepository $assessments,
        private readonly AttemptRepository $attempts,
        private readonly UserRepository $users,
    ) {
        parent::__construct($certificates, $renderer, $notifier);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $tenantId = $this->tenantId($request);
        $body = $this->body($request);

        $source = CertificateSource::tryFrom(strtolower(trim((string) ($body['source'] ?? ''))));

        if ($source === null) {
            throw ValidationFailed::fromArray([
                'source' => 'Choose one of: course, training, assessment.',
            ]);
        }

        $sourceId = trim((string) ($body['sourceId'] ?? ''));
        $userId = trim((string) ($body['userId'] ?? ''));

        if ($sourceId === '' || $userId === '') {
            throw ValidationFailed::fromArray([
                'sourceId' => 'Name what the certificate is for and who it is for.',
            ]);
        }

        $learner = $this->users->findByTenant($tenantId, $userId)
            ?? throw new EntityNotFound('User', $userId);

        $earned = $this->eligibility($tenantId, $source, $sourceId, $userId);
        $force = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOL);

        if (!$earned->eligible && !$force) {
            throw new ConflictingState($earned->reason ?? 'Not eligible.', 'not_eligible');
        }

        $templateId = $this->resolveTemplate($tenantId, $body['templateId'] ?? null);

        $identity = CertificateIdentity::mint(
            $this->certificates->tenantCode($tenantId),
            (int) date('Y'),
            $this->certificates->nextSequence($tenantId, (int) date('Y')),
        );

        $certificate = $this->certificates->issue(
            $tenantId,
            $source,
            $sourceId,
            $userId,
            [
                'identity'   => $identity,
                'templateId' => $templateId,
                // The name is copied from the learner as they are now, and
                // never updated again — see the Certificate docblock.
                'learnerName'  => $earned->learnerName,
                'learnerEmail' => $learner->email,
                'title'        => $earned->title,
                'score'        => $earned->score,
                'percentage'   => $earned->percentage,
                'grade'        => $this->grade($earned->percentage),
                'attemptId'    => $earned->attemptId,
                'enrollmentId' => $earned->enrollmentId,
                'customData'   => is_array($body['customData'] ?? null) ? $body['customData'] : [],
                'validFrom'    => $this->date($body['validFrom'] ?? null),
                'validUntil'   => $this->date($body['validUntil'] ?? null),
            ],
            $this->userId($request),
        );

        if (!$earned->eligible) {
            // Recorded so the register can tell an earned certificate from one
            // an administrator decided to grant.
            $this->certificates->log(
                $tenantId,
                $certificate->id,
                'issued',
                $this->userId($request),
                ['notes' => 'Issued despite: ' . ($earned->reason ?? 'not eligible.')],
            );
        }

        // The whole point of issuing one is that the holder finds out.
        $this->notifier->notifyOne(
            $tenantId,
            $certificate->userId,
            NotificationType::CertificateIssued,
            [
                'subjectTitle'      => $certificate->title,
                'certificateNumber' => $certificate->identity->number,
            ],
            $certificate->id,
        );

        return Json::created(
            $response,
            $certificate->toArray([
                'verificationUrl' => $this->verificationUrl($request, $certificate),
                'wasOverridden'   => !$earned->eligible,
            ]),
            '/v1/certificates/' . $certificate->id,
        );
    }

    /**
     * Asks the owning module whether this learner earned it.
     */
    private function eligibility(
        string $tenantId,
        CertificateSource $source,
        string $sourceId,
        string $userId,
    ): CertificateEligibility {
        $learner = $this->users->findByTenant($tenantId, $userId)
            ?? throw new EntityNotFound('User', $userId);

        // Falls back to the address rather than leaving a certificate with a
        // blank name on it.
        $name = $learner->fullName();
        $name = $name === '' ? $learner->email : $name;

        return match ($source) {
            CertificateSource::Course     => $this->courseEligibility($tenantId, $sourceId, $userId, $name),
            CertificateSource::Training   => $this->trainingEligibility($tenantId, $sourceId, $userId, $name),
            CertificateSource::Assessment => $this->assessmentEligibility($tenantId, $sourceId, $userId, $name),
        };
    }

    private function courseEligibility(
        string $tenantId,
        string $courseId,
        string $userId,
        string $name,
    ): CertificateEligibility {
        $course = $this->courses->find($tenantId, $courseId)
            ?? throw new EntityNotFound('Course', $courseId);

        $enrolment = $this->enrollments->findForUserAndCourse($tenantId, $userId, $courseId);

        if ($enrolment === null) {
            return CertificateEligibility::notEarned(
                $course->title,
                $name,
                'They are not enrolled on this course.',
            );
        }

        if (!$enrolment->status->isComplete()) {
            return CertificateEligibility::notEarned(
                $course->title,
                $name,
                sprintf('They have completed %s%% of the course.', $enrolment->progressPercentage),
                $enrolment->progressPercentage,
                $enrolment->id,
            );
        }

        return CertificateEligibility::earned(
            $course->title,
            $name,
            $enrolment->finalScore,
            $enrolment->progressPercentage,
            null,
            $enrolment->id,
        );
    }

    private function trainingEligibility(
        string $tenantId,
        string $trainingId,
        string $userId,
        string $name,
    ): CertificateEligibility {
        $training = $this->trainings->find($tenantId, $trainingId)
            ?? throw new EntityNotFound('Training', $trainingId);

        $enrolment = $this->trainings->findEnrolment($tenantId, $trainingId, $userId);

        if ($enrolment === null) {
            return CertificateEligibility::notEarned(
                $training->title,
                $name,
                'They are not on this programme.',
            );
        }

        $attendance = (float) $enrolment['attendancePercentage'];
        $enrolmentId = (string) $enrolment['id'];

        if ($enrolment['status'] !== 'completed') {
            return CertificateEligibility::notEarned(
                $training->title,
                $name,
                sprintf(
                    'They attended %s%% of the programme, and %s%% is required.',
                    $attendance,
                    $training->minAttendancePercentage,
                ),
                $attendance,
                $enrolmentId,
            );
        }

        // Attendance is not a mark, so it is not printed as one — see
        // CertificateSource::carriesScore().
        return CertificateEligibility::earned(
            $training->title,
            $name,
            null,
            $attendance,
            null,
            $enrolmentId,
        );
    }

    private function assessmentEligibility(
        string $tenantId,
        string $assessmentId,
        string $userId,
        string $name,
    ): CertificateEligibility {
        $assessment = $this->assessments->find($tenantId, $assessmentId)
            ?? throw new EntityNotFound('Assessment', $assessmentId);

        $attempts = $this->attempts->forLearner($tenantId, $assessmentId, $userId);
        $best = null;

        foreach ($attempts as $attempt) {
            // The best passing attempt: someone who passed on their second try
            // passed, and the certificate should say what they scored then
            // rather than on a later practice run.
            if (
                $attempt->passed === true
                && ($best === null || ($attempt->percentage ?? 0.0) > ($best->percentage ?? 0.0))
            ) {
                $best = $attempt;
            }
        }

        if ($best === null) {
            return CertificateEligibility::notEarned(
                $assessment->title,
                $name,
                $attempts === []
                    ? 'They have not sat this assessment.'
                    : 'They have not passed this assessment.',
            );
        }

        return CertificateEligibility::earned(
            $assessment->title,
            $name,
            $best->rawScore,
            $best->percentage,
            $best->id,
        );
    }
    private function resolveTemplate(string $tenantId, mixed $requested): ?string
    {
        if (is_string($requested) && trim($requested) !== '') {
            $template = $this->certificates->findTemplate($tenantId, trim($requested))
                ?? throw new EntityNotFound('Template', trim($requested));

            return $template->id;
        }

        return $this->certificates->findDefaultTemplate($tenantId)?->id;
    }

    /**
     * A letter grade, where a percentage exists.
     *
     * Bands are deliberately conventional and deliberately not configurable
     * yet: a tenant-defined grading scale is a real feature, and guessing at
     * one now would mean migrating whatever we guessed.
     */
    private function grade(?float $percentage): ?string
    {
        if ($percentage === null) {
            return null;
        }

        return match (true) {
            $percentage >= 90 => 'A',
            $percentage >= 80 => 'B',
            $percentage >= 70 => 'C',
            $percentage >= 60 => 'D',
            default           => 'E',
        };
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value)))->format('Y-m-d');
        } catch (\Exception) {
            throw ValidationFailed::fromArray(['validUntil' => 'Use a date in the form 2026-03-01.']);
        }
    }
}
