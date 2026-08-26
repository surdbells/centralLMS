<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Shared\Difficulty;

/**
 * An assessment: a quiz inside a course, a standalone exam, or — once the
 * extension lands — a proctored sitting. One shape for all three.
 */
final readonly class Assessment
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $title,
        public AssessmentStatus $status = AssessmentStatus::Draft,
        public string $kind = 'quiz',
        public ?string $description = null,
        public ?string $instructions = null,
        public ?Difficulty $difficulty = null,
        public ?string $courseId = null,
        public ?string $moduleId = null,
        public ?string $categoryId = null,
        public ?int $timeLimitMinutes = null,
        public ?\DateTimeImmutable $availableFrom = null,
        public ?\DateTimeImmutable $availableUntil = null,
        public int $gracePeriodMinutes = 0,
        public ?int $maxAttempts = null,
        public int $attemptsGapHours = 0,
        public float $passingScore = 70.0,
        public PassingType $passingType = PassingType::Percentage,
        public QuestionSelectionMode $questionSelection = QuestionSelectionMode::Manual,
        /** How many to draw in `random` mode. An input, not a total. */
        public ?int $randomQuestionCount = null,
        public bool $shuffleQuestions = false,
        public bool $shuffleOptions = false,
        public RevealAnswers $revealAnswers = RevealAnswers::AfterAllAttempts,
        public bool $showScoreImmediately = true,
        public bool $showExplanations = true,
        public bool $allowBackwardNavigation = true,
        public bool $requiresManualGrading = false,
        public ProctoringLevel $proctoringLevel = ProctoringLevel::None,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Derived, never stored — see the migration docblock. */
        public int $questionCount = 0,
        public float $totalPoints = 0.0,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, array $input): self
    {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Enter a title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'Title must be 255 characters or fewer.';
        }

        $kind = strtolower(trim((string) ($input['kind'] ?? 'quiz'))) ?: 'quiz';

        if (!in_array($kind, ['quiz', 'exam', 'assignment', 'survey'], true)) {
            $violations['kind'] = 'Choose quiz, exam, assignment or survey.';
        }

        $passingType = PassingType::tryFrom(strtolower(trim((string) ($input['passingType'] ?? 'percentage'))))
            ?? PassingType::Percentage;

        $passingScore = (float) ($input['passingScore'] ?? 70);

        if ($passingScore < 0) {
            $violations['passingScore'] = 'A pass mark cannot be negative.';
        } elseif ($passingType === PassingType::Percentage && $passingScore > 100) {
            // A percentage above 100 can never be reached, so every learner
            // fails and nobody can say why.
            $violations['passingScore'] = 'A percentage pass mark cannot be above 100.';
        }

        $timeLimit = self::positiveIntOrNull($input['timeLimitMinutes'] ?? null);

        if (isset($input['timeLimitMinutes']) && $input['timeLimitMinutes'] !== '' && $timeLimit === null) {
            $violations['timeLimitMinutes'] = 'A time limit must be a positive number of minutes, or left empty.';
        }

        $maxAttempts = self::positiveIntOrNull($input['maxAttempts'] ?? null);

        if (isset($input['maxAttempts']) && $input['maxAttempts'] !== '' && $maxAttempts === null) {
            $violations['maxAttempts'] = 'Allow at least one attempt, or leave it empty for unlimited.';
        }

        $availableFrom = self::parseDate($input['availableFrom'] ?? null);
        $availableUntil = self::parseDate($input['availableUntil'] ?? null);

        if ($availableFrom !== null && $availableUntil !== null && $availableUntil < $availableFrom) {
            $violations['availableUntil'] = 'The closing date cannot be before the opening one.';
        }

        $grace = (int) ($input['gracePeriodMinutes'] ?? 0);
        $gap = (int) ($input['attemptsGapHours'] ?? 0);

        if ($grace < 0) {
            $violations['gracePeriodMinutes'] = 'A grace period cannot be negative.';
        }

        if ($gap < 0) {
            $violations['attemptsGapHours'] = 'A gap between attempts cannot be negative.';
        }

        $moduleId = self::text($input['moduleId'] ?? null);
        $courseId = self::text($input['courseId'] ?? null);

        if ($moduleId !== null && $courseId === null) {
            $violations['courseId'] = 'A module quiz must name the course the module is in.';
        }

        $selection = QuestionSelectionMode::tryFrom(
            strtolower(trim((string) ($input['questionSelection'] ?? 'manual'))),
        ) ?? QuestionSelectionMode::Manual;

        $randomCount = self::positiveIntOrNull($input['randomQuestionCount'] ?? null);

        if ($selection === QuestionSelectionMode::Random && $randomCount === null) {
            // Drawing an unspecified number draws nothing, and an empty
            // paper scores everyone zero out of zero — which the pass rules
            // then read as a pass.
            $violations['randomQuestionCount'] = 'Say how many questions each learner should be asked.';
        }

        $difficulty = null;

        if (isset($input['difficulty']) && trim((string) $input['difficulty']) !== '') {
            try {
                $difficulty = Difficulty::fromLegacy((string) $input['difficulty']);
            } catch (\ValueError) {
                $violations['difficulty'] = 'Choose beginner, intermediate or professional.';
            }
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            title: $title,
            status: AssessmentStatus::tryFrom(strtolower(trim((string) ($input['status'] ?? 'draft'))))
                ?? AssessmentStatus::Draft,
            kind: $kind,
            description: self::text($input['description'] ?? null),
            instructions: self::text($input['instructions'] ?? null),
            difficulty: $difficulty,
            courseId: $courseId,
            moduleId: $moduleId,
            categoryId: self::text($input['categoryId'] ?? null),
            timeLimitMinutes: $timeLimit,
            availableFrom: $availableFrom,
            availableUntil: $availableUntil,
            gracePeriodMinutes: $grace,
            maxAttempts: $maxAttempts,
            attemptsGapHours: $gap,
            passingScore: $passingScore,
            passingType: $passingType,
            questionSelection: $selection,
            randomQuestionCount: $selection === QuestionSelectionMode::Random ? $randomCount : null,
            shuffleQuestions: filter_var($input['shuffleQuestions'] ?? false, FILTER_VALIDATE_BOOL),
            shuffleOptions: filter_var($input['shuffleOptions'] ?? false, FILTER_VALIDATE_BOOL),
            revealAnswers: RevealAnswers::tryFrom(
                strtolower(trim((string) ($input['revealAnswers'] ?? 'after_all_attempts'))),
            ) ?? RevealAnswers::AfterAllAttempts,
            showScoreImmediately: filter_var($input['showScoreImmediately'] ?? true, FILTER_VALIDATE_BOOL),
            showExplanations: filter_var($input['showExplanations'] ?? true, FILTER_VALIDATE_BOOL),
            allowBackwardNavigation: filter_var($input['allowBackwardNavigation'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * The same assessment with its author recorded.
     *
     * A wither rather than a hand-written `new self(...)` at the call site.
     * Restating twenty-odd constructor arguments to change one is how
     * `randomQuestionCount` was silently dropped on create — the third time
     * a hand-maintained field list has lost a field in this codebase.
     */
    public function withCreatedBy(?string $userId): self
    {
        $clone = get_object_vars($this);
        $clone['createdBy'] = $userId;

        return new self(...$clone);
    }

    /**
     * Whether a learner may start it right now.
     *
     * Deliberately excludes the attempt limit and the gap between attempts:
     * both need the learner's history, which belongs to the repository. See
     * `Attempt::refusalToStart()` for those.
     */
    public function isOpenNow(\DateTimeImmutable $now): bool
    {
        if (!$this->status->isAvailableToLearners()) {
            return false;
        }

        if ($this->availableFrom !== null && $now < $this->availableFrom) {
            return false;
        }

        return $this->availableUntil === null || $now <= $this->availableUntil->modify(
            sprintf('+%d minutes', $this->gracePeriodMinutes),
        );
    }

    /**
     * When an attempt started now would run out.
     *
     * Resolved once, at the start, so that editing the time limit mid-sitting
     * cannot shorten an attempt already underway. Null when untimed.
     */
    public function deadlineFor(\DateTimeImmutable $startedAt): ?\DateTimeImmutable
    {
        if ($this->timeLimitMinutes === null) {
            return null;
        }

        $deadline = $startedAt->modify(
            sprintf('+%d minutes', $this->timeLimitMinutes + $this->gracePeriodMinutes),
        );

        // A sitting cannot run past the window's own close, or a learner
        // starting five minutes before the deadline gets the full hour.
        if ($this->availableUntil !== null) {
            $windowClose = $this->availableUntil->modify(
                sprintf('+%d minutes', $this->gracePeriodMinutes),
            );

            return $deadline < $windowClose ? $deadline : $windowClose;
        }

        return $deadline;
    }

    public function isPassed(float $rawScore, float $maxScore): bool
    {
        return $this->passingType->isPassed($rawScore, $maxScore, $this->passingScore);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'description'   => $this->description,
            'instructions'  => $this->instructions,
            'kind'          => $this->kind,
            'status'        => $this->status->value,
            'statusLabel'   => $this->status->label(),
            'difficulty'    => $this->difficulty?->value,
            'courseId'      => $this->courseId,
            'moduleId'      => $this->moduleId,
            'categoryId'    => $this->categoryId,
            'timeLimitMinutes'   => $this->timeLimitMinutes,
            'availableFrom'      => $this->availableFrom?->format(\DateTimeInterface::ATOM),
            'availableUntil'     => $this->availableUntil?->format(\DateTimeInterface::ATOM),
            'gracePeriodMinutes' => $this->gracePeriodMinutes,
            'maxAttempts'        => $this->maxAttempts,
            'attemptsGapHours'   => $this->attemptsGapHours,
            'passingScore'       => $this->passingScore,
            'passingType'        => $this->passingType->value,
            'questionSelection'  => $this->questionSelection->value,
            'questionSelectionLabel' => $this->questionSelection->label(),
            'randomQuestionCount' => $this->randomQuestionCount,
            'shuffleQuestions'   => $this->shuffleQuestions,
            'shuffleOptions'     => $this->shuffleOptions,
            'revealAnswers'      => $this->revealAnswers->value,
            'showScoreImmediately' => $this->showScoreImmediately,
            'showExplanations'     => $this->showExplanations,
            'allowBackwardNavigation' => $this->allowBackwardNavigation,
            'requiresManualGrading'   => $this->requiresManualGrading,
            'proctoringLevel'         => $this->proctoringLevel->value,
            // Counted, not stored: the legacy total_questions disagreed with
            // reality in 16 of its 19 rows.
            'questionCount' => $this->questionCount,
            'totalPoints'   => $this->totalPoints,
            'publishedAt'   => $this->publishedAt?->format(\DateTimeInterface::ATOM),
            'createdAt'     => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            ...$extra,
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
