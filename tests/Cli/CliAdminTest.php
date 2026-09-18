<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf admin:create` for real by shelling out against a
 * throwaway SQLite database (DB_DSN override), feeding the interactive
 * password prompts over stdin, then reading the result back through the
 * repository. STDIN is a pipe (not a TTY), so the command takes its visible
 * fallback path. Never touches the dev database or any network.
 */
final class CliAdminTest extends TestCase
{
    private string $dbPath;
    private string $osf;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'osf_admin_');
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

    public function testCreatesAdminWithValidPassword(): void
    {
        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=The Boss'],
            "a-strong-password\na-strong-password\n"
        );

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Created admin', $result['stdout']);
        // Never echoes the password.
        self::assertStringNotContainsString('a-strong-password', $result['stdout']);

        $admin = $this->repo()->findByEmail('boss@example.com');
        self::assertNotNull($admin);
        self::assertSame('The Boss', $admin['display_name']);
        self::assertTrue((new PasswordHasher())->verify('a-strong-password', $admin['password_hash']));
    }

    public function testRefusesWeakPassword(): void
    {
        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=Boss'],
            "short\nshort\n"
        );

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('at least 12 characters', $result['stderr']);
        self::assertNull($this->repo()->findByEmail('boss@example.com'));
    }

    public function testRefusesMismatchedPasswords(): void
    {
        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=Boss'],
            "a-strong-password\na-different-password\n"
        );

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('do not match', $result['stderr']);
        self::assertNull($this->repo()->findByEmail('boss@example.com'));
    }

    public function testRefusesDuplicateEmail(): void
    {
        $this->repo()->createAdmin('boss@example.com', 'Boss', 'a-strong-password');

        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=Boss'],
            "another-strong-password\nanother-strong-password\n"
        );

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('already exists', $result['stderr']);
    }

    // --- admin:delete -------------------------------------------------

    public function testDeletesAdminById(): void
    {
        $this->repo()->createAdmin('boss@example.com', 'Boss', 'a-strong-password');
        $target = $this->repo()->createAdmin('second@example.com', 'Second', 'a-strong-password');

        $result = $this->osf(['admin:delete', (string) $target['id']], '');

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Deleted admin #' . $target['id'], $result['stdout']);
        self::assertStringContainsString('second@example.com', $result['stdout']);
        self::assertNull($this->repo()->findById($target['id']));
    }

    public function testRefusesLastActiveAdmin(): void
    {
        $solo = $this->repo()->createAdmin('boss@example.com', 'Boss', 'a-strong-password');

        $result = $this->osf(['admin:delete', (string) $solo['id']], '');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('last active admin', $result['stderr']);
        self::assertNotNull($this->repo()->findById($solo['id']));
    }

    public function testDeletingInactiveAdminIsAlwaysAllowed(): void
    {
        $this->repo()->createAdmin('boss@example.com', 'Boss', 'a-strong-password');
        $ghost = $this->repo()->createAdmin('ghost@example.com', 'Ghost', 'a-strong-password');
        $this->repo()->setActive($ghost['id'], false);

        $result = $this->osf(['admin:delete', (string) $ghost['id']], '');

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertNull($this->repo()->findById($ghost['id']));
    }

    public function testRefusesUnknownId(): void
    {
        $result = $this->osf(['admin:delete', '999'], '');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('No admin found', $result['stderr']);
    }

    public function testRefusesNonNumericId(): void
    {
        $result = $this->osf(['admin:delete', 'not-a-number'], '');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('requires a numeric admin ID', $result['stderr']);
    }

    // --- admin:reset-2fa ----------------------------------------------

    public function testResetTotpUnenrolsAndClearsRecoveryCodes(): void
    {
        $repo = $this->repo();
        $admin = $repo->createAdmin('boss@example.com', 'Boss', 'a-strong-password');

        // Enrol: a stored secret, enabled enforcement, and a batch of recovery
        // codes we can prove work before the reset.
        $repo->setTotp($admin['id'], 'JBSWY3DPEHPK3PXP');
        $repo->enableTotp($admin['id']);
        $batch = (new RecoveryCodes(new PasswordHasher()))->generate();
        $repo->setRecoveryCodes($admin['id'], $batch['hashes']);
        $aCode = $batch['plain'][0];

        $result = $this->osf(['admin:reset-2fa', (string) $admin['id']], '');

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Reset two-factor authentication for admin #' . $admin['id'], $result['stdout']);
        self::assertStringContainsString('boss@example.com', $result['stdout']);

        // Re-read: 2FA is unenrolled and both secret and codes are cleared.
        $after = $this->repo()->findById($admin['id']);
        self::assertNotNull($after);
        self::assertSame(0, $after['totp_enabled']);
        self::assertNull($after['totp_secret']);
        self::assertNull($after['recovery_codes']);
    }

    public function testResetTotpMakesOldRecoveryCodesStopWorking(): void
    {
        $repo = $this->repo();
        $admin = $repo->createAdmin('boss@example.com', 'Boss', 'a-strong-password');
        $repo->setTotp($admin['id'], 'JBSWY3DPEHPK3PXP');
        $repo->enableTotp($admin['id']);
        $batch = (new RecoveryCodes(new PasswordHasher()))->generate();
        $repo->setRecoveryCodes($admin['id'], $batch['hashes']);
        $aCode = $batch['plain'][1];

        // The code works before the reset (sanity check on the fixture).
        self::assertTrue($this->repo()->consumeRecoveryCode($admin['id'], $batch['plain'][0]));

        $result = $this->osf(['admin:reset-2fa', (string) $admin['id']], '');
        self::assertSame(0, $result['code'], $result['stderr']);

        // A previously valid, still-unused code no longer works after the reset.
        self::assertFalse($this->repo()->consumeRecoveryCode($admin['id'], $aCode));
    }

    public function testResetTotpRefusesUnknownId(): void
    {
        $result = $this->osf(['admin:reset-2fa', '999'], '');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('No admin found', $result['stderr']);
    }

    public function testResetTotpRefusesNonNumericId(): void
    {
        $result = $this->osf(['admin:reset-2fa', 'nope'], '');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('requires a numeric admin ID', $result['stderr']);
    }

    private function repo(): AdminRepository
    {
        $hasher = new PasswordHasher();
        $db = Database::connect('sqlite:' . $this->dbPath);
        // The CLI runs migrations; ensure the schema exists for direct reads too.
        (new \OpenSendForm\Storage\MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        return new AdminRepository($db, $hasher, new RecoveryCodes($hasher));
    }

    /**
     * @param array<int, string> $args
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(array $args, string $stdin): array
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

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
