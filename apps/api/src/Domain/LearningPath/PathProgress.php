<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * Where somebody has got to on a path.
 *
 * Pure, and the centre of the module. It is handed the items in order and the
 * set of them that are finished, and works out what is open, what is locked,
 * how far through the learner is and whether the path is done.
 *
 * The legacy stored all of that. `learning_path_enrollments` carried
 * `progress_percentage`, `items_completed`, `items_total`, `current_item_id`,
 * `total_points_earned` and `avg_assessment_score`; `learning_path_items`
 * carried a `status` per learner in a table of its own. The single path in the
 * database claims two items, has three item rows with none active, and every
 * one of its thirty-three enrolments says `items_total = 4`. Three stored
 * answers to one question, and this class is the question asked directly.
 */
final readonly class PathProgress
{
    /**
     * @param list<ItemState> $items in path order
     */
    private function __construct(
        public array $items,
        public int $total,
        public int $required,
        public int $completed,
        public int $requiredCompleted,
        public float $percent,
        public bool $isComplete,
        public ?string $currentItemId,
        public int $pointsEarned,
        public int $pointsAvailable,
    ) {
    }

    /**
     * @param list<PathItem>            $items      in sort order
     * @param array<string, string>     $finishedAt item id => when, for the ones done
     * @param array<string, int|null>   $sectionMinimums section id => how many of its
     *                                                   items must be done, null for all
     */
    public static function of(
        array $items,
        array $finishedAt,
        PathPacing $pacing,
        CompletionRule $rule,
        float $threshold = 100.0,
        array $sectionMinimums = [],
    ): self {
        $states          = [];
        $unlocked        = true;
        $sectionsDone    = self::sectionsSatisfied($items, $finishedAt, $sectionMinimums);
        $seenSections    = [];
        $currentItemId   = null;

        foreach ($items as $item) {
            $id   = (string) $item->id;
            $done = array_key_exists($id, $finishedAt);

            $available = match ($pacing) {
                // Everything is open at once.
                PathPacing::Flexible   => true,
                // Open until the first unfinished one, which is then the last
                // open item.
                PathPacing::Sequential => $unlocked,
                // A section opens once every section before it is satisfied.
                PathPacing::Milestone  => self::sectionIsOpen($item, $items, $sectionsDone, $seenSections),
            };

            if ($pacing === PathPacing::Sequential && !$done) {
                // Everything after the first unfinished item is locked. Note
                // the order: this item is still available, the next is not.
                $unlocked = false;
            }

            if ($currentItemId === null && !$done && $available) {
                $currentItemId = $id;
            }

            $states[] = new ItemState(
                item: $item,
                isComplete: $done,
                isAvailable: $available || $done,
                completedAt: $finishedAt[$id] ?? null,
            );
        }

        $total             = count($items);
        $required          = count(array_filter($items, static fn (PathItem $i): bool => $i->isRequired));
        $completed         = count($finishedAt);
        $requiredCompleted = count(array_filter(
            $items,
            static fn (PathItem $i): bool => $i->isRequired && array_key_exists((string) $i->id, $finishedAt),
        ));

        // Measured over the required items, because the optional ones are
        // optional: somebody who has done everything they must have done is
        // finished, whatever they skipped.
        $percent = $required === 0
            ? ($total === 0 ? 0.0 : round(100 * $completed / $total, 1))
            : round(100 * $requiredCompleted / $required, 1);

        $points          = 0;
        $pointsAvailable = 0;

        foreach ($items as $item) {
            $pointsAvailable += $item->pointsValue;

            if (array_key_exists((string) $item->id, $finishedAt)) {
                $points += $item->pointsValue;
            }
        }

        return new self(
            items: $states,
            total: $total,
            required: $required,
            completed: $completed,
            requiredCompleted: $requiredCompleted,
            percent: $percent,
            isComplete: self::finished($rule, $total, $required, $requiredCompleted, $percent, $threshold),
            currentItemId: $currentItemId,
            pointsEarned: $points,
            pointsAvailable: $pointsAvailable,
        );
    }

    /**
     * Whether the path counts as done.
     */
    private static function finished(
        CompletionRule $rule,
        int $total,
        int $required,
        int $requiredCompleted,
        float $percent,
        float $threshold,
    ): bool {
        // An empty path is not a finished path. Thirty-three people were
        // enrolled on one whose items had all been deactivated; telling them
        // they had completed it would have been worse than telling them
        // nothing.
        if ($total === 0) {
            return false;
        }

        return match ($rule) {
            CompletionRule::AllRequiredItems => $required > 0 && $requiredCompleted === $required,
            CompletionRule::Percentage       => $percent >= $threshold,
        };
    }

    /**
     * Which sections have had enough of their items finished.
     *
     * @param  list<PathItem>          $items
     * @param  array<string, string>   $finishedAt
     * @param  array<string, int|null> $minimums
     * @return array<string, bool>
     */
    private static function sectionsSatisfied(array $items, array $finishedAt, array $minimums): array
    {
        $bySection = [];

        foreach ($items as $item) {
            if ($item->sectionId === null) {
                continue;
            }

            $bySection[$item->sectionId] ??= ['required' => 0, 'done' => 0, 'requiredDone' => 0];
            $done = array_key_exists((string) $item->id, $finishedAt);

            if ($done) {
                $bySection[$item->sectionId]['done']++;
            }

            if ($item->isRequired) {
                $bySection[$item->sectionId]['required']++;

                if ($done) {
                    $bySection[$item->sectionId]['requiredDone']++;
                }
            }
        }

        $satisfied = [];

        foreach ($bySection as $sectionId => $counts) {
            $minimum = $minimums[$sectionId] ?? null;

            $satisfied[$sectionId] = $minimum === null
                ? $counts['requiredDone'] >= $counts['required']
                : $counts['done'] >= $minimum;
        }

        return $satisfied;
    }

    /**
     * Whether the section this item is in has opened.
     *
     * @param list<PathItem>           $items
     * @param array<string, bool>      $sectionsDone
     * @param array<string, bool>      $seenSections carried between calls
     */
    private static function sectionIsOpen(
        PathItem $item,
        array $items,
        array $sectionsDone,
        array &$seenSections,
    ): bool {
        // An item outside any section is not gated by one.
        if ($item->sectionId === null) {
            return true;
        }

        foreach ($items as $earlier) {
            if ($earlier->sectionId === $item->sectionId) {
                break;
            }

            if ($earlier->sectionId === null) {
                continue;
            }

            // Every earlier section has to be satisfied. Only the milestone
            // ones gate; an ordinary section is a heading.
            if ($earlier->isMilestoneSection && ($sectionsDone[$earlier->sectionId] ?? false) === false) {
                return false;
            }
        }

        $seenSections[$item->sectionId] = true;

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total'             => $this->total,
            'required'          => $this->required,
            'completed'         => $this->completed,
            'requiredCompleted' => $this->requiredCompleted,
            'percent'           => $this->percent,
            'isComplete'        => $this->isComplete,
            'currentItemId'     => $this->currentItemId,
            'pointsEarned'      => $this->pointsEarned,
            'pointsAvailable'   => $this->pointsAvailable,
            'items'             => array_map(static fn (ItemState $s): array => $s->toArray(), $this->items),
        ];
    }
}
