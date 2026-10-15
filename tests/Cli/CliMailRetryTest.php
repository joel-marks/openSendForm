<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf mail:retry` and its new --quiet cron mode for real by
 * shelling out against a throwaway SQLite database (DB_DSN override). Mail is
 * off in this environment, so a due submission is "attempted" but skipped — the
 * point is the stdout contract: --quiet stays silent when the sweep found
 * nothing to do and speaks up only when it attempted a delivery. Exit code is
 * always 0.
 */
final class CliMailRetryTest extends TestCase
{
    private string $dbPath;
    private string $osf;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'osf_retry_');
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

    public function testQuietPrintsNothingWhenNothingWasDue(): void
    {
        // Empty database: no submission is due, so --quiet prints nothing and
        // cPanel sends no mail. Exit 0.
        $this->db(); // ensure the schema exists

        $result = $this->osf(['mail:retry', '--quiet']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function testQuietPrintsSummaryWhenItAttemptedSomething(): void
    {
        // A never-attempted 'received' row is due; the quiet run attempts it
        // (skipped, since mail is off) and therefore prints its summary.
        $form = $this->forms()->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs()->recordSubmission($form['id'], '203.0.113.7', null, null);

        $result = $this->osf(['mail:retry', '--quiet']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('mail:retry — attempted 1', $result['stdout']);
    }

    public function testNonQuietAlwaysPrintsEvenWithNothingDue(): void
    {
        $this->db();

        $result = $this->osf(['mail:retry']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('mail:retry — attempted 0', $result['stdout']);
    }

    public function testUnknownOptionRejected(): void
    {
        $result = $this->osf(['mail:retry', '--nope']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('Unknown option: --nope', $result['stderr']);
    }

    // --- Helpers ----------------------------------------------------------

    private function db(): Database
    {
        $db = Database::connect('sqlite:' . $this->dbPath);
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        return $db;
    }

    private function forms(): FormRepository
    {
        return new FormRepository($this->db());
    }

    private function subs(): SubmissionRepository
    {
        return new SubmissionRepository($this->db());
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

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['DB_DSN'] = 'sqlite:' . $this->dbPath;
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
}
