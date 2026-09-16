<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf monitor:run` and `monitor:status` for real by shelling out
 * with OSF_BASE_DIR pointed at a throwaway install. With no forms the run needs
 * no network: it generates and writes back MONITOR_SECRET, purges nothing,
 * checks nothing and exits 0 — proving the wiring, the config write-back and the
 * usage text without a live server.
 */
final class CliMonitorTest extends TestCase
{
    private string $base;
    private string $osf;
    private string $configPath;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf_monitor_cli_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var/data', 0775, true);
        $this->osf = dirname(__DIR__, 2) . '/bin/osf';
        $this->configPath = $this->base . '/var/config.php';
        $this->dbPath = $this->base . '/var/data/test.sqlite';
        $this->markInstalled();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function testUsageListsMonitorCommands(): void
    {
        $result = $this->osf([]);

        // No command -> usage on stderr, exit 1.
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('monitor:run', $result['stderr']);
        self::assertStringContainsString('monitor:status', $result['stderr']);
        self::assertStringContainsString('monitor:run', $result['stderr']);
    }

    public function testStatusWithNoFormsReportsNothingToMonitor(): void
    {
        $result = $this->osf(['monitor:status']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('No active forms to monitor.', $result['stdout']);
    }

    public function testRunWithNoFormsGeneratesSecretAndExitsZero(): void
    {
        $result = $this->osf(['monitor:run']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Generated MONITOR_SECRET', $result['stdout']);
        self::assertStringContainsString('checked 0 form(s)', $result['stdout']);

        // The secret was written back to the config file.
        $written = require $this->configPath;
        self::assertArrayHasKey('MONITOR_SECRET', $written);
        self::assertNotSame('', (string) $written['MONITOR_SECRET']);

        // A second run finds the secret already present (no regeneration).
        $second = $this->osf(['monitor:run']);
        self::assertSame(0, $second['code'], $second['stderr']);
        self::assertStringNotContainsString('Generated MONITOR_SECRET', $second['stdout']);
    }

    private function markInstalled(): void
    {
        file_put_contents(
            $this->configPath,
            "<?php\nreturn ['DB_DSN' => 'sqlite:{$this->dbPath}'];\n"
        );
        file_put_contents(
            $this->base . '/var/install.lock',
            json_encode(['installed_at' => '2026-09-09 10:00:00', 'version' => '0.1.0']) . "\n"
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

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $env = getenv();
        $env['OSF_BASE_DIR'] = $this->base;
        $env['XDEBUG_MODE'] = 'off';

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
