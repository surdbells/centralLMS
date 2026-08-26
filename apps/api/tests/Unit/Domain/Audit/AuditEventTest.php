<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Audit;

use Central\Api\Domain\Audit\AuditEvent;
use Central\Api\Domain\Audit\AuditFilters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvent::class)]
#[CoversClass(AuditFilters::class)]
final class AuditEventTest extends TestCase
{
    #[Test]
    #[DataProvider('sentences')]
    public function an_action_reads_as_a_sentence(string $action, string $expected): void
    {
        // Derived from the route name rather than looked up in a table of
        // labels, which would be one more thing to forget when a route lands.
        self::assertSame($expected, self::event($action)->describe());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sentences(): iterable
    {
        yield 'create'            => ['courses.create', 'Created a course'];
        yield 'delete'            => ['users.delete', 'Deleted a user'];
        yield 'nested'            => ['modules.create', 'Created a module'];
        yield 'an article'        => ['enrollments.create', 'Created an enrollment'];
        yield 'status'            => ['users.status', 'Changed the status of a user'];
        yield 'self-service'      => ['me.profile.update', 'Updated their own profile'];
        yield 'a bare me route'   => ['me.avatar', 'Me avatar'];
        // Words that are already singular must not have a letter taken off.
        yield 'not a plural'      => ['settings.update', 'Updated a settings'];
        // And a verb nobody has named still says something true.
        yield 'an unknown verb'   => ['courses.skills', 'Courses skills'];
    }

    #[Test]
    public function a_departed_colleague_is_still_named_by_their_address(): void
    {
        // `actor_id` goes null when somebody is deleted — an audit row has to
        // outlive its subject — and the name is joined from `users`, so it
        // comes back empty. Without the fallback every row for somebody who
        // has left would read "somebody did this".
        $event = new AuditEvent(
            id: 'e-1',
            tenantId: 't-1',
            actorId: null,
            actorEmail: 'gone@example.test',
            action: 'users.delete',
            method: 'DELETE',
            path: '/v1/users/u-1',
            subjectId: null,
            status: 204,
        );

        self::assertSame('gone@example.test', $event->toArray()['actorName']);
    }

    #[Test]
    public function a_filter_it_cannot_parse_is_a_violation_not_a_shrug(): void
    {
        // A dropped filter hands back everything, which reads as a wide result
        // rather than a rejected query.
        $violations = [];

        AuditFilters::fromQuery(
            ['action' => '%', 'actorId' => 'nobody', 'from' => 'yesterday-ish'],
            $violations,
        );

        self::assertArrayHasKey('action', $violations);
        self::assertArrayHasKey('actorId', $violations);
        self::assertArrayHasKey('from', $violations);
    }

    #[Test]
    public function an_absent_filter_is_not_a_violation(): void
    {
        $violations = [];
        $filters    = AuditFilters::fromQuery([], $violations);

        self::assertSame([], $violations);
        self::assertNull($filters->action);
        self::assertFalse($filters->failuresOnly);
    }

    #[Test]
    public function a_route_name_passes_and_a_wildcard_does_not(): void
    {
        // The pattern is what keeps a `%` out of the LIKE this becomes.
        $violations = [];

        self::assertSame('users.create', AuditFilters::fromQuery(['action' => 'users.create'], $violations)->action);
        self::assertSame('users', AuditFilters::fromQuery(['action' => 'users'], $violations)->action);
        self::assertSame([], $violations);

        AuditFilters::fromQuery(['action' => 'users%'], $violations);
        self::assertArrayHasKey('action', $violations);
    }

    private static function event(string $action): AuditEvent
    {
        return new AuditEvent(
            id: 'e-1',
            tenantId: 't-1',
            actorId: 'u-1',
            actorEmail: 'ada@example.test',
            action: $action,
            method: 'POST',
            path: '/v1/whatever',
            subjectId: null,
            status: 201,
            actorName: 'Ada Lovelace',
        );
    }
}
