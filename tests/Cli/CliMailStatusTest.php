<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf mail:status` for real by shelling out with an
 * OSF_BASE_DIR pointed at a throwaway directory. The mail settings are supplied
 * through the environment, and the From address is left as the (domain-less)
 * default so the command reports its config state and then gracefully skips the
 * live DNS lookups — the test never touches the network or real DNS.
 */
final class CliMailStatusTest extends TestCase
{
    private string $base;
    private string $osf;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf_mailstatus_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var/data', 0775, true);
        $this->osf = dirname(__DIR__, 2) . '/bin/osf';
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function testPrintsConfigStateAndSkipsWhenNoValidDomain(): void
    {
        $result = $this->osf([
            'MAIL_ENABLED' => '0',
            'SMTP_HOST'    => 'smtp.test.internal',
            'SMTP_PORT'    => '2525',
            // Domain-less default From address → deliverability lookups skipped.
            'MAIL_FROM_ADDRESS' => 'noreply@localhost',
        ]);

        self::assertSame(0, $result['code'], $result['stderr']);
        // Clear heading + plain-language verdict for a non-technical operator.
        self::assertStringContainsString('Email status', $result['stdout']);
        self::assertStringContainsString('Email sending is OFF', $result['stdout']);
        // Machine-parsable detail lines remain beneath.
        self::assertStringContainsString('Mail configuration', $result['stdout']);
        self::assertStringContainsString('Sending:       off', $result['stdout']);
        self::assertStringContainsString('set (smtp.test.internal)', $result['stdout']);
        self::assertStringContainsString('2525', $result['stdout']);
        self::assertStringContainsString('No valid From domain', $result['stdout']);
    }

    public function testNeverPrintsSecrets(): void
    {
        $result = $this->osf([
            'SMTP_USER' => 'me@example.com',
            'SMTP_PASS' => 'super-secret-password',
            'MAIL_FROM_ADDRESS' => 'noreply@localhost',
        ]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('super-secret-password', $result['stdout']);
        // The presence of auth is reported without revealing the credentials.
        self::assertStringContainsString('username set', $result['stdout']);
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(array $extraEnv): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->osf) . ' mail:status';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['OSF_BASE_DIR'] = $this->base;
        $env['XDEBUG_MODE'] = 'off';
        // A writable SQLite DB in the throwaway dir (the CLI boots the DB first).
        $env['DB_DSN'] = 'sqlite:' . $this->base . '/var/data/test.sqlite';
        foreach ($extraEnv as $k => $v) {
            $env[$k] = $v;
        }

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
