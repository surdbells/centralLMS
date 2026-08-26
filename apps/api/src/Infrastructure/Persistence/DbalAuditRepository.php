<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Audit\AuditEvent;
use Central\Api\Domain\Audit\AuditFilters;
use Central\Api\Domain\Audit\AuditRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

final readonly class DbalAuditRepository implements AuditRepository
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Records one event, and never lets its own failure reach the caller.
     *
     * This runs after the request it describes has already succeeded — the
     * course is created, the account is suspended. Throwing here would turn a
     * completed action into a 500 the client would reasonably retry, doing it
     * twice. A lost audit row is logged loudly and the request stands.
     */
    public function record(AuditEvent $event): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO audit_events
                    (tenant_id, actor_id, actor_email, action, method, path,
                     subject_id, status, ip_address, user_agent, request_id)
                 VALUES
                    (:tenant, :actor, :actorEmail, :action, :method, :path,
                     :subject, :status, :ip, :agent, :requestId)',
                [
                    'tenant'     => $event->tenantId,
                    'actor'      => $event->actorId,
                    'actorEmail' => $event->actorEmail,
                    'action'     => $event->action,
                    'method'     => $event->method,
                    'path'       => $event->path,
                    'subject'    => $event->subjectId,
                    'status'     => $event->status,
                    'ip'         => $event->ipAddress,
                    'agent'      => $event->userAgent,
                    'requestId'  => $event->requestId,
                ],
                ['status' => ParameterType::INTEGER],
            );
        } catch (\Throwable $e) {
            $this->logger->error('An audit event could not be recorded.', [
                'action'    => $event->action,
                'actorId'   => $event->actorId,
                'requestId' => $event->requestId,
                'exception' => $e,
            ]);
        }
    }

    public function paginate(string $tenantId, AuditFilters $filters, int $page, int $perPage): array
    {
        [$where, $parameters, $types] = $this->conditions($tenantId, $filters);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events e WHERE ' . $where,
            $parameters,
            $types,
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $rows = $this->connection->fetchAllAssociative(
            // The actor's name is joined rather than stored, so the trail
            // shows what somebody is called now. When the join misses — they
            // have been deleted — the address on the row stands in.
            'SELECT e.id, e.actor_id, e.actor_email, e.action, e.method, e.path,
                    e.subject_id, e.status, host(e.ip_address) AS ip_address, e.user_agent,
                    e.request_id, e.created_at,
                    NULLIF(btrim(COALESCE(u.first_name, \'\') || \' \' || COALESCE(u.last_name, \'\')), \'\')
                        AS actor_name
               FROM audit_events e
               LEFT JOIN users u ON u.id = e.actor_id
              WHERE ' . $where . '
              ORDER BY e.created_at DESC, e.id DESC
              LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(fn (array $row): AuditEvent => $this->hydrate($tenantId, $row), $rows),
            'total' => $total,
        ];
    }

    public function actionsUsed(string $tenantId): array
    {
        return array_map(
            static fn (mixed $value): string => (string) $value,
            $this->connection->fetchFirstColumn(
                'SELECT DISTINCT action FROM audit_events e WHERE tenant_id = :tenant ORDER BY action',
                ['tenant' => $tenantId],
            ),
        );
    }

    public function deleteBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM audit_events WHERE created_at < :cutoff',
            ['cutoff' => $cutoff->format(\DateTimeInterface::ATOM)],
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function conditions(string $tenantId, AuditFilters $filters): array
    {
        $where      = ['e.tenant_id = :tenant'];
        $parameters = ['tenant' => $tenantId];
        $types      = [];

        if ($filters->actorId !== null) {
            $where[]              = 'e.actor_id = :actor';
            $parameters['actor']  = $filters->actorId;
        }

        if ($filters->action !== null) {
            // `users` matches every users.* action; `users.create` matches one.
            // The value is already restricted to word characters and dots, so
            // there is no wildcard for a caller to smuggle in.
            $where[]               = '(e.action = :action OR e.action LIKE :actionPrefix)';
            $parameters['action']  = $filters->action;
            $parameters['actionPrefix'] = $filters->action . '.%';
        }

        if ($filters->subjectId !== null) {
            $where[]                = 'e.subject_id = :subject';
            $parameters['subject']  = $filters->subjectId;
        }

        if ($filters->from !== null) {
            $where[]             = 'e.created_at >= :from';
            $parameters['from']  = $filters->from->format(\DateTimeInterface::ATOM);
        }

        if ($filters->until !== null) {
            $where[]              = 'e.created_at <= :until';
            $parameters['until']  = $filters->until->format(\DateTimeInterface::ATOM);
        }

        if ($filters->failuresOnly) {
            $where[] = 'e.status >= 400';
        }

        return [implode(' AND ', $where), $parameters, $types];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(string $tenantId, array $row): AuditEvent
    {
        return new AuditEvent(
            id: (string) $row['id'],
            tenantId: $tenantId,
            actorId: $row['actor_id'] === null ? null : (string) $row['actor_id'],
            actorEmail: (string) ($row['actor_email'] ?? ''),
            action: (string) $row['action'],
            method: (string) $row['method'],
            path: (string) $row['path'],
            subjectId: $row['subject_id'] === null ? null : (string) $row['subject_id'],
            status: (int) $row['status'],
            actorName: (string) ($row['actor_name'] ?? ''),
            ipAddress: $row['ip_address'] === null ? null : (string) $row['ip_address'],
            userAgent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
            requestId: $row['request_id'] === null ? null : (string) $row['request_id'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
