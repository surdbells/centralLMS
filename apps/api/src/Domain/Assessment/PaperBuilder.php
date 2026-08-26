<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Decides which questions one attempt is dealt.
 *
 * Pure, like {@see Grader}: given the assessment's attached questions, its
 * sections and its rules, it returns the paper. The draw is seeded from the
 * attempt id, so the same attempt always produces the same paper — a learner
 * who reloads mid-sitting must not be dealt a fresh hand, and a paper that
 * changed between the draw and the marking would be unscoreable.
 *
 * Three modes:
 *
 *  - **manual** — every attached question, in the author's order.
 *  - **random** — N of them, drawn at random.
 *  - **pool**   — drawn per rule from banks, ignoring the attached list.
 *
 * Sections are honoured in the first two: a section marked `random`
 * contributes a sample of its own questions, so "two from each topic" works
 * without any rule at all.
 */
final class PaperBuilder
{
    /**
     * @param list<array{question: Question, pointsOverride: ?float, sortOrder: int, isRequired: bool, sectionId: ?string}> $attached
     * @param list<AssessmentSection>                                                                                      $sections
     * @param array<string, list<Question>>                                                                                $ruleDraws  rule id => the questions matching that rule, already narrowed
     * @param list<RandomRule>                                                                                             $rules
     *
     * @return list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}>
     */
    public function build(
        Assessment $assessment,
        string $attemptId,
        array $attached,
        array $sections = [],
        array $rules = [],
        array $ruleDraws = [],
    ): array {
        $seed = crc32($attemptId);

        return match ($assessment->questionSelection) {
            QuestionSelectionMode::Manual => $this->fromSections($attached, $sections, $seed),
            QuestionSelectionMode::Random => $this->sample(
                $this->fromSections($attached, $sections, $seed),
                $assessment->randomQuestionCount ?? count($attached),
                $seed,
            ),
            QuestionSelectionMode::Pool => $this->fromRules($rules, $ruleDraws, $seed),
        };
    }

    /**
     * Applies each section's own selection, then concatenates in order.
     *
     * Questions filed under no section come last, as one implicit group — an
     * author who sections half their paper should not find the rest silently
     * dropped.
     *
     * @param list<array{question: Question, pointsOverride: ?float, sortOrder: int, isRequired: bool, sectionId: ?string}> $attached
     * @param list<AssessmentSection>                                                                                      $sections
     *
     * @return list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}>
     */
    private function fromSections(array $attached, array $sections, int $seed): array
    {
        /** @var array<string, list<array{question: Question, pointsOverride: ?float, sortOrder: int, isRequired: bool, sectionId: ?string}>> $grouped */
        $grouped = [];
        $loose = [];

        foreach ($attached as $row) {
            if ($row['sectionId'] === null) {
                $loose[] = $row;
            } else {
                $grouped[$row['sectionId']][] = $row;
            }
        }

        usort($sections, static fn (AssessmentSection $a, AssessmentSection $b): int => $a->sortOrder <=> $b->sortOrder);

        $paper = [];
        $position = 0;

        foreach ($sections as $index => $section) {
            $rows = $grouped[$section->id] ?? [];

            if ($section->selectionType->isSample() && $section->randomCount !== null) {
                // Each section gets its own seed offset, or every section
                // would draw the same positions from its list.
                $rows = $this->take($rows, $section->randomCount, $seed + $index + 1);
            }

            foreach ($rows as $row) {
                $paper[] = $this->line($row, $section->id, $position++);
            }
        }

        foreach ($loose as $row) {
            $paper[] = $this->line($row, null, $position++);
        }

        return $paper;
    }

    /**
     * @param list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}> $paper
     *
     * @return list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}>
     */
    private function sample(array $paper, int $count, int $seed): array
    {
        $drawn = $this->take($paper, $count, $seed);

        // Renumbered so the learner sees 1..N with no gaps.
        $position = 0;

        return array_map(
            static function (array $row) use (&$position): array {
                $row['sortOrder'] = $position++;

                return $row;
            },
            $drawn,
        );
    }

    /**
     * @param list<RandomRule>              $rules
     * @param array<string, list<Question>> $ruleDraws
     *
     * @return list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}>
     */
    private function fromRules(array $rules, array $ruleDraws, int $seed): array
    {
        usort($rules, static fn (RandomRule $a, RandomRule $b): int => $a->sortOrder <=> $b->sortOrder);

        $paper = [];
        $position = 0;
        $seen = [];

        foreach ($rules as $index => $rule) {
            $candidates = $ruleDraws[$rule->id] ?? [];

            // A question already drawn by an earlier rule is not offered
            // twice: overlapping filters are easy to write by accident, and
            // asking the same question twice doubles its weight silently.
            $candidates = array_values(array_filter(
                $candidates,
                static fn (Question $q): bool => !isset($seen[$q->id]),
            ));

            foreach ($this->take($candidates, $rule->questionCount, $seed + $index + 1) as $question) {
                $seen[$question->id] = true;

                $paper[] = [
                    'question'       => $question,
                    'pointsPossible' => $rule->pointsPerQuestion,
                    'sectionId'      => $rule->sectionId,
                    'sortOrder'      => $position++,
                ];
            }
        }

        return $paper;
    }

    /**
     * Takes up to `$count` items, deterministically for a given seed.
     *
     * Fewer than asked for is returned as-is rather than padded: a rule that
     * cannot be satisfied should shorten the paper visibly, and publishing
     * already refuses an assessment whose rules do not add up.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function take(array $items, int $count, int $seed): array
    {
        if ($count >= count($items)) {
            return $items;
        }

        if ($count <= 0) {
            return [];
        }

        // Seeded so the same attempt always draws the same questions. The
        // global generator is restored afterwards, so nothing else inherits
        // a predictable sequence.
        mt_srand($seed);
        $keys = array_keys($items);
        shuffle($keys);
        mt_srand();

        $chosen = array_slice($keys, 0, $count);

        // Back into the author's order: a random *selection* should not also
        // be a random ordering unless the assessment asked for shuffling.
        sort($chosen);

        return array_values(array_map(static fn (int $k) => $items[$k], $chosen));
    }

    /**
     * @param array{question: Question, pointsOverride: ?float, sortOrder: int, isRequired: bool, sectionId: ?string} $row
     *
     * @return array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}
     */
    private function line(array $row, ?string $sectionId, int $position): array
    {
        return [
            'question'       => $row['question'],
            'pointsPossible' => $row['question']->pointsIn($row['pointsOverride']),
            'sectionId'      => $sectionId,
            'sortOrder'      => $position,
        ];
    }
}
