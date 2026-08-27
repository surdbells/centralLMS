<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Notification\EmailTemplate;
use Central\Api\Domain\Notification\EmailTemplateRepository;
use Central\Api\Domain\Notification\NotificationType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DbalEmailTemplateRepository implements EmailTemplateRepository
{
    private const COLUMNS = 'id, tenant_id, type, subject, body, enabled, updated_by, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s FROM email_templates WHERE tenant_id = :tenant', self::COLUMNS),
            ['tenant' => $tenantId],
        );

        $templates = [];

        foreach ($rows as $row) {
            $type = NotificationType::tryFrom((string) $row['type']);

            // A row naming a type that no longer exists is ignored rather than
            // throwing: the catalogue is an enum in code, and removing a case
            // should not stop a deployment on rows nobody can reach any more.
            if ($type !== null) {
                $templates[$type->value] = $this->hydrate($row, $type);
            }
        }

        return $templates;
    }

    public function find(string $tenantId, NotificationType $type): ?EmailTemplate
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM email_templates WHERE tenant_id = :tenant AND type = :type',
                self::COLUMNS,
            ),
            ['tenant' => $tenantId, 'type' => $type->value],
        );

        return $row === false ? null : $this->hydrate($row, $type);
    }

    public function save(EmailTemplate $template): EmailTemplate
    {
        $this->connection->executeStatement(
            'INSERT INTO email_templates (id, tenant_id, type, subject, body, enabled, updated_by)
             VALUES (:id, :tenant, :type, :subject, :body, :enabled, :updatedBy)
             ON CONFLICT (tenant_id, type) DO UPDATE SET
                 subject    = EXCLUDED.subject,
                 body       = EXCLUDED.body,
                 enabled    = EXCLUDED.enabled,
                 updated_by = EXCLUDED.updated_by,
                 updated_at = NOW()',
            [
                'id'        => $template->id === '' ? Uuid::uuid4()->toString() : $template->id,
                'tenant'    => $template->tenantId,
                'type'      => $template->type->value,
                'subject'   => $template->subject,
                'body'      => $template->body,
                'enabled'   => $template->enabled ? 'true' : 'false',
                'updatedBy' => $template->updatedBy,
            ],
        );

        return $this->find($template->tenantId, $template->type) ?? $template;
    }

    public function delete(string $tenantId, NotificationType $type): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM email_templates WHERE tenant_id = :tenant AND type = :type',
            ['tenant' => $tenantId, 'type' => $type->value],
        ) > 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row, NotificationType $type): EmailTemplate
    {
        return new EmailTemplate(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            type: $type,
            subject: $this->text($row['subject'] ?? null),
            body: $this->text($row['body'] ?? null),
            enabled: (bool) $row['enabled'],
            updatedBy: $this->text($row['updated_by'] ?? null),
            updatedAt: isset($row['updated_at']) && is_string($row['updated_at'])
                ? new \DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
