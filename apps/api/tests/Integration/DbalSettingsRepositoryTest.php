<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Settings\SettingCatalogue;
use Central\Api\Domain\Settings\SettingCategory;
use Central\Api\Domain\Settings\SettingKey;
use Central\Api\Infrastructure\Persistence\DbalSettingsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Setting overrides against a real PostgreSQL.
 *
 * The thing worth checking here is what is *not* written: only overrides land
 * in the table, so a tenant that has changed one colour has one row rather than
 * the thirty the legacy wrote on first save — most of them holding the default
 * they had just been handed.
 */
#[CoversClass(DbalSettingsRepository::class)]
final class DbalSettingsRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalSettingsRepository $settings;

    private string $tenantA;
    private string $tenantB;
    private string $ada;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->settings = new DbalSettingsRepository(self::$connection, new SettingCatalogue());

        $this->tenantA = $this->createTenant('set-a');
        $this->tenantB = $this->createTenant('set-b');
        $this->ada     = $this->createUser($this->tenantA, 'Ada');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            self::$connection->executeStatement(
                'DELETE FROM tenant_settings WHERE tenant_id = :id',
                ['id' => $tenantId],
            );
            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    #[Test]
    public function a_tenant_that_has_saved_nothing_has_no_rows(): void
    {
        $settings = $this->settings->forTenant($this->tenantA);

        self::assertSame('#c9a227', $settings->string(SettingKey::PrimaryColour));
        self::assertSame(0, $this->rowCount($this->tenantA));
    }

    #[Test]
    public function only_what_changed_is_written(): void
    {
        // The legacy wrote every key of every category on first save. Two
        // tenants ended up with sixteen rows between them, all defaults.
        $this->settings->save($this->tenantA, ['primary_color' => '#112233'], $this->ada);

        self::assertSame(1, $this->rowCount($this->tenantA));
    }

    #[Test]
    public function saving_the_same_key_twice_updates_it(): void
    {
        $this->settings->save($this->tenantA, ['primary_color' => '#112233'], $this->ada);
        $saved = $this->settings->save($this->tenantA, ['primary_color' => '#445566'], $this->ada);

        self::assertSame('#445566', $saved->string(SettingKey::PrimaryColour));
        self::assertSame(1, $this->rowCount($this->tenantA));
    }

    #[Test]
    public function a_saved_value_comes_back_as_its_type(): void
    {
        // `value_type` was a column the legacy never read, so a boolean saved
        // as "false" came back as the string "false", which is true.
        $saved = $this->settings->save(
            $this->tenantA,
            ['show_powered_by' => 'false', 'points_per_course' => '250'],
            $this->ada,
        );

        self::assertFalse($saved->bool(SettingKey::ShowPoweredBy));
        self::assertSame(250, $saved->int(SettingKey::PointsPerCourse));
    }

    #[Test]
    public function one_tenants_settings_are_not_anothers(): void
    {
        $this->settings->save($this->tenantA, ['primary_color' => '#112233'], $this->ada);

        self::assertSame('#112233', $this->settings->forTenant($this->tenantA)->string(SettingKey::PrimaryColour));
        self::assertSame('#c9a227', $this->settings->forTenant($this->tenantB)->string(SettingKey::PrimaryColour));
    }

    #[Test]
    public function a_save_is_all_or_nothing(): void
    {
        // A settings form saves a whole tab, and half a saved tab is worse
        // than a rejected one.
        try {
            $this->settings->save(
                $this->tenantA,
                ['primary_color' => '#112233', 'not_a_setting' => 'x'],
                $this->ada,
            );
            self::fail('An undeclared key should have been refused.');
        } catch (\LogicException) {
            // expected
        }

        self::assertSame(0, $this->rowCount($this->tenantA));
    }

    #[Test]
    public function saving_nothing_is_not_an_error(): void
    {
        $settings = $this->settings->save($this->tenantA, [], $this->ada);

        self::assertSame('#c9a227', $settings->string(SettingKey::PrimaryColour));
    }

    #[Test]
    public function a_reset_removes_the_rows_rather_than_writing_the_defaults(): void
    {
        // A stored default is indistinguishable from a deliberate choice, and
        // the next time the default changes the tenant silently keeps the old
        // one.
        $this->settings->save(
            $this->tenantA,
            ['primary_color' => '#112233', 'points_per_course' => '250'],
            $this->ada,
        );

        $removed = $this->settings->resetCategory($this->tenantA, SettingCategory::Branding);

        self::assertSame(1, $removed);
        self::assertSame(1, $this->rowCount($this->tenantA));

        $settings = $this->settings->forTenant($this->tenantA);

        self::assertSame('#c9a227', $settings->string(SettingKey::PrimaryColour));
        self::assertFalse($settings->isOverridden(SettingKey::PrimaryColour));
        // The other category is untouched.
        self::assertSame(250, $settings->int(SettingKey::PointsPerCourse));
    }

    #[Test]
    public function resetting_another_tenants_category_does_nothing_to_yours(): void
    {
        $this->settings->save($this->tenantA, ['primary_color' => '#112233'], $this->ada);

        self::assertSame(0, $this->settings->resetCategory($this->tenantB, SettingCategory::Branding));
        self::assertSame('#112233', $this->settings->forTenant($this->tenantA)->string(SettingKey::PrimaryColour));
    }

    #[Test]
    public function the_history_names_who_changed_what(): void
    {
        $this->settings->save($this->tenantA, ['show_powered_by' => 'false'], $this->ada);

        $history = $this->settings->history($this->tenantA);

        self::assertCount(1, $history);
        self::assertSame('show_powered_by', $history[0]['key']);
        self::assertSame('Show "powered by"', $history[0]['label']);
        self::assertSame('Ada Tester', $history[0]['changedBy']);
        // Parsed, so a boolean reads as one.
        self::assertFalse($history[0]['value']);
        self::assertNotNull($history[0]['changedAt']);
    }

    #[Test]
    public function a_change_with_no_named_actor_is_still_recorded(): void
    {
        // A console command or a migration has no user behind it.
        $this->settings->save($this->tenantA, ['primary_color' => '#112233'], null);

        $history = $this->settings->history($this->tenantA);

        self::assertCount(1, $history);
        self::assertNull($history[0]['changedBy']);
    }

    #[Test]
    public function deleting_the_person_who_changed_a_setting_keeps_the_setting(): void
    {
        $this->settings->save($this->tenantA, ['primary_color' => '#112233'], $this->ada);

        self::$connection?->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $this->ada]);

        $settings = $this->settings->forTenant($this->tenantA);

        self::assertSame('#112233', $settings->string(SettingKey::PrimaryColour));
        self::assertNull($this->settings->history($this->tenantA)[0]['changedBy']);
    }

    #[Test]
    public function removing_a_tenant_removes_its_settings(): void
    {
        $this->settings->save($this->tenantA, ['primary_color' => '#112233'], $this->ada);

        self::$connection?->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $this->tenantA]);
        self::$connection?->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $this->tenantA]);

        self::assertSame(0, $this->rowCount($this->tenantA));
    }

    // --- helpers ----------------------------------------------------------

    private function rowCount(string $tenantId): int
    {
        return (int) self::$connection?->fetchOne(
            'SELECT COUNT(*) FROM tenant_settings WHERE tenant_id = :id',
            ['id' => $tenantId],
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'Test ' . $prefix, 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $first): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@set.test',
                'f'  => $first,
            ],
        );

        return $id;
    }

    private static function connect(): ?Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5442),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? 'central_lms',
            ]);

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
