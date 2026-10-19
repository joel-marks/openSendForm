<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Monitor;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Install\ConfigWriter;
use OpenSendForm\Monitor\MonitorRepository;
use OpenSendForm\Monitor\MonitorService;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Tests\Support\FakeDnsChecker;
use OpenSendForm\Tests\Support\FakeMailer;
use OpenSendForm\Tests\Support\FakeSleeper;
use OpenSendForm\Tests\Support\FixedClock;
use OpenSendForm\Tests\Support\InProcessHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage of the zero-noise monitor. The dark canary drives a REAL
 * submission through an in-process app (token -> min-time -> POST -> store ->
 * delivery) but its delivery is addressed to the service's own From address, so
 * no human is ever contacted. Passive per-form observation reads real delivery
 * outcomes and alerts only on a transition. No live server, no wall-clock sleep.
 */
final class MonitorServiceTest extends TestCase
{
    private const MONITOR_SECRET = 'monitor-shared-secret';
    private const BASE_URL = 'http://localhost:8080';
    private const RECIPIENT = 'owner@example.com';
    private const FROM_ADDRESS = 'noreply@service.example';
    private const ADMIN_EMAIL = 'admin@example.com';

    private Database $db;
    private FixedClock $clock;
    private FakeDnsChecker $dns;
    private FormRepository $forms;
    private SubmissionRepository $submissions;
    private MonitorRepository $checks;
    private string $configDir;
    private int $canaryId;
    private FakeSleeper $sleeper;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->clock = new FixedClock(1_700_000_000);
        $this->dns = new FakeDnsChecker(true);
        $this->forms = new FormRepository($this->db);
        $this->submissions = new SubmissionRepository($this->db);
        $this->checks = new MonitorRepository($this->db);

        $form = $this->forms->createForm('Contact', self::RECIPIENT, ['https://example.com']);
        $this->canaryId = (int) $form['id'];

        // An admin so the alert falls back to a real recipient (MONITOR_ALERT_EMAIL empty).
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        (new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher)))
            ->createAdmin(self::ADMIN_EMAIL, 'The Boss', 'correct-horse-battery-staple');

        $this->configDir = sys_get_temp_dir() . '/osf_monitor_' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->configDir);
    }

    // --- The dark canary ---------------------------------------------------

    public function testPassingCanaryIsDeliveredDarkToTheServiceAddress(): void
    {
        $delivery = new FakeMailer();
        $alerts = new FakeMailer();
        $summary = $this->monitor($delivery, $alerts)->run();

        self::assertTrue($summary['canary_ran']);
        self::assertTrue($summary['canary_ok']);
        self::assertSame(0, $summary['alerts_sent']);

        // The synthetic submission reached 'sent' and is flagged synthetic.
        $row = $this->submissions->newestSyntheticForForm($this->canaryId, 0);
        self::assertNotNull($row);
        self::assertSame('sent', (string) $row['status']);

        // The SMTP transaction genuinely happened, but was addressed to the
        // service's OWN From address - never the form's real recipient.
        self::assertSame(1, $delivery->callCount());
        self::assertSame(self::FROM_ADDRESS, $delivery->lastCall()['to']);
        self::assertNotSame(self::RECIPIENT, $delivery->lastCall()['to']);
        self::assertStringContainsString(MonitorService::MARKER, $delivery->lastCall()['textBody']);

        // Recorded ok under the canary kind; no alert email on a first-ever pass.
        self::assertTrue($this->checks->lastResultForForm($this->canaryId, MonitorRepository::KIND_CANARY));
        self::assertSame(0, $alerts->callCount());
    }

    public function testCanaryNeverDeliversToAnyFormRecipient(): void
    {
        // Two active forms with distinct recipients; whichever is the canary,
        // the delivery must not be addressed to any form's real recipient.
        $this->forms->createForm('Second', 'two@example.com', ['https://two.example.com']);

        $delivery = new FakeMailer();
        $this->monitor($delivery, new FakeMailer())->run();

        foreach ($delivery->calls as $call) {
            self::assertNotSame(self::RECIPIENT, $call['to']);
            self::assertNotSame('two@example.com', $call['to']);
            self::assertSame(self::FROM_ADDRESS, $call['to']);
        }
    }

    public function testFailingCanaryDeliverySendsOneAlertToTheAdmin(): void
    {
        $delivery = new FakeMailer();
        $delivery->alwaysFail('smtp is down');
        $alerts = new FakeMailer();

        $summary = $this->monitor($delivery, $alerts)->run();

        self::assertFalse($summary['canary_ok']);
        self::assertSame(1, $summary['alerts_sent']);
        self::assertNull($summary['alert_error']);

        self::assertSame(1, $alerts->callCount());
        self::assertSame(self::ADMIN_EMAIL, $alerts->lastCall()['to']);
        self::assertStringContainsString('FAILURE', $alerts->lastCall()['subject']);

        self::assertFalse($this->checks->lastResultForForm($this->canaryId, MonitorRepository::KIND_CANARY));
    }

    public function testNoRepeatCanaryAlertWhileFailingThenRecoveryOnRestore(): void
    {
        // Fail the first two delivery attempts, succeed after.
        $delivery = new FakeMailer();
        $delivery->failFirst(2, 'temporary outage');
        $alerts = new FakeMailer();
        $monitor = $this->monitor($delivery, $alerts);

        $monitor->run(); // fail -> one alert
        self::assertSame(1, $alerts->callCount());
        self::assertStringContainsString('FAILURE', $alerts->calls[0]['subject']);

        $monitor->run(); // still failing -> NO new alert
        self::assertSame(1, $alerts->callCount());

        $monitor->run(); // recovered -> recovery email
        self::assertSame(2, $alerts->callCount());
        self::assertStringContainsString('RECOVERED', $alerts->calls[1]['subject']);
        self::assertTrue($this->checks->lastResultForForm($this->canaryId, MonitorRepository::KIND_CANARY));
    }

    public function testAlertSendFailureIsReportedForCronBackstop(): void
    {
        $delivery = new FakeMailer();
        $delivery->alwaysFail();
        $alerts = new FakeMailer();
        $alerts->alwaysFail('alert smtp refused');

        $summary = $this->monitor($delivery, $alerts)->run();

        // The canary failed AND the alert could not be sent -> reported so the
        // CLI exits nonzero and cron output becomes the backstop.
        self::assertFalse($summary['canary_ok']);
        self::assertSame(0, $summary['alerts_sent']);
        self::assertNotNull($summary['alert_error']);
        self::assertStringContainsString('alert smtp refused', (string) $summary['alert_error']);
    }

    public function testUnreachableEndpointIsAFailedCanaryThatAlerts(): void
    {
        $delivery = new FakeMailer();
        $alerts = new FakeMailer();
        $monitor = $this->monitor($delivery, $alerts, failTransport: true);

        $summary = $monitor->run();

        self::assertTrue($summary['canary_ran']);
        self::assertFalse($summary['canary_ok']);
        self::assertSame(1, $summary['alerts_sent']);
        self::assertStringContainsString('token fetch failed', (string) $summary['canary_detail']);
    }

    // --- Passive per-form observation -------------------------------------

    public function testRealFailedDeliveryAlertsOnceThenDeadThenRecovers(): void
    {
        $alerts = new FakeMailer();
        $monitor = $this->monitor(new FakeMailer(), $alerts);

        // A real (non-synthetic) submission for the canary form that FAILED to
        // deliver and is retrying. The canary itself passes (own mailer ok).
        $failedId = $this->realSubmission('failed');

        $monitor->run();
        // One passive alert for the first entry into 'failed'. (The canary pass
        // is silent.)
        self::assertSame(1, $alerts->callCount());
        self::assertStringContainsString('FAILURE', $alerts->calls[0]['subject']);
        self::assertSame(self::ADMIN_EMAIL, $alerts->calls[0]['to']);

        // Still failing on the next run -> NO repeat alert.
        $monitor->run();
        self::assertSame(1, $alerts->callCount());

        // The same submission escalates to 'dead' -> one alert about that.
        $this->db->execute('UPDATE submissions SET status = :s WHERE id = :id', ['s' => 'dead', 'id' => $failedId]);
        $monitor->run();
        self::assertSame(2, $alerts->callCount());
        self::assertStringContainsString('UNDELIVERABLE', $alerts->calls[1]['subject']);

        // A later real submission delivers successfully -> recovery notice.
        $this->realSubmission('sent');
        $monitor->run();
        self::assertSame(3, $alerts->callCount());
        self::assertStringContainsString('RECOVERED', $alerts->calls[2]['subject']);
    }

    public function testPassiveObservationIgnoresSyntheticRows(): void
    {
        // A failed SYNTHETIC row must not trigger any passive per-form alert -
        // only real traffic drives passive observation.
        $this->submissions->recordSubmission(
            $this->canaryId, '127.0.0.1', null, null, '{}', 'failed', true
        );

        $alerts = new FakeMailer();
        $summary = $this->monitor(new FakeMailer(), $alerts)->run();

        self::assertSame(0, $summary['passive_transitions']);
        self::assertSame(0, $alerts->callCount());
    }

    public function testFailingFormsReflectsPassiveStateForTheDashboardBanner(): void
    {
        $this->realSubmission('failed');
        $this->monitor(new FakeMailer(), new FakeMailer())->run();

        $failing = $this->checks->failingForms();
        self::assertCount(1, $failing);
        self::assertSame($this->canaryId, $failing[0]['form_id']);
    }

    // --- Purge / secret / status ------------------------------------------

    public function testExpiredSyntheticsArePurged(): void
    {
        // An old synthetic probe from a previous run.
        $oldId = $this->submissions->recordSubmission(
            $this->canaryId, '127.0.0.1', null, null, '{}', 'sent', true
        );
        $this->db->execute(
            'UPDATE submissions SET created_at = :c WHERE id = :id',
            ['c' => '2000-01-01 00:00:00', 'id' => $oldId]
        );

        $summary = $this->monitor(new FakeMailer(), new FakeMailer())->run();

        self::assertGreaterThanOrEqual(1, $summary['purged']);
        $stale = $this->db->fetchAll(
            "SELECT id FROM submissions WHERE created_at = '2000-01-01 00:00:00'"
        );
        self::assertSame([], $stale);
    }

    public function testSecretIsGeneratedAndWrittenBackOnFirstRun(): void
    {
        // No secret configured yet, and no active forms so no canary needs it.
        $this->forms->setActive($this->canaryId, false);
        $configPath = $this->configDir . '/config.php';

        $summary = $this->monitor(new FakeMailer(), new FakeMailer(), secret: '', configPath: $configPath)->run();

        self::assertTrue($summary['secret_generated']);
        self::assertFalse($summary['canary_ran']);

        self::assertFileExists($configPath);
        $written = require $configPath;
        self::assertArrayHasKey('MONITOR_SECRET', $written);
        self::assertNotSame('', (string) $written['MONITOR_SECRET']);
    }

    public function testStatusReportsCanaryAndPerFormOutcome(): void
    {
        $this->monitor(new FakeMailer(), new FakeMailer())->run();

        $status = $this->monitor(new FakeMailer(), new FakeMailer())->status();

        // Canary block reflects the hourly heartbeat.
        self::assertNotNull($status['canary']);
        self::assertSame($this->canaryId, $status['canary']['form_id']);
        self::assertTrue($status['canary']['last_ok']);

        // Per-form outcome: the canary's own real traffic is none yet (synthetic
        // excluded), so the outcome is null.
        self::assertCount(1, $status['forms']);
        self::assertSame($this->canaryId, $status['forms'][0]['form_id']);
        self::assertTrue($status['forms'][0]['is_canary']);
        self::assertNull($status['forms'][0]['outcome']);
    }

    public function testStatusShowsRealDeliveryOutcomeWhenKnown(): void
    {
        $this->realSubmission('sent');
        $status = $this->monitor(new FakeMailer(), new FakeMailer())->status();

        self::assertSame('sent', $status['forms'][0]['outcome']);
    }

    // --- Run cost (poll, min-time) ----------------------------------------

    public function testImmediateSentShortCircuitsTheDeliveryPoll(): void
    {
        // Delivery is synchronous, so the row is 'sent' on the first read: the
        // only sleep is the min-submit-time wait, with NO delivery-poll re-checks.
        $summary = $this->monitor(new FakeMailer(), new FakeMailer())->run();

        self::assertTrue($summary['canary_ok']);
        self::assertSame([4], $this->sleeper->sleeps); // MIN_SUBMIT_SECONDS 3 + margin 1
    }

    public function testMinTimeWaitDerivesFromConfiguredMinSubmitSeconds(): void
    {
        $summary = $this->monitor(
            new FakeMailer(),
            new FakeMailer(),
            overrides: ['MIN_SUBMIT_SECONDS' => '7']
        )->run();

        self::assertTrue($summary['canary_ok']);
        self::assertSame([8], $this->sleeper->sleeps); // 7 + margin 1, no poll
    }

    public function testRowStillReceivedAtTimeoutIsAFailure(): void
    {
        // Mail off -> DeliveryStage skips -> the synthetic row stays 'received',
        // so the poll runs the full send-timeout window and the canary honestly
        // fails (delivery did not happen in-request).
        $summary = $this->monitor(
            new FakeMailer(),
            new FakeMailer(),
            overrides: ['MAIL_ENABLED' => '0', 'MONITOR_SEND_TIMEOUT_SECONDS' => '10']
        )->run();

        self::assertFalse($summary['canary_ok']);
        self::assertStringContainsString("did not reach 'sent'", (string) $summary['canary_detail']);
        self::assertSame([4, 2, 2, 2, 2, 2], $this->sleeper->sleeps);
    }

    // --- Harness ----------------------------------------------------------

    /**
     * Insert a real (non-synthetic) submission for the canary form in a given
     * delivery status, returning its id.
     */
    private function realSubmission(string $status): int
    {
        return $this->submissions->recordSubmission(
            $this->canaryId,
            '203.0.113.9',
            'https://example.com',
            'ua',
            '{"email":"a@b.test"}',
            $status,
            false
        );
    }

    /**
     * @param array<string, string> $overrides Extra/override config values.
     */
    private function monitor(
        FakeMailer $delivery,
        FakeMailer $alerts,
        bool $failTransport = false,
        string $secret = self::MONITOR_SECRET,
        ?string $configPath = null,
        array $overrides = []
    ): MonitorService {
        $config = Config::fromValues(array_merge([
            'APP_ENV'            => 'testing',
            'APP_SECRET'         => 'app-signing-secret',
            'MONITOR_SECRET'     => $secret,
            'MONITOR_BASE_URL'   => self::BASE_URL,
            // The dark-canary send address (the service's own From).
            'MAIL_FROM_ADDRESS'  => self::FROM_ADDRESS,
            // Keep rate limits out of the way of repeated synthetic POSTs.
            'RATE_IP_PER_MINUTE' => '1000',
            'RATE_FORM_PER_HOUR' => '1000',
            'MIN_SUBMIT_SECONDS' => '3',
        ], $overrides));

        $app = AppFactory::create($config, $this->db, $this->dns, $this->clock, $delivery);
        $http = new InProcessHttpClient($app, self::BASE_URL);
        $http->failTransport = $failTransport;

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->sleeper = new FakeSleeper($this->clock);

        return new MonitorService(
            $http,
            $this->forms,
            $this->submissions,
            $this->checks,
            $alerts,
            new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher)),
            $config,
            new ConfigWriter($configPath ?? ($this->configDir . '/config.php')),
            $this->clock,
            $this->sleeper
        );
    }
}
