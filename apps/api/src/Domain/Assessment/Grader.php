<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Marks one answer against one question.
 *
 * Pure: no database, no clock, no randomness. Everything it needs is the
 * question (with its options) and what the learner submitted, which is what
 * makes every rule below testable in isolation — and grading is the part of an
 * LMS where being quietly wrong costs the most.
 *
 * The answer payload is a small JSON object whose shape depends on the type:
 *
 * | Type                       | Shape                                  |
 * | -------------------------- | -------------------------------------- |
 * | `multiple_choice_single`   | `{"optionIds": ["…"]}`                 |
 * | `multiple_choice_multiple` | `{"optionIds": ["…", "…"]}`            |
 * | `true_false`               | `{"optionIds": ["…"]}`                 |
 * | `ordering`                 | `{"optionIds": [ … in the learner's order ]}` |
 * | `matching`                 | `{"pairs": {"<optionId>": "<term>"}}`  |
 * | `fill_in_blanks`           | `{"texts": ["…", "…"]}`                |
 * | `short_answer`             | `{"text": "…"}`                        |
 * | `numeric`                  | `{"value": 42.5}`                      |
 * | `long_answer`, `file_upload`, `hotspot` | whatever the client sends |
 */
final class Grader
{
    /**
     * @param array<string, mixed> $answer
     */
    public function grade(Question $question, array $answer, float $pointsPossible): GradeResult
    {
        if (!$question->type->isAutoGradable()) {
            return GradeResult::awaitingHuman($pointsPossible);
        }

        $fraction = $this->fractionCorrect($question, $answer);

        // Partial credit only where the type allows it. Elsewhere a 0.75 would
        // silently become a pass on a question the learner got wrong.
        if (!$question->type->supportsPartialCredit() && $fraction < 1.0) {
            $fraction = 0.0;
        }

        return new GradeResult(
            pointsEarned: round($pointsPossible * $fraction, 2),
            pointsPossible: $pointsPossible,
            isCorrect: $fraction >= 1.0,
            needsHumanGrading: false,
        );
    }

    /**
     * How much of the question the learner got right, from 0 to 1.
     *
     * @param array<string, mixed> $answer
     */
    private function fractionCorrect(Question $question, array $answer): float
    {
        return match ($question->type) {
            QuestionType::MultipleChoiceSingle,
            QuestionType::TrueFalse => $this->singleChoice($question, $answer),

            QuestionType::MultipleChoiceMultiple => $this->multipleChoice($question, $answer),

            QuestionType::Ordering => $this->ordering($question, $answer),

            QuestionType::Matching => $this->matching($question, $answer),

            QuestionType::FillInBlanks => $this->fillInBlanks($question, $answer),

            QuestionType::ShortAnswer => $this->shortAnswer($question, $answer),

            QuestionType::Numeric => $this->numeric($question, $answer),

            // Hotspot is auto-gradable in principle but needs coordinate
            // regions the schema does not carry yet; until it does, marking it
            // wrong automatically would be worse than asking a human.
            QuestionType::Hotspot => 0.0,

            QuestionType::LongAnswer, QuestionType::FileUpload => 0.0,
        };
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function singleChoice(Question $question, array $answer): float
    {
        $selected = $this->ids($answer['optionIds'] ?? null);

        // Selecting two options for a single-choice question is not a partial
        // answer; it is not an answer to the question asked.
        if (count($selected) !== 1) {
            return 0.0;
        }

        foreach ($question->options as $option) {
            if ($option->id === $selected[0]) {
                return $option->isCorrect ? 1.0 : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Every right option, and no wrong ones.
     *
     * Credit is the share of correct options found, less the share of wrong
     * ones chosen, floored at zero. Without the penalty, selecting everything
     * scores full marks on every multiple-answer question ever written.
     *
     * @param array<string, mixed> $answer
     */
    private function multipleChoice(Question $question, array $answer): float
    {
        $selected = $this->ids($answer['optionIds'] ?? null);

        $correctIds = [];
        $wrongIds = [];

        foreach ($question->options as $option) {
            if ($option->isCorrect) {
                $correctIds[] = $option->id;
            } else {
                $wrongIds[] = $option->id;
            }
        }

        if ($correctIds === []) {
            return 0.0;
        }

        $hits = count(array_intersect($selected, $correctIds));
        $misses = count(array_intersect($selected, $wrongIds));

        $fraction = ($hits - $misses) / count($correctIds);

        return max(0.0, min(1.0, $fraction));
    }

    /**
     * Credit per item left in its correct position.
     *
     * @param array<string, mixed> $answer
     */
    private function ordering(Question $question, array $answer): float
    {
        $submitted = $this->ids($answer['optionIds'] ?? null);

        $expected = $question->options;
        usort($expected, static fn (QuestionOption $a, QuestionOption $b): int => $a->sortOrder <=> $b->sortOrder);

        if ($expected === [] || count($submitted) !== count($expected)) {
            return 0.0;
        }

        $correct = 0;

        foreach ($expected as $position => $option) {
            if (($submitted[$position] ?? null) === $option->id) {
                $correct++;
            }
        }

        return $correct / count($expected);
    }

    /**
     * Credit per pair matched to the right term.
     *
     * @param array<string, mixed> $answer
     */
    private function matching(Question $question, array $answer): float
    {
        $pairs = $answer['pairs'] ?? null;

        if (!is_array($pairs) || $question->options === []) {
            return 0.0;
        }

        $correct = 0;
        $total = 0;

        foreach ($question->options as $option) {
            if ($option->matchText === null) {
                continue;
            }

            $total++;
            $given = $pairs[$option->id] ?? null;

            if (is_string($given) && $this->same($given, $option->matchText)) {
                $correct++;
            }
        }

        return $total === 0 ? 0.0 : $correct / $total;
    }

    /**
     * Credit per blank filled correctly, in order.
     *
     * The accepted answers are the options, read positionally: the first
     * option answers the first blank. Any option whose text matches is
     * accepted for that position, so an author can offer alternatives by
     * giving several options the same `sortOrder`.
     *
     * @param array<string, mixed> $answer
     */
    private function fillInBlanks(Question $question, array $answer): float
    {
        $given = $answer['texts'] ?? null;

        if (!is_array($given) || $question->options === []) {
            return 0.0;
        }

        /** @var array<int, list<string>> $acceptedByPosition */
        $acceptedByPosition = [];

        foreach ($question->options as $option) {
            $acceptedByPosition[$option->sortOrder][] = $option->text;
        }

        ksort($acceptedByPosition);
        $positions = array_values($acceptedByPosition);

        if ($positions === []) {
            return 0.0;
        }

        $correct = 0;
        $givenValues = array_values($given);

        foreach ($positions as $index => $accepted) {
            $value = $givenValues[$index] ?? null;

            if (!is_string($value)) {
                continue;
            }

            foreach ($accepted as $candidate) {
                if ($this->same($value, $candidate)) {
                    $correct++;
                    break;
                }
            }
        }

        return $correct / count($positions);
    }

    /**
     * Matched against the model answer, or against any option marked correct.
     *
     * Comparison ignores case and surrounding space. It does not attempt
     * anything cleverer: a near-miss marked wrong is a complaint, but a wrong
     * answer marked right because of fuzzy matching is a certificate issued in
     * error — and the legacy code's `similar_text()` is exactly that mistake.
     *
     * @param array<string, mixed> $answer
     */
    private function shortAnswer(Question $question, array $answer): float
    {
        $given = $answer['text'] ?? null;

        if (!is_string($given) || trim($given) === '') {
            return 0.0;
        }

        $accepted = [];

        if ($question->modelAnswer !== null) {
            $accepted[] = $question->modelAnswer;
        }

        foreach ($question->options as $option) {
            if ($option->isCorrect) {
                $accepted[] = $option->text;
            }
        }

        // Nothing to compare against: ask a human rather than mark it wrong.
        if ($accepted === []) {
            return 0.0;
        }

        foreach ($accepted as $candidate) {
            if ($this->same($given, $candidate)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /**
     * Within tolerance of the expected value.
     *
     * A tolerance of zero means exact, which is why it is not conflated with
     * "no tolerance set".
     *
     * @param array<string, mixed> $answer
     */
    private function numeric(Question $question, array $answer): float
    {
        $given = $answer['value'] ?? null;

        if ($question->numericAnswer === null || !is_numeric($given)) {
            return 0.0;
        }

        $tolerance = $question->numericTolerance ?? 0.0;
        $difference = abs((float) $given - $question->numericAnswer);

        // Binary floating point cannot represent most decimals exactly, so a
        // learner answering 3.13 to "3.14 ± 0.01" produces a difference of
        // 0.010000000000000231 and would be marked wrong for being exactly
        // right. The allowance is scaled to the magnitudes involved so it
        // stays negligible for large numbers, and it is far smaller than any
        // tolerance an author would set.
        $epsilon = 1e-9 * max(1.0, abs($question->numericAnswer), abs((float) $given));

        return $difference <= $tolerance + $epsilon ? 1.0 : 0.0;
    }

    /**
     * Case- and whitespace-insensitive comparison.
     *
     * Internal runs of whitespace are collapsed too, so "New  York" and
     * "New York" agree — a difference no marker would count against anyone.
     */
    private function same(string $a, string $b): bool
    {
        return $this->normalise($a) === $this->normalise($b);
    }

    private function normalise(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($collapsed);
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
