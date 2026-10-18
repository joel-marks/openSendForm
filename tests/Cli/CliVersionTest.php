<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use OpenSendForm\Version;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf version` by shelling out against a throwaway SQLite
 * database (DB_DSN override). Because that database is empty, no migrations
 * have been applied yet, so the command must report schema version 0 and every
 * migration file as pending — proving it reads the live schema state WITHOUT
 * auto-migrating first. Never touches the dev database or any network.
 */
final class CliVersionTest extends TestCase
{
    private string $dbPath;
    private string $osf;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'osf_version_');
        self::assertNotFalse($tmp);
        $this->dbPath = $tmp;
        $this->osf = dirname(__DIR__, 2) . '/bin/osf';
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testPrintsVersionAndFreshSchemaState(): void
    {
        $result = $this->osf(['version']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('openSendForm ' . Version::STRING, $result['stdout']);
        self::assertStringContainsString('Schema version:     0', $result['stdout']);

        // A fresh database has every migration pending.
        $migrationCount = count(glob(dirname(__DIR__, 2) . '/migrations/*.sql'));
        self::assertGreaterThan(0, $migrationCount);
        self::assertStringContainsString(
            'Pending migrations: ' . $migrationCount,
            $result['stdout']
        );
    }

    /**
     * @param array<int, string> $args
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->osf);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $env = getenv();
        $env['DB_DSN'] = 'sqlite:' . $this->dbPath;
        $env['XDEBUG_MODE'] = 'off';

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
