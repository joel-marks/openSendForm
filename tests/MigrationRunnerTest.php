<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private function migrationsPath(): string
    {
        return dirname(__DIR__) . '/migrations';
    }

    public function testMigratesCleanlyOnFreshDatabase(): void
    {
        $db = Database::connect('sqlite::memory:');
        $runner = new MigrationRunner($db, $this->migrationsPath());

        $applied = $runner->migrate();

        self::assertContains('001_create_schema_migrations.sql', $applied);

        // schema_migrations table now exists and records every migration.
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $runner->appliedVersions());
    }

    public function testIsIdempotentOnSecondRun(): void
    {
        $db = Database::connect('sqlite::memory:');
        $runner = new MigrationRunner($db, $this->migrationsPath());

        $firstRun = $runner->migrate();
        self::assertNotEmpty($firstRun);

        $secondRun = $runner->migrate();

        // Nothing pending the second time around.
        self::assertSame([], $secondRun);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $runner->appliedVersions());
    }

    public function testAppliedVersionsEmptyBeforeMigrating(): void
    {
        $db = Database::connect('sqlite::memory:');
        $runner = new MigrationRunner($db, $this->migrationsPath());

        self::assertSame([], $runner->appliedVersions());
    }
}
