<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tags on bank questions.
 *
 * A join table rather than a `text[]` column: it matches the composite-FK
 * convention the rest of the assessment schema uses (`question_options` keys on
 * `(question_id, tenant_id)`), and `DISTINCT tag` / `EXISTS` filters read
 * cleanly off it. Tags are wholesale-replaced with the question, exactly as its
 * options are, so there is no per-tag identity to preserve.
 */
final class Version20260919000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tags on bank questions (question_tags join table)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE question_tags (
                tenant_id   uuid        NOT NULL,
                question_id uuid        NOT NULL,
                tag         text        NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT question_tags_pkey PRIMARY KEY (question_id, tag),
                CONSTRAINT question_tags_tag_not_blank CHECK (length(btrim(tag)) > 0),
                CONSTRAINT question_tags_question_fk
                    FOREIGN KEY (question_id, tenant_id)
                    REFERENCES questions (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT question_tags_tenant_id_fkey
                    FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX question_tags_tag_idx ON question_tags (tenant_id, tag)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS question_tags');
    }
}
