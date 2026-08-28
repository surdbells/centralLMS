<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What learners thought of a learning path, and who checked it.
 *
 * A review is a rating and some words from somebody who has actually been on
 * the path. That last part is the constraint that matters: the review names
 * the enrolment it came from, and one enrolment leaves one review. Without it,
 * a rating is a number anybody can post as often as they like, and the average
 * beside a path means nothing.
 *
 * **Moderation is a separate state from publication.** A review can be
 * approved and later hidden, or pending and never seen. Collapsing the two
 * into one status is how a rejected review comes back at the next edit.
 *
 * The average and the count are not stored on `learning_paths`. They change
 * whenever anybody reviews, un-reviews or is moderated, and a stored average
 * is wrong from the first of those and never right again.
 */
final class Version20260908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Learner reviews of learning paths, and their moderation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_reviews (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                path_id       UUID NOT NULL REFERENCES learning_paths (id) ON DELETE CASCADE,
                user_id       UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                -- The enrolment this came from. A review by somebody who was
                -- never on the path is an opinion, not a review.
                enrollment_id UUID NOT NULL
                    REFERENCES learning_path_enrollments (id) ON DELETE CASCADE,

                rating        SMALLINT NOT NULL,
                title         TEXT,
                review_text   TEXT,
                -- The three optional dimensions. Null means "did not say",
                -- which is different from a middling score.
                content_rating         SMALLINT,
                difficulty_rating      SMALLINT,
                practical_value_rating SMALLINT,

                -- Whether it has been looked at. Separate from `is_published`
                -- so a review can be approved and later hidden without
                -- becoming "pending" again.
                moderation_status TEXT NOT NULL DEFAULT 'pending',
                moderated_by      UUID REFERENCES users (id) ON DELETE SET NULL,
                moderated_at      TIMESTAMPTZ,
                rejection_reason  TEXT,
                is_published      BOOLEAN NOT NULL DEFAULT FALSE,

                -- The author of the path answering, in public.
                response_text TEXT,
                response_by   UUID REFERENCES users (id) ON DELETE SET NULL,
                response_at   TIMESTAMPTZ,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT lp_reviews_rating_range CHECK (rating BETWEEN 1 AND 5),
                CONSTRAINT lp_reviews_content_range
                    CHECK (content_rating IS NULL OR content_rating BETWEEN 1 AND 5),
                CONSTRAINT lp_reviews_difficulty_range
                    CHECK (difficulty_rating IS NULL OR difficulty_rating BETWEEN 1 AND 5),
                CONSTRAINT lp_reviews_practical_range
                    CHECK (practical_value_rating IS NULL OR practical_value_rating BETWEEN 1 AND 5),
                CONSTRAINT lp_reviews_moderation_known
                    CHECK (moderation_status IN ('pending', 'approved', 'rejected')),
                -- A rejection needs a reason. "Rejected, no reason given" is
                -- the message the learner would receive.
                CONSTRAINT lp_reviews_rejection_has_reason
                    CHECK (moderation_status <> 'rejected' OR BTRIM(COALESCE(rejection_reason, '')) <> ''),
                -- Nothing unapproved is ever published.
                CONSTRAINT lp_reviews_published_is_approved
                    CHECK (NOT is_published OR moderation_status = 'approved'),
                -- A response without words is not a response.
                CONSTRAINT lp_reviews_response_consistent
                    CHECK ((response_at IS NULL) = (BTRIM(COALESCE(response_text, '')) = ''))
            )
        SQL);

        // One review per enrolment. Somebody who takes the path twice may
        // review it twice; somebody who takes it once may not review it
        // forty times, which is what makes the average mean anything.
        $this->addSql(
            'CREATE UNIQUE INDEX lp_reviews_enrolment_uniq
             ON learning_path_reviews (enrollment_id)
             WHERE deleted_at IS NULL',
        );

        // What a path's page shows: the published ones, newest first.
        $this->addSql(
            'CREATE INDEX lp_reviews_published_idx
             ON learning_path_reviews (path_id, created_at DESC)
             WHERE is_published AND deleted_at IS NULL',
        );

        // The moderation queue: everything waiting, oldest first, across
        // every path. Partial, so it stays small however many reviews exist.
        $this->addSql(
            "CREATE INDEX lp_reviews_pending_idx
             ON learning_path_reviews (tenant_id, created_at)
             WHERE moderation_status = 'pending' AND deleted_at IS NULL",
        );

        // Who found a review helpful, and who reported one. Rows rather than
        // counters: a counter cannot stop the same person voting twice, and
        // "who reported this" is the first question a moderator asks.
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_review_votes (
                review_id  UUID NOT NULL REFERENCES learning_path_reviews (id) ON DELETE CASCADE,
                user_id    UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                kind       TEXT NOT NULL,
                reason     TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (review_id, user_id, kind),

                CONSTRAINT lp_review_votes_kind_known CHECK (kind IN ('helpful', 'report'))
            )
        SQL);

        $this->addSql(
            'CREATE INDEX lp_review_votes_review_idx
             ON learning_path_review_votes (review_id, kind)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS learning_path_review_votes');
        $this->addSql('DROP TABLE IF EXISTS learning_path_reviews');
    }
}
