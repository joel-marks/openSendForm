<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Standing copy rule (CLAUDE.md): user-facing copy never uses the em dash
 * (—) or its HTML entity (&mdash;); a plain hyphen "-" is used instead, or
 * the sentence is restructured. Source-code comments/docblocks are exempt,
 * so PHP files are tokenized and only non-comment tokens are checked —
 * templates render their comments to developers, never to the browser.
 */
final class NoEmDashTest extends TestCase
{
    private const EM_DASH = "\xE2\x80\x94"; // U+2014 EM DASH

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    public function testTemplatesCarryNoEmDash(): void
    {
        $files = array_merge(
            glob(self::root() . '/templates/admin/*.php'),
            glob(self::root() . '/templates/install/*.php'),
            glob(self::root() . '/templates/_shared/*.php')
        );
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertPhpFileHasNoEmDashOutsideComments($file);
        }
    }

    public function testKeyPhpStringSourcesCarryNoEmDash(): void
    {
        // Files known to build user-facing CLI output, flash/error messages,
        // or form-control labels shown in the browser.
        $files = [
            'bin/osf',
            'src/Admin/MailController.php',
            'src/Admin/SubmissionsController.php',
            'src/Install/InstallController.php',
            'src/Install/InstallerService.php',
            'src/Install/Requirements.php',
            'src/Mail/DeliverabilityChecker.php',
            'src/Mail/MailSettingsForm.php',
            'src/Http/SubmitHtmlPage.php',
        ];

        foreach ($files as $relative) {
            $this->assertPhpFileHasNoEmDashOutsideComments(self::root() . '/' . $relative);
        }
    }

    public function testReadmeCarriesNoEmDash(): void
    {
        $readme = (string) file_get_contents(self::root() . '/README.md');
        self::assertStringNotContainsString(self::EM_DASH, $readme, 'README.md uses an em dash; use a plain hyphen "-" instead');
    }

    private function assertPhpFileHasNoEmDashOutsideComments(string $file): void
    {
        $source = (string) file_get_contents($file);
        $code = self::stripPhpComments($source);

        self::assertStringNotContainsString(
            self::EM_DASH,
            $code,
            basename($file) . ' uses an em dash outside a comment; use a plain hyphen "-" instead'
        );
        self::assertStringNotContainsString(
            '&mdash;',
            $code,
            basename($file) . ' uses the &mdash; entity; use a plain hyphen "-" instead'
        );
    }

    /**
     * Returns the file's source with every T_COMMENT/T_DOC_COMMENT token
     * removed (token-level, so a dash inside a string literal that merely
     * looks like a comment delimiter is never mistaken for one).
     */
    private static function stripPhpComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $text;
            } else {
                $out .= $token;
            }
        }

        return $out;
    }
}
