<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Central\Api\Domain\Certificate\DefaultCertificateTemplate;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every tenant without one a default certificate template.
 *
 * Provisioning created no template, so `findDefaultTemplate()` returned null
 * and rendering any certificate raised `template_missing`. Certificates were
 * being issued that nobody — the holder included — could open. New tenants get
 * one from {@see \Central\Api\Application\Console\ProvisionTenantCommand}; this
 * is for the ones already created.
 *
 * A tenant that has since made its own default is left alone.
 */
final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give tenants without one a default certificate template';
    }

    public function up(Schema $schema): void
    {
        /** @var list<string> $tenants */
        $tenants = $this->connection->fetchFirstColumn(
            'SELECT t.id FROM tenants t
             WHERE NOT EXISTS (
                 SELECT 1 FROM certificate_templates ct
                 WHERE ct.tenant_id = t.id
                   AND ct.is_default
                   AND ct.deleted_at IS NULL
             )',
        );

        foreach ($tenants as $tenantId) {
            $template = DefaultCertificateTemplate::for($tenantId);

            $this->connection->executeStatement(
                'INSERT INTO certificate_templates
                     (id, tenant_id, name, description, body_html, styles_css,
                      orientation, page_size, is_default, is_active)
                 VALUES (:id, :tenant, :name, :description, :body, :styles,
                         :orientation, :pageSize, true, true)',
                [
                    'id'          => $template->id,
                    'tenant'      => $tenantId,
                    'name'        => $template->name,
                    'description' => $template->description,
                    'body'        => $template->bodyHtml,
                    'styles'      => $template->stylesCss,
                    'orientation' => $template->orientation,
                    'pageSize'    => $template->pageSize,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Only the ones this migration could have added, and only while they
        // are untouched — an organisation that has edited the template it was
        // given should keep it.
        $this->connection->executeStatement(
            'DELETE FROM certificate_templates
             WHERE name = :name AND is_default AND updated_at IS NULL',
            ['name' => DefaultCertificateTemplate::NAME],
        );
    }
}
