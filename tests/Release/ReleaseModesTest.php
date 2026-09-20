<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Release;

use PHPUnit\Framework\TestCase;

/**
 * The release permissions guarantee (task 6): the build stamps a fixed mode
 * policy into the zip (dirs 755, files 644, bin/osf 755) and the verifier reads
 * those stored modes back and asserts them. This drives the shared plumbing
 * (osf_zip_dir + osf_release_mode + osf_verify_modes) against a tiny synthetic
 * package, without running the real git-archive/composer build.
 */
final class ReleaseModesTest extends TestCase
{
    private string $base;

    public static function setUpBeforeClass(): void
    {
        // Loads osf_zip_dir + osf_release_mode, and (via verify) osf_verify_modes.
        // The guarded main() in each script does not run when merely required.
        require_once dirname(__DIR__, 2) . '/bin/release_lib.php';
        require_once dirname(__DIR__, 2) . '/bin/verify-release.php';
    }

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            self::markTestSkipped('ext-zip required to read stored modes');
        }
        $this->base = sys_get_temp_dir() . '/osf_modes_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/pkg/bin', 0777, true);
        mkdir($this->base . '/pkg/public', 0777, true);
        file_put_contents($this->base . '/pkg/bin/osf', "#!/usr/bin/env php\n");
        file_put_contents($this->base . '/pkg/public/index.php', "<?php\n");
        file_put_contents($this->base . '/pkg/LICENSE', "x\n");
    }

    protected function tearDown(): void
    {
        osf_rrmdir($this->base);
    }

    public function testPolicyIsDirs755Files644AndOsf755(): void
    {
        self::assertSame(0755, osf_release_mode('', true));
        self::assertSame(0755, osf_release_mode('some/dir', true));
        self::assertSame(0755, osf_release_mode('bin/osf', false));
        self::assertSame(0644, osf_release_mode('public/index.php', false));
        self::assertSame(0644, osf_release_mode('LICENSE', false));
    }

    public function testStampedArchiveVerifiesClean(): void
    {
        $zip = $this->base . '/pkg.zip';
        osf_zip_dir($this->base, 'pkg', $zip, 'osf_release_mode');

        self::assertSame([], osf_verify_modes($zip, 'pkg'));
    }

    public function testUnstampedArchiveIsCaught(): void
    {
        // Built WITHOUT the mode callback: the verifier must notice the missing
        // or wrong stored modes rather than passing silently.
        $zip = $this->base . '/pkg-bad.zip';
        osf_zip_dir($this->base, 'pkg', $zip);

        self::assertNotSame([], osf_verify_modes($zip, 'pkg'));
    }
}
