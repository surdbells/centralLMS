<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\LearningPlanImporter;
use Central\Api\Domain\LearningPlan\LearningPlan;
use Central\Api\Domain\LearningPlan\LearningPlanRepository;
use PHPUnit\Framework\TestCase;

/**
 * Matching, diffing and idempotent re-import, against an in-memory repository
 * and the synthetic workbook.
 */
final class LearningPlanImporterTest extends TestCase
{
    private const T = '11111111-1111-1111-1111-111111111111';

    private string $path = '';
    private InMemoryPlans $repo;

    protected function setUp(): void
    {
        $this->path = PlanWorkbookFixture::write();
        $this->repo = new InMemoryPlans([
            'ada@example.test' => ['id' => 'u-ada', 'name' => 'Ada Test', 'status' => 'active'],
            'bo@example.test'  => ['id' => 'u-bo', 'name' => 'Bo Test', 'status' => 'suspended'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testPreviewMatchesPeopleAndCountsWithoutWriting(): void
    {
        $preview = (new LearningPlanImporter($this->repo))->preview(self::T, $this->path, 'plans.xlsx');
        $sheet   = $preview['sheets'][1];

        self::assertSame('2026 Learning Plan - ACME', $sheet['sheet']);
        self::assertTrue($sheet['importable']);
        self::assertSame(['matched' => 2, 'unmatched' => 1, 'noEmail' => 1, 'duplicate' => 1], $sheet['people']);
        self::assertSame(7, $sheet['items']['create']);
        self::assertSame(0, $sheet['items']['unchanged']);
        self::assertNull($sheet['existingPlanId']);
        self::assertContains('user_inactive', array_column($sheet['warnings'], 'code'));
        self::assertSame([], $this->repo->items);
        self::assertSame(2, $preview['totals']['importableSheets']);
        self::assertSame(1, $preview['totals']['unmatchedPeople']);
    }

    public function testCommitIsIdempotent(): void
    {
        $importer = new LearningPlanImporter($this->repo);
        $sheets   = ['2026 Learning Plan - ACME'];

        $first = $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', $sheets, false, false);
        $w     = $first['sheets'][0]['written'];

        self::assertTrue($w['planCreated']);
        self::assertSame(7, $w['itemsCreated']);
        self::assertSame(2, $w['membersAdded']);
        self::assertNull($w['error']);
        self::assertSame('draft', $this->repo->plans[$w['planId']]->status);

        $again = $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', $sheets, true, false);
        $w2    = $again['sheets'][0]['written'];

        self::assertFalse($w2['planCreated']);
        self::assertSame($w['planId'], $w2['planId']);
        self::assertSame(0, $w2['itemsCreated']);
        self::assertSame(0, $w2['itemsUpdated']);
        self::assertSame(7, $w2['itemsUnchanged']);
        self::assertTrue($w2['published']);
        self::assertCount(7, $this->repo->items);
    }

    public function testStaleItemsAreOnlyRemovedWhenAsked(): void
    {
        $importer = new LearningPlanImporter($this->repo);
        $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', ['2026 Learning Plan - ACME'], false, false);

        // An item from an earlier version of the file, for a matched person.
        $planId = array_key_first($this->repo->plans);
        $this->repo->items['old-key'] = ['id' => 'i-old', 'plan' => (string) $planId, 'user_id' => 'u-ada', 'title' => 'Old', 'category' => 'functional', 'provider' => null, 'schedule_text' => null, 'planned_start' => null, 'planned_end' => null, 'sort_order' => 9];

        $preview = $importer->preview(self::T, $this->path, 'plans.xlsx');
        self::assertSame(1, $preview['sheets'][1]['items']['stale']);

        $kept = $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', ['2026 Learning Plan - ACME'], false, false);
        self::assertSame(0, $kept['sheets'][0]['written']['itemsRemoved']);

        $removed = $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', ['2026 Learning Plan - ACME'], false, true);
        self::assertSame(1, $removed['sheets'][0]['written']['itemsRemoved']);
        self::assertArrayNotHasKey('old-key', $this->repo->items);
    }

    public function testASheetWithNoMatchedPeopleCreatesNoPlan(): void
    {
        $repo     = new InMemoryPlans([]);
        $importer = new LearningPlanImporter($repo);

        $result = $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', ['2026 Learning Plan - ACME'], true, false);
        $w      = $result['sheets'][0]['written'];

        self::assertNull($w['planId']);
        self::assertNotNull($w['error']);
        self::assertFalse($w['published']);
        self::assertSame([], $repo->plans);
    }

    public function testAFailingSheetIsReportedNotThrown(): void
    {
        $this->repo->failUpserts = true;
        $importer = new LearningPlanImporter($this->repo);

        $result = $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', ['2026 Learning Plan - ACME', '2025 ACME Training Plan '], false, false);

        self::assertCount(2, $result['sheets']);
        self::assertNotNull($result['sheets'][0]['written']['error']);
    }
}

/**
 * Just enough of the repository for the importer.
 */
final class InMemoryPlans implements LearningPlanRepository
{
    /** @var array<string, LearningPlan> */
    public array $plans = [];

    /** @var array<string, array{id: string, plan: string, user_id: ?string, title: string, category: ?string, provider: ?string, schedule_text: ?string, planned_start: ?string, planned_end: ?string, sort_order: int}> */
    public array $items = [];

    /** @var array<string, array<string, true>> */
    public array $members = [];

    public bool $failUpserts = false;

    /** @param array<string, array{id: string, name: string, status: string}> $users */
    public function __construct(private readonly array $users)
    {
    }

    public function list(string $tenantId, ?int $year = null): array
    {
        return array_values($this->plans);
    }

    public function find(string $tenantId, string $id): ?LearningPlan
    {
        return $this->plans[$id] ?? null;
    }

    public function save(LearningPlan $plan): LearningPlan
    {
        $id = $plan->id === '' ? 'p-' . (count($this->plans) + 1) : $plan->id;

        return $this->plans[$id] = new LearningPlan($id, $plan->tenantId, $plan->year, $plan->title, $plan->description, $plan->status);
    }

    public function setStatus(string $tenantId, string $id, string $status): ?LearningPlan
    {
        $p = $this->plans[$id];

        return $this->plans[$id] = new LearningPlan($id, $p->tenantId, $p->year, $p->title, $p->description, $status);
    }

    public function delete(string $tenantId, string $id): bool
    {
        unset($this->plans[$id]);

        return true;
    }

    public function items(string $tenantId, string $planId): array
    {
        return [];
    }

    public function saveItem(string $tenantId, string $planId, array $input): array
    {
        return [];
    }

    public function deleteItem(string $tenantId, string $planId, string $itemId): bool
    {
        return false;
    }

    public function assignMembers(string $tenantId, string $planId, array $userIds): int
    {
        $n = 0;
        foreach ($userIds as $u) {
            if (!isset($this->members[$planId][$u])) {
                $this->members[$planId][$u] = true;
                $n++;
            }
        }

        return $n;
    }

    public function assignDepartment(string $tenantId, string $planId, string $departmentId): int
    {
        return 0;
    }

    public function assignEveryone(string $tenantId, string $planId): int
    {
        return 0;
    }

    public function members(string $tenantId, string $planId, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function removeMember(string $tenantId, string $planId, string $userId): bool
    {
        return false;
    }

    public function personalItems(string $tenantId, string $planId, ?string $userId, ?string $category, ?string $q, int $page, int $perPage): array
    {
        return ['people' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage, 'categories' => []];
    }

    public function findByYearTitle(string $tenantId, int $year, string $title): ?LearningPlan
    {
        $norm = static fn (string $t): string => mb_strtolower(str_replace(["\u{2013}", "\u{2014}"], '-', trim($t)));

        foreach ($this->plans as $p) {
            if ($p->year === $year && $norm($p->title) === $norm($title)) {
                return $p;
            }
        }

        return null;
    }

    public function importedItems(string $tenantId, string $planId): array
    {
        $out = [];
        foreach ($this->items as $key => $i) {
            if ($i['plan'] === $planId) {
                $copy = $i;
                unset($copy['plan']);
                $out[$key] = $copy;
            }
        }

        return $out;
    }

    public function upsertImportedItem(string $tenantId, string $planId, array $row): string
    {
        if ($this->failUpserts) {
            throw new \RuntimeException('boom');
        }

        $existed = isset($this->items[$row['importKey']]);
        $this->items[$row['importKey']] = [
            'id'            => $this->items[$row['importKey']]['id'] ?? 'i-' . count($this->items),
            'plan'          => $planId,
            'user_id'       => $row['userId'],
            'title'         => $row['title'],
            'category'      => $row['category'],
            'provider'      => $row['provider'],
            'schedule_text' => $row['scheduleText'],
            'planned_start' => $row['plannedStart'],
            'planned_end'   => $row['plannedEnd'],
            'sort_order'    => $row['sortOrder'],
        ];

        return $existed ? 'updated' : 'created';
    }

    public function deleteItemsByIds(string $tenantId, string $planId, array $ids): int
    {
        $n = 0;
        foreach ($this->items as $key => $i) {
            if ($i['plan'] === $planId && in_array($i['id'], $ids, true)) {
                unset($this->items[$key]);
                $n++;
            }
        }

        return $n;
    }

    public function userEmailIndex(string $tenantId): array
    {
        return $this->users;
    }

    public function transactional(\Closure $work): mixed
    {
        return $work();
    }

    public function forLearner(string $tenantId, string $userId): array
    {
        return [];
    }

    public function canTouchItem(string $tenantId, string $userId, string $itemId): bool
    {
        return false;
    }

    public function setSchedule(string $tenantId, string $userId, string $itemId, ?string $date): void
    {
    }

    public function setItemStatus(string $tenantId, string $userId, string $itemId, string $status): void
    {
    }

    public function completionForLearner(string $tenantId, string $userId, ?int $year = null): array
    {
        return ['total' => 0, 'completed' => 0, 'rate' => 0.0, 'byYear' => []];
    }
}
