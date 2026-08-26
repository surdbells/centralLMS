<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Certificates: templates, issuance, and public verification.
 *
 * Three modules already promise these — `courses.issues_certificate`,
 * `live_trainings.issues_certificate` and the assessment core's
 * `certificate_template_id` — and nothing could deliver one. As with
 * proctoring and ILT, the legacy tables show a feature that was designed and
 * never finished: `certificate_templates` holds 3 rows and
 * `proc_certificate_templates` 1, while **`certificates` and
 * `proc_certificates` are both empty**. Not one certificate has ever been
 * issued.
 *
 * **One table, three sources.** The legacy had two families describing the
 * same object — one bound to a course, one to an assessment attempt. They
 * merge here, with `source` naming what earned it and a check constraint
 * making exactly one of `course_id`, `training_id` and `assessment_id`
 * present. A certificate is a certificate whatever produced it.
 *
 * **The learner's name and the course title are copied onto the row, on
 * purpose.** This is the one place in the schema where denormalisation is the
 * correct answer rather than a shortcut: a certificate is a record of a
 * moment. If someone marries and changes their name, or an author retitles a
 * course, the certificate issued last year must still read as it did when it
 * was issued — and an employer verifying it must see what the holder was
 * given, not what the row says today.
 *
 * Dropped from the legacy shapes:
 *
 *  - **`is_active` and `expired`** — both duplicate `status`, which already
 *    has `active`, `revoked`, `expired` and `superseded`, and `expired` also
 *    duplicates `valid_until`. Three representations of one fact is the shape
 *    that produced 16 contradictory enrolment rows.
 *  - **`download_count`, `verification_count`, `last_downloaded_at` and
 *    `last_verified_at`** — counters beside the log that records the same
 *    events. They are `COUNT`s over `certificate_events`.
 *  - **`proc_certificate_templates.usage_count`**, maintained by an
 *    `AFTER INSERT` trigger. A trigger-updated counter is the least visible
 *    version of this mistake: nothing in the application code shows that the
 *    number exists, so nothing keeps it honest.
 *  - **`certificate_reprint_history` and `certificate_verification_log`**
 *    become one `certificate_events` table. A reprint and a verification are
 *    both "something happened to this certificate", they were queried
 *    together, and two tables meant two places to look.
 *
 * Not built: `proc_certificate_fields`, which positions each field on the page
 * by absolute millimetre coordinates for a drag-and-drop designer. The HTML
 * and CSS template covers rendering; the designer is a product decision, not a
 * porting one, and building it because a table existed would be guessing.
 */
final class Version20260827140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Certificates: templates, issuance, revocation and public verification.';
    }

    public function up(Schema $schema): void
    {
        $this->templates();
        $this->certificates();
        $this->events();
    }

    private function templates(): void
    {
        $this->addSql("
            CREATE TABLE certificate_templates (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                name        TEXT NOT NULL,
                description TEXT,

                -- The template itself. Placeholders are {{learner_name}} and
                -- friends; CertificateRenderer decides which are known.
                body_html TEXT NOT NULL,
                styles_css TEXT,

                orientation TEXT NOT NULL DEFAULT 'landscape',
                page_size   TEXT NOT NULL DEFAULT 'A4',

                is_default BOOLEAN NOT NULL DEFAULT FALSE,
                is_active  BOOLEAN NOT NULL DEFAULT TRUE,

                created_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT certificate_templates_name_not_blank CHECK (length(btrim(name)) > 0),
                CONSTRAINT certificate_templates_body_not_blank CHECK (length(btrim(body_html)) > 0),
                CONSTRAINT certificate_templates_orientation_check
                    CHECK (orientation IN ('portrait', 'landscape')),
                CONSTRAINT certificate_templates_page_size_check
                    CHECK (page_size IN ('A4', 'A5', 'Letter', 'Legal')),
                CONSTRAINT certificate_templates_id_tenant_uniq UNIQUE (id, tenant_id)
            )
        ");

        // One default per tenant, so \"which template?\" always has an answer
        // and never two.
        $this->addSql('
            CREATE UNIQUE INDEX certificate_templates_one_default
                ON certificate_templates (tenant_id) WHERE is_default AND deleted_at IS NULL
        ');
        $this->addSql('
            CREATE UNIQUE INDEX certificate_templates_tenant_name_uniq
                ON certificate_templates (tenant_id, lower(name)) WHERE deleted_at IS NULL
        ');
        $this->addSql('
            CREATE UNIQUE INDEX certificate_templates_legacy_uniq
                ON certificate_templates (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    private function certificates(): void
    {
        $this->addSql("
            CREATE TABLE certificates (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                -- Printed on the certificate and quoted by the holder.
                number TEXT NOT NULL,

                -- Short enough to read down a phone line; the hash is what a
                -- verification URL carries, so the number alone cannot be
                -- enumerated into somebody else's record.
                verification_code TEXT NOT NULL,
                verification_hash TEXT NOT NULL,

                template_id UUID,

                -- What earned it. Exactly one of the three ids below is set,
                -- and the check constraint keeps it that way.
                source        TEXT NOT NULL,
                course_id     UUID,
                training_id   UUID,
                assessment_id UUID,
                attempt_id    UUID,
                enrollment_id UUID,

                user_id UUID NOT NULL,

                -- Copied at issue and never updated. See the class docblock:
                -- a certificate says what it said, not what the row says now.
                learner_name  TEXT NOT NULL,
                learner_email TEXT,
                title         TEXT NOT NULL,
                score         NUMERIC(5,2),
                percentage    NUMERIC(5,2),
                grade         TEXT,

                -- Anything a template wants that the columns do not carry.
                custom_data JSONB NOT NULL DEFAULT '{}'::jsonb,

                pdf_url          TEXT,
                pdf_generated_at TIMESTAMPTZ,

                issued_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
                valid_until DATE,

                status TEXT NOT NULL DEFAULT 'active',
                revoked_at        TIMESTAMPTZ,
                revoked_by        UUID REFERENCES users (id) ON DELETE SET NULL,
                revocation_reason TEXT,

                issued_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,

                CONSTRAINT certificates_number_not_blank CHECK (length(btrim(number)) > 0),
                CONSTRAINT certificates_learner_not_blank CHECK (length(btrim(learner_name)) > 0),
                CONSTRAINT certificates_title_not_blank CHECK (length(btrim(title)) > 0),
                CONSTRAINT certificates_source_check
                    CHECK (source IN ('course', 'training', 'assessment')),
                CONSTRAINT certificates_status_check
                    CHECK (status IN ('active', 'revoked', 'expired', 'superseded')),
                CONSTRAINT certificates_percentage_check
                    CHECK (percentage IS NULL OR percentage BETWEEN 0 AND 100),
                CONSTRAINT certificates_validity_check
                    CHECK (valid_until IS NULL OR valid_until >= valid_from),
                CONSTRAINT certificates_custom_data_object
                    CHECK (jsonb_typeof(custom_data) = 'object'),

                -- Exactly one source id, matching `source`. A certificate that
                -- names both a course and an assessment cannot say what it is
                -- for, and one that names neither cannot be traced at all.
                CONSTRAINT certificates_source_consistent CHECK (
                    (source = 'course'     AND course_id IS NOT NULL
                        AND training_id IS NULL AND assessment_id IS NULL)
                    OR (source = 'training' AND training_id IS NOT NULL
                        AND course_id IS NULL AND assessment_id IS NULL)
                    OR (source = 'assessment' AND assessment_id IS NOT NULL
                        AND course_id IS NULL AND training_id IS NULL)
                ),

                -- A revocation must say when. Without it a revoked certificate
                -- cannot be defended if the holder disputes it.
                CONSTRAINT certificates_revocation_consistent CHECK (
                    (status = 'revoked' AND revoked_at IS NOT NULL)
                    OR (status <> 'revoked' AND revoked_at IS NULL)
                ),

                CONSTRAINT certificates_template_fk
                    FOREIGN KEY (template_id, tenant_id)
                    REFERENCES certificate_templates (id, tenant_id) ON DELETE SET NULL,
                CONSTRAINT certificates_user_fk
                    FOREIGN KEY (user_id, tenant_id)
                    REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT certificates_course_fk
                    FOREIGN KEY (course_id, tenant_id)
                    REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT certificates_training_fk
                    FOREIGN KEY (training_id, tenant_id)
                    REFERENCES live_trainings (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT certificates_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id)
                    REFERENCES assessments (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT certificates_id_tenant_uniq UNIQUE (id, tenant_id)
            )
        ");

        // Both are quoted by holders and typed by verifiers, so both must
        // resolve to exactly one certificate across the whole platform — not
        // merely within a tenant, since a verifier has no tenant.
        $this->addSql('CREATE UNIQUE INDEX certificates_number_uniq ON certificates (number)');
        $this->addSql('CREATE UNIQUE INDEX certificates_hash_uniq ON certificates (verification_hash)');
        $this->addSql('CREATE UNIQUE INDEX certificates_code_uniq ON certificates (upper(verification_code))');

        // One live certificate per learner per thing. Re-issuing supersedes
        // rather than adding a second, or a learner ends up holding two
        // certificates for one course with different scores on them.
        $this->addSql("
            CREATE UNIQUE INDEX certificates_course_uniq
                ON certificates (course_id, user_id)
                WHERE course_id IS NOT NULL AND status = 'active'
        ");
        $this->addSql("
            CREATE UNIQUE INDEX certificates_training_uniq
                ON certificates (training_id, user_id)
                WHERE training_id IS NOT NULL AND status = 'active'
        ");
        $this->addSql("
            CREATE UNIQUE INDEX certificates_assessment_uniq
                ON certificates (assessment_id, user_id)
                WHERE assessment_id IS NOT NULL AND status = 'active'
        ");

        $this->addSql('CREATE INDEX certificates_user_idx ON certificates (tenant_id, user_id, issued_at DESC)');
        $this->addSql("
            CREATE INDEX certificates_expiring_idx
                ON certificates (tenant_id, valid_until)
                WHERE valid_until IS NOT NULL AND status = 'active'
        ");
        $this->addSql('
            CREATE UNIQUE INDEX certificates_legacy_uniq
                ON certificates (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    /**
     * Everything that has happened to a certificate.
     *
     * Replaces `certificate_reprint_history` and
     * `certificate_verification_log`, and is where the counters the legacy
     * kept on the row are computed from.
     */
    private function events(): void
    {
        $this->addSql("
            CREATE TABLE certificate_events (
                id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id      BIGINT,
                tenant_id      UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                certificate_id UUID NOT NULL,

                action TEXT NOT NULL,

                -- Who did it. NULL for a public verification, which is the
                -- normal case: an employer checking a certificate has no
                -- account here and should not need one.
                actor_id UUID REFERENCES users (id) ON DELETE SET NULL,

                -- What a public verifier told us about themselves. Entirely
                -- self-reported, so it is evidence of a check happening rather
                -- than of who made it.
                verifier_name    TEXT,
                verifier_email   TEXT,
                verifier_company TEXT,

                -- Kept for abuse investigation only, and the retention policy
                -- for it belongs in the deployment, not here.
                ip_address INET,

                notes      TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT certificate_events_action_check CHECK (action IN (
                    'issued', 'downloaded', 'verified', 'revoked', 'reinstated', 'superseded'
                )),
                CONSTRAINT certificate_events_certificate_fk
                    FOREIGN KEY (certificate_id, tenant_id)
                    REFERENCES certificates (id, tenant_id) ON DELETE CASCADE
            )
        ");

        $this->addSql('
            CREATE INDEX certificate_events_certificate_idx
                ON certificate_events (certificate_id, created_at DESC)
        ');
        $this->addSql("
            CREATE INDEX certificate_events_verifications_idx
                ON certificate_events (tenant_id, created_at DESC) WHERE action = 'verified'
        ");
        $this->addSql('
            CREATE UNIQUE INDEX certificate_events_legacy_uniq
                ON certificate_events (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS certificate_events');
        $this->addSql('DROP TABLE IF EXISTS certificates');
        $this->addSql('DROP TABLE IF EXISTS certificate_templates');
    }
}
