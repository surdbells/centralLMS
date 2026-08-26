<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Certificate\Certificate;
use Central\Api\Domain\Certificate\CertificateIdentity;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Certificate\CertificateSource;
use Central\Api\Domain\Certificate\CertificateStatus;
use Central\Api\Domain\Certificate\CertificateTemplate;
use Doctrine\DBAL\Connection;

final class DbalCertificateRepository implements CertificateRepository
{
    private const COLUMNS = 'c.id, c.tenant_id, c.number, c.verification_code, c.verification_hash,
                             c.template_id, c.source, c.course_id, c.training_id, c.assessment_id,
                             c.attempt_id, c.enrollment_id, c.user_id, c.learner_name, c.learner_email,
                             c.title, c.score, c.percentage, c.grade, c.custom_data, c.pdf_url,
                             c.issued_at, c.valid_from, c.valid_until, c.status,
                             c.revoked_at, c.revocation_reason';

    /**
     * The counters the legacy kept on the row, computed from the log instead.
     */
    private const DERIVED = "(
            SELECT COUNT(*) FROM certificate_events e
            WHERE e.certificate_id = c.id AND e.action = 'downloaded'
        ) AS download_count,
        (
            SELECT COUNT(*) FROM certificate_events e
            WHERE e.certificate_id = c.id AND e.action = 'verified'
        ) AS verification_count";

    private const TEMPLATE_COLUMNS = 'id, tenant_id, name, description, body_html, styles_css,
                                      orientation, page_size, is_default, is_active,
                                      created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // --- templates --------------------------------------------------------

    public function paginateTemplates(string $tenantId, int $page, int $perPage, ?string $search = null): array
    {
        $where = 'tenant_id = :tenant AND deleted_at IS NULL';
        $params = ['tenant' => $tenantId];

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (name ILIKE :search OR description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM certificate_templates WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM certificate_templates WHERE %s
                 ORDER BY is_default DESC, name ASC LIMIT :limit OFFSET :offset',
                self::TEMPLATE_COLUMNS,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): CertificateTemplate => $this->hydrateTemplate($r), $rows),
            'total' => $total,
        ];
    }

    public function findTemplate(string $tenantId, string $id): ?CertificateTemplate
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM certificate_templates
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                self::TEMPLATE_COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateTemplate($row);
    }

    public function findDefaultTemplate(string $tenantId): ?CertificateTemplate
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM certificate_templates
                 WHERE tenant_id = :tenant AND is_default AND is_active AND deleted_at IS NULL',
                self::TEMPLATE_COLUMNS,
            ),
            ['tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateTemplate($row);
    }

    public function saveTemplate(CertificateTemplate $template): CertificateTemplate
    {
        /** @var string $id */
        $id = $this->connection->transactional(
            function (Connection $tx) use ($template): string {
                // Only one default per tenant, and the index enforces it — so
                // the old default must stand down in the same transaction.
                if ($template->isDefault) {
                    $tx->executeStatement(
                        'UPDATE certificate_templates SET is_default = FALSE, updated_at = NOW()
                         WHERE tenant_id = :tenant AND is_default AND id <> :id AND deleted_at IS NULL',
                        ['tenant' => $template->tenantId, 'id' => $template->id ?: self::NIL_UUID],
                    );
                }

                $params = [
                    'tenant'      => $template->tenantId,
                    'name'        => $template->name,
                    'description' => $template->description,
                    'body'        => $template->bodyHtml,
                    'css'         => $template->stylesCss,
                    'orientation' => $template->orientation,
                    'pageSize'    => $template->pageSize,
                    'isDefault'   => $template->isDefault ? 'true' : 'false',
                    'isActive'    => $template->isActive ? 'true' : 'false',
                ];

                if ($template->id === '') {
                    return (string) $tx->fetchOne(
                        'INSERT INTO certificate_templates
                             (tenant_id, name, description, body_html, styles_css,
                              orientation, page_size, is_default, is_active)
                         VALUES (:tenant, :name, :description, :body, :css,
                                 :orientation, :pageSize, :isDefault, :isActive)
                         RETURNING id',
                        $params,
                    );
                }

                $affected = $tx->executeStatement(
                    'UPDATE certificate_templates SET
                        name = :name, description = :description, body_html = :body,
                        styles_css = :css, orientation = :orientation, page_size = :pageSize,
                        is_default = :isDefault, is_active = :isActive, updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    $params + ['id' => $template->id],
                );

                if ($affected === 0) {
                    throw new \RuntimeException('Template could not be found.');
                }

                return $template->id;
            },
        );

        return $this->findTemplate($template->tenantId, $id)
            ?? throw new \RuntimeException('Template could not be read back.');
    }

    public function deleteTemplate(string $tenantId, string $id): bool
    {
        // Certificates already issued keep their template_id; the foreign key
        // is ON DELETE SET NULL and they carry their own rendered PDF anyway.
        return $this->connection->executeStatement(
            'UPDATE certificate_templates SET deleted_at = NOW(), is_default = FALSE, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function templateHasName(string $tenantId, string $name, ?string $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM certificate_templates
                WHERE tenant_id = :tenant AND lower(name) = lower(:name) AND deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'name' => $name];

        if ($exceptId !== null && $exceptId !== '') {
            $sql .= ' AND id <> :exceptId';
            $params['exceptId'] = $exceptId;
        }

        return $this->connection->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    // --- issuing ----------------------------------------------------------

    public function issue(
        string $tenantId,
        CertificateSource $source,
        string $sourceId,
        string $userId,
        array $details,
        ?string $actorId,
    ): Certificate {
        /** @var string $id */
        $id = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $source, $sourceId, $userId, $details, $actorId): string {
                $column = $this->sourceColumn($source);

                // The old one stands down first: the partial unique index
                // allows a single active certificate per learner per thing, so
                // the insert would otherwise be rejected.
                $superseded = $tx->fetchFirstColumn(
                    sprintf(
                        "UPDATE certificates SET status = 'superseded', updated_at = NOW()
                         WHERE %s = :source AND user_id = :user AND tenant_id = :tenant
                           AND status = 'active'
                         RETURNING id",
                        $column,
                    ),
                    ['source' => $sourceId, 'user' => $userId, 'tenant' => $tenantId],
                );

                foreach ($superseded as $oldId) {
                    $this->writeEvent($tx, $tenantId, (string) $oldId, 'superseded', $actorId, []);
                }

                /** @var CertificateIdentity $identity */
                $identity = $details['identity'];

                $newId = (string) $tx->fetchOne(
                    sprintf(
                        'INSERT INTO certificates
                             (tenant_id, number, verification_code, verification_hash, template_id,
                              source, %s, attempt_id, enrollment_id, user_id,
                              learner_name, learner_email, title, score, percentage, grade,
                              custom_data, valid_from, valid_until, issued_by)
                         VALUES (:tenant, :number, :code, :hash, :template,
                                 :source, :sourceId, :attempt, :enrollment, :user,
                                 :learnerName, :learnerEmail, :title, :score, :percentage, :grade,
                                 :custom::jsonb, COALESCE(:validFrom::date, CURRENT_DATE), :validUntil::date, :actor)
                         RETURNING id',
                        $column,
                    ),
                    [
                        'tenant'   => $tenantId,
                        'number'   => $identity->number,
                        'code'     => $identity->code,
                        'hash'     => $identity->hash,
                        'template' => $details['templateId'] ?? null,
                        'source'   => $source->value,
                        'sourceId' => $sourceId,
                        'attempt'      => $details['attemptId'] ?? null,
                        'enrollment'   => $details['enrollmentId'] ?? null,
                        'user'         => $userId,
                        'learnerName'  => $details['learnerName'],
                        'learnerEmail' => $details['learnerEmail'] ?? null,
                        'title'        => $details['title'],
                        'score'        => $details['score'] ?? null,
                        'percentage'   => $details['percentage'] ?? null,
                        'grade'        => $details['grade'] ?? null,
                        // JSON_FORCE_OBJECT so an empty array encodes as
                        // `{}` rather than `[]`; the column is constrained to
                        // an object, and a list here would mean the shape had
                        // drifted.
                        'custom'       => json_encode(
                            $details['customData'] ?? [],
                            JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT,
                        ),
                        'validFrom'    => $details['validFrom'] ?? null,
                        'validUntil'   => $details['validUntil'] ?? null,
                        'actor'        => $actorId,
                    ],
                );

                $this->writeEvent($tx, $tenantId, $newId, 'issued', $actorId, []);

                return $newId;
            },
        );

        return $this->find($tenantId, $id)
            ?? throw new \RuntimeException('Newly issued certificate could not be read back.');
    }

    public function nextSequence(string $tenantId, int $year): int
    {
        return 1 + (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM certificates
             WHERE tenant_id = :tenant AND EXTRACT(YEAR FROM issued_at) = :year',
            ['tenant' => $tenantId, 'year' => $year],
        );
    }

    public function tenantCode(string $tenantId): string
    {
        $code = $this->connection->fetchOne(
            'SELECT code FROM tenants WHERE id = :id',
            ['id' => $tenantId],
        );

        return is_string($code) && $code !== '' ? $code : 'CERT';
    }

    // --- reading ----------------------------------------------------------

    public function find(string $tenantId, string $id): ?Certificate
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM certificates c WHERE c.id = :id AND c.tenant_id = :tenant',
                self::COLUMNS,
                self::DERIVED,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?CertificateStatus $status = null,
        ?string $userId = null,
    ): array {
        $where = 'c.tenant_id = :tenant';
        $params = ['tenant' => $tenantId];

        if ($status !== null) {
            $where .= ' AND c.status = :status';
            $params['status'] = $status->value;
        }

        if ($userId !== null && $userId !== '') {
            $where .= ' AND c.user_id = :user';
            $params['user'] = $userId;
        }

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (c.learner_name ILIKE :search OR c.title ILIKE :search OR c.number ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM certificates c WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM certificates c WHERE %s
                 ORDER BY c.issued_at DESC LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::DERIVED,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): Certificate => $this->hydrate($r), $rows),
            'total' => $total,
        ];
    }

    public function forLearner(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM certificates c
                 WHERE c.tenant_id = :tenant AND c.user_id = :user
                 ORDER BY c.issued_at DESC',
                self::COLUMNS,
                self::DERIVED,
            ),
            ['tenant' => $tenantId, 'user' => $userId],
        );

        return array_map(fn (array $r): Certificate => $this->hydrate($r), $rows);
    }

    public function findForSource(
        string $tenantId,
        CertificateSource $source,
        string $sourceId,
        string $userId,
    ): ?Certificate {
        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT %s, %s FROM certificates c
                 WHERE c.%s = :source AND c.user_id = :user
                   AND c.tenant_id = :tenant AND c.status = 'active'",
                self::COLUMNS,
                self::DERIVED,
                $this->sourceColumn($source),
            ),
            ['source' => $sourceId, 'user' => $userId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    // --- public verification ----------------------------------------------

    public function verifyByHash(string $hash): ?Certificate
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM certificates c WHERE c.verification_hash = :hash',
                self::COLUMNS,
                self::DERIVED,
            ),
            ['hash' => $hash],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function verifyByCode(string $code): ?Certificate
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM certificates c WHERE upper(c.verification_code) = upper(:code)',
                self::COLUMNS,
                self::DERIVED,
            ),
            ['code' => $code],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    // --- lifecycle --------------------------------------------------------

    public function revoke(string $tenantId, string $id, string $reason, string $actorId): ?Certificate
    {
        $affected = $this->connection->executeStatement(
            "UPDATE certificates
             SET status = 'revoked', revoked_at = NOW(), revoked_by = :actor,
                 revocation_reason = :reason, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND status <> 'revoked'",
            ['actor' => $actorId, 'reason' => $reason, 'id' => $id, 'tenant' => $tenantId],
        );

        if ($affected === 0) {
            return null;
        }

        $this->log($tenantId, $id, 'revoked', $actorId, ['notes' => $reason]);

        return $this->find($tenantId, $id);
    }

    public function reinstate(string $tenantId, string $id, string $actorId): ?Certificate
    {
        // The check constraint requires `revoked_at` to be null unless the
        // status is `revoked`, so all three fields clear together.
        $affected = $this->connection->executeStatement(
            "UPDATE certificates
             SET status = 'active', revoked_at = NULL, revoked_by = NULL,
                 revocation_reason = NULL, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND status = 'revoked'",
            ['id' => $id, 'tenant' => $tenantId],
        );

        if ($affected === 0) {
            return null;
        }

        $this->log($tenantId, $id, 'reinstated', $actorId, []);

        return $this->find($tenantId, $id);
    }

    public function expireLapsed(?string $tenantId = null): int
    {
        $sql = "UPDATE certificates SET status = 'expired', updated_at = NOW()
                WHERE status = 'active' AND valid_until IS NOT NULL AND valid_until < CURRENT_DATE";
        $params = [];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant';
            $params['tenant'] = $tenantId;
        }

        return (int) $this->connection->executeStatement($sql, $params);
    }

    public function recordPdf(string $tenantId, string $id, string $pdfUrl): ?Certificate
    {
        $affected = $this->connection->executeStatement(
            'UPDATE certificates SET pdf_url = :url, pdf_generated_at = NOW(), updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant',
            ['url' => $pdfUrl, 'id' => $id, 'tenant' => $tenantId],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    // --- history ----------------------------------------------------------

    public function log(
        string $tenantId,
        string $certificateId,
        string $action,
        ?string $actorId = null,
        array $context = [],
    ): void {
        $this->writeEvent($this->connection, $tenantId, $certificateId, $action, $actorId, $context);
    }

    public function history(string $tenantId, string $certificateId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.action, e.verifier_name, e.verifier_company, e.notes, e.created_at,
                    NULLIF(btrim(concat_ws(' ', a.first_name, a.last_name)), '') AS actor_name
             FROM certificate_events e
             LEFT JOIN users a ON a.id = e.actor_id
             WHERE e.certificate_id = :id AND e.tenant_id = :tenant
             ORDER BY e.created_at DESC",
            ['id' => $certificateId, 'tenant' => $tenantId],
        );

        return array_map(static fn (array $r): array => [
            'action'    => (string) $r['action'],
            'actorName' => is_string($r['actor_name'] ?? null) ? $r['actor_name'] : null,
            // Self-reported by whoever was checking, so it is evidence that a
            // check happened rather than of who made it.
            'verifierName'    => is_string($r['verifier_name'] ?? null) ? $r['verifier_name'] : null,
            'verifierCompany' => is_string($r['verifier_company'] ?? null) ? $r['verifier_company'] : null,
            'notes' => is_string($r['notes'] ?? null) ? $r['notes'] : null,
            'at'    => (string) $r['created_at'],
        ], $rows);
    }

    // --- internals --------------------------------------------------------

    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @param array<string, mixed> $context
     */
    private function writeEvent(
        Connection $connection,
        string $tenantId,
        string $certificateId,
        string $action,
        ?string $actorId,
        array $context,
    ): void {
        $connection->executeStatement(
            'INSERT INTO certificate_events
                 (tenant_id, certificate_id, action, actor_id,
                  verifier_name, verifier_email, verifier_company, ip_address, notes)
             VALUES (:tenant, :certificate, :action, :actor,
                     :name, :email, :company, :ip::inet, :notes)',
            [
                'tenant'      => $tenantId,
                'certificate' => $certificateId,
                'action'      => $action,
                'actor'       => $actorId,
                'name'        => $context['verifierName'] ?? null,
                'email'       => $context['verifierEmail'] ?? null,
                'company'     => $context['verifierCompany'] ?? null,
                'ip'          => $context['ip'] ?? null,
                'notes'       => $context['notes'] ?? null,
            ],
        );
    }

    /**
     * The column a source lives in.
     *
     * Chosen from three literals, never interpolated from input.
     */
    private function sourceColumn(CertificateSource $source): string
    {
        return match ($source) {
            CertificateSource::Course     => 'course_id',
            CertificateSource::Training   => 'training_id',
            CertificateSource::Assessment => 'assessment_id',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Certificate
    {
        $custom = json_decode((string) ($row['custom_data'] ?? '{}'), true);

        return new Certificate(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            identity: CertificateIdentity::existing(
                (string) $row['number'],
                (string) $row['verification_code'],
                (string) $row['verification_hash'],
            ),
            source: CertificateSource::fromLegacy((string) $row['source']),
            userId: (string) $row['user_id'],
            learnerName: (string) $row['learner_name'],
            title: (string) $row['title'],
            status: CertificateStatus::fromLegacy((string) $row['status']),
            learnerEmail: $this->text($row['learner_email'] ?? null),
            templateId: $this->text($row['template_id'] ?? null),
            courseId: $this->text($row['course_id'] ?? null),
            trainingId: $this->text($row['training_id'] ?? null),
            assessmentId: $this->text($row['assessment_id'] ?? null),
            attemptId: $this->text($row['attempt_id'] ?? null),
            enrollmentId: $this->text($row['enrollment_id'] ?? null),
            score: isset($row['score']) ? (float) $row['score'] : null,
            percentage: isset($row['percentage']) ? (float) $row['percentage'] : null,
            grade: $this->text($row['grade'] ?? null),
            customData: is_array($custom) ? $custom : [],
            pdfUrl: $this->text($row['pdf_url'] ?? null),
            issuedAt: $this->date($row['issued_at'] ?? null),
            validFrom: $this->date($row['valid_from'] ?? null),
            validUntil: $this->date($row['valid_until'] ?? null),
            revokedAt: $this->date($row['revoked_at'] ?? null),
            revocationReason: $this->text($row['revocation_reason'] ?? null),
            downloadCount: (int) ($row['download_count'] ?? 0),
            verificationCount: (int) ($row['verification_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTemplate(array $row): CertificateTemplate
    {
        return new CertificateTemplate(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            bodyHtml: (string) $row['body_html'],
            description: $this->text($row['description'] ?? null),
            stylesCss: $this->text($row['styles_css'] ?? null),
            orientation: (string) $row['orientation'],
            pageSize: (string) $row['page_size'],
            isDefault: (bool) $row['is_default'],
            isActive: (bool) $row['is_active'],
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
