<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What learners thought of a course — the 5-star evaluation.
 *
 * The legacy `course_reviews` table was never ported; `courses.rating_average`
 * and `rating_count` were left as a cache of a source of truth that did not
 * exist, so nothing wrote them. This is that source of truth, modelled on
 * {@see Version20260908090000} (learning-path reviews): a rating and some words
 * from somebody who was actually enrolled, one review per enrolment.
 *
 * Course ratings publish on submission (an evaluation score is the point, and a
 * moderation queue would simply mean no satisfaction data arrives); the
 * moderation columns exist so a review with objectionable words can later be
 * hidden without being deleted. The average and count are recomputed onto
 * `courses` from the published reviews, never trusted as stored state.
 */
final class Version20261110000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Learner course reviews (5-star evaluation) — the dropped source table, restored';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE course_reviews (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                course_id     UUID NOT NULL REFERENCES courses (id) ON DELETE CASCADE,
                user_id       UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                -- The enrolment this came from. A review by somebody who was
                -- never on the course is an opinion, not an evaluation.
                enrollment_id UUID NOT NULL REFERENCES enrollments (id) ON DELETE CASCADE,

                rating        SMALLINT NOT NULL,
                title         TEXT,
                review_text   TEXT,
                -- Optional sub-scores. Null means "did not say", not a middling
                -- score.
                content_rating         SMALLINT,
                difficulty_rating      SMALLINT,
                practical_value_rating SMALLINT,

                -- Published on submission; the moderation columns let a review
                -- be hidden later without losing the rating behind it.
                moderation_status TEXT NOT NULL DEFAULT 'approved',
                moderated_by      UUID REFERENCES users (id) ON DELETE SET NULL,
                moderated_at      TIMESTAMPTZ,
                rejection_reason  TEXT,
                is_published      BOOLEAN NOT NULL DEFAULT TRUE,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT course_reviews_rating_range CHECK (rating BETWEEN 1 AND 5),
                CONSTRAINT course_reviews_content_range
                    CHECK (content_rating IS NULL OR content_rating BETWEEN 1 AND 5),
                CONSTRAINT course_reviews_difficulty_range
                    CHECK (difficulty_rating IS NULL OR difficulty_rating BETWEEN 1 AND 5),
                CONSTRAINT course_reviews_practical_range
                    CHECK (practical_value_rating IS NULL OR practical_value_rating BETWEEN 1 AND 5),
                CONSTRAINT course_reviews_moderation_known
                    CHECK (moderation_status IN ('pending', 'approved', 'rejected')),
                CONSTRAINT course_reviews_rejection_has_reason
                    CHECK (moderation_status <> 'rejected' OR BTRIM(COALESCE(rejection_reason, '')) <> ''),
                CONSTRAINT course_reviews_published_is_approved
                    CHECK (NOT is_published OR moderation_status = 'approved')
            )
        SQL);

        // One review per enrolment, so the average means something. Re-taking
        // the course (a fresh enrolment) allows a fresh review.
        $this->addSql(
            'CREATE UNIQUE INDEX course_reviews_enrolment_uniq
             ON course_reviews (enrollment_id)
             WHERE deleted_at IS NULL',
        );

        // What a course page shows: the published ones, newest first.
        $this->addSql(
            'CREATE INDEX course_reviews_published_idx
             ON course_reviews (course_id, created_at DESC)
             WHERE is_published AND deleted_at IS NULL',
        );

        // Satisfaction analytics read every published review by tenant/time.
        $this->addSql(
            'CREATE INDEX course_reviews_tenant_time_idx
             ON course_reviews (tenant_id, created_at)
             WHERE is_published AND deleted_at IS NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS course_reviews');
    }
}
