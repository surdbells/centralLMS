<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let an auto-submitted attempt carry its score.
 *
 * `attempts_graded_consistent` paired the result columns with the status: a
 * 'graded' row must have a full result, and any other status must have none.
 * That read 'auto_submitted' as a scoreless state, but it is not one -- it is
 * the closing reason "the timer ran out", orthogonal to whether the paper is
 * marked. When the clock stops on an attempt whose questions all grade
 * automatically, submit() scores it and then records the auto-close, leaving a
 * fully scored row at status 'auto_submitted'. The old constraint rejected
 * exactly that row, so a learner who let an auto-marked exam expire hit a 500
 * on submit instead of seeing their result.
 *
 * The corrected invariant keeps the real guarantee -- 'graded' always has a
 * result, and a result never appears on an unresolved sitting -- while letting
 * 'auto_submitted' sit on either side: scored, when the timer closed an
 * auto-marked paper; or unscored, which is where a migrated legacy 'timed_out'
 * attempt and a timer-close still awaiting manual marks both fall. It is a
 * strict widening of the old rule, so every existing row still satisfies it.
 */
final class Version20260918000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow a scored auto-submitted attempt (fix expired auto-graded submit)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attempts DROP CONSTRAINT IF EXISTS attempts_graded_consistent');
        $this->addSql(<<<'SQL'
            ALTER TABLE attempts ADD CONSTRAINT attempts_graded_consistent CHECK (
                -- No result recorded. Any status but 'graded' may sit here: an
                -- open or abandoned sitting, one submitted and awaiting marks,
                -- or a timer-close (auto_submitted) not yet auto-graded.
                (passed IS NULL AND status <> 'graded')
                OR
                -- A full result, and only the two resolved states carry one: a
                -- normally graded attempt, or one the timer closed after it had
                -- already been auto-graded.
                (passed IS NOT NULL AND raw_score IS NOT NULL AND percentage IS NOT NULL
                    AND graded_at IS NOT NULL AND status IN ('graded', 'auto_submitted'))
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Restore the original, stricter pairing.
        $this->addSql('ALTER TABLE attempts DROP CONSTRAINT IF EXISTS attempts_graded_consistent');
        $this->addSql(<<<'SQL'
            ALTER TABLE attempts ADD CONSTRAINT attempts_graded_consistent CHECK (
                (status = 'graded' AND graded_at IS NOT NULL AND passed IS NOT NULL
                    AND raw_score IS NOT NULL AND percentage IS NOT NULL)
                OR (status <> 'graded' AND passed IS NULL)
            )
        SQL);
    }
}
