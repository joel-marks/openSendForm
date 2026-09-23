<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use OpenSendForm\Admin\TemplateRenderer;
use OpenSendForm\Install\Requirements;
use PHPUnit\Framework\TestCase;

/**
 * Renders the installer Requirements step (step 2 of the onboarding stepper,
 * split out of the old combined welcome screen) directly to prove the hosting
 * check drives the Continue control: a failing check disables it (and shows the
 * remedy), while an all-clear set links onward to the database step.
 */
final class WelcomeTemplateTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates/install');
    }

    public function testFailingRequirementDisablesContinueAndShowsRemedy(): void
    {
        $html = $this->renderer->render('requirements', [
            'title'       => 'Requirements',
            'stepNo'      => 2,
            'stepCount'   => 7,
            'hasFailures' => true,
            'flashes'     => [],
            'checks'      => [[
                'key'    => 'openssl',
                'label'  => 'Encryption support (openssl)',
                'status' => Requirements::FAIL,
                'remedy' => 'Enable the openssl extension in cPanel.',
            ]],
        ]);

        self::assertStringContainsString('osf-disabled-link', $html);
        self::assertStringContainsString('Enable the openssl extension in cPanel.', $html);
        self::assertStringNotContainsString('href="/install/database"', $html);
        self::assertStringContainsString('Action needed', $html);
    }

    public function testAllClearRequirementsLinkOnward(): void
    {
        $html = $this->renderer->render('requirements', [
            'title'       => 'Requirements',
            'stepNo'      => 2,
            'stepCount'   => 7,
            'hasFailures' => false,
            'flashes'     => [],
            'checks'      => [[
                'key'    => 'php_version',
                'label'  => 'PHP 8.1.27',
                'status' => Requirements::PASS,
                'remedy' => '',
            ]],
        ]);

        self::assertStringContainsString('href="/install/database"', $html);
        self::assertStringNotContainsString('osf-disabled-link', $html);
    }
}
