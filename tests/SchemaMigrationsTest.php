<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class SchemaMigrationsTest extends TestCase
{
    private function migrationsPath(): string
    {
        return dirname(__DIR__) . '/migrations';
    }

    public function testFormsAndSubmissionsMigrationsApply(): void
    {
        $db = Database::connect('sqlite::memory:');
        $runner = new MigrationRunner($db, $this->migrationsPath());

        $applied = $runner->migrate();

        self::assertContains('002_create_forms.sql', $applied);
        self::assertContains('003_create_submissions.sql', $applied);
        self::assertContains('004_create_rate_counters.sql', $applied);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $runner->appliedVersions());

        // All tables now exist and are queryable.
        self::assertTableExists($db, 'forms');
        self::assertTableExists($db, 'submissions');
        self::assertTableExists($db, 'rate_counters');
    }

    public function testMigrationsAreIdempotent(): void
    {
        $db = Database::connect('sqlite::memory:');
        $runner = new MigrationRunner($db, $this->migrationsPath());

        $runner->migrate();
        $secondRun = $runner->migrate();

        self::assertSame([], $secondRun);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $runner->appliedVersions());
    }

    public function testSubmissionsIndexExists(): void
    {
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, $this->migrationsPath()))->migrate();

        $row = $db->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = :name",
            ['name' => 'idx_submissions_form_created']
        );

        self::assertNotNull($row);
    }

    public function testTurnstileColumnsExistOnForms(): void
    {
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, $this->migrationsPath()))->migrate();

        $columns = array_column(
            $db->fetchAll('PRAGMA table_info(forms)'),
            'name'
        );

        self::assertContains('turnstile_sitekey', $columns);
        self::assertContains('turnstile_secret', $columns);
    }

    public function testAllowNojsColumnExistsOnForms(): void
    {
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, $this->migrationsPath()))->migrate();

        $columns = array_column(
            $db->fetchAll('PRAGMA table_info(forms)'),
            'name'
        );

        self::assertContains('allow_nojs', $columns);
    }

    public function testAdminsTableExistsWithExpectedColumns(): void
    {
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, $this->migrationsPath()))->migrate();

        self::assertTableExists($db, 'admins');

        $columns = array_column($db->fetchAll('PRAGMA table_info(admins)'), 'name');
        foreach (
            ['id', 'email', 'display_name', 'password_hash', 'totp_secret',
             'totp_enabled', 'recovery_codes', 'is_active', 'created_at',
             'updated_at', 'last_login_at'] as $column
        ) {
            self::assertContains($column, $columns);
        }
    }

    private static function assertTableExists(Database $db, string $table): void
    {
        $row = $db->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table]
        );

        self::assertNotNull($row, "Expected table '{$table}' to exist.");
    }
}
