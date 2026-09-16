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
 * End-to-end coverage of the monitor: it drives REAL submissions through an
 * in-process app (token -> min-time -> POST -> store -> delivery), polls the
 * stored row, records each check, alerts on ok<->fail transitions, purges
 * expired synthetics and generates the secret on first run — all with no live
 * server and no wall-clock sleeping.
 */
final class MonitorServiceTest extends TestCase
{
    private const MONITOR_SECRET = 'monitor-shared-secret';
    private const BASE_URL = 'http://localhost:8080';
    private const RECIPIENT = 'owner@example.com';
    private const ADMIN_EMAIL = 'admin@example.com';

    private Database $db;
    private FixedClock $clock;
    private FakeDnsChecker $dns;
    private FormRepository $forms;
    private SubmissionRepository $submissions;
    private MonitorRepository $checks;
    private string $configDir;
    private int $canaryId;

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

    public function testPassingCheckDeliversToRecipientAndRecordsOk(): void
    {
        $delivery = new FakeMailer();
        $alerts = new FakeMailer();
        $summary = $this->monitor($delivery, $alerts)->run();

        self::assertSame(1, $summary['checks_ran']);
        self::assertSame(0, $summary['failures']);
        self::assertSame(0, $summary['alerts_sent']);

        // The synthetic submission reached 'sent' and is flagged synthetic.
        $row = $this->submissions->newestSyntheticForForm($this->canaryId, 0);
        self::assertNotNull($row);
        self::assertSame('sent', (string) $row['status']);

        // Delivered to the form's REAL recipient, carrying the marker.
        self::assertSame(1, $delivery->callCount());
        self::assertSame(self::RECIPIENT, $delivery->lastCall()['to']);
        self::assertStringContainsString(MonitorService::MARKER, $delivery->lastCall()['textBody']);

        // Recorded ok; no alert email on a first-ever pass.
        self::assertTrue($this->checks->lastResultForForm($this->canaryId));
        self::assertSame(0, $alerts->callCount());
    }

    public function testFailingDeliverySendsOneAlertToTheAdmin(): void
    {
        $delivery = new FakeMailer();
        $delivery->alwaysFail('smtp is down');
        $alerts = new FakeMailer();

        $summary = $this->monitor($delivery, $alerts)->run();

        self::assertSame(1, $summary['failures']);
        self::assertSame(1, $summary['alerts_sent']);
        self::assertNull($summary['alert_error']);

        self::assertSame(1, $alerts->callCount());
        self::assertSame(self::ADMIN_EMAIL, $alerts->lastCall()['to']);
        self::assertStringContainsString('FAILURE', $alerts->lastCall()['subject']);

        self::assertFalse($this->checks->lastResultForForm($this->canaryId));
    }

    public function testNoRepeatAlertWhileFailingThenRecoveryOnRestore(): void
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
        self::assertTrue($this->checks->lastResultForForm($this->canaryId));
    }

    public function testAlertSendFailureIsReportedForCronBackstop(): void
    {
        $delivery = new FakeMailer();
        $delivery->alwaysFail();
        $alerts = new FakeMailer();
        $alerts->alwaysFail('alert smtp refused');

        $summary = $this->monitor($delivery, $alerts)->run();

        // The check failed AND the alert could not be sent -> reported so the
        // CLI exits nonzero and cron output becomes the backstop.
        self::assertSame(1, $summary['failures']);
        self::assertSame(0, $summary['alerts_sent']);
        self::assertNotNull($summary['alert_error']);
        self::assertStringContainsString('alert smtp refused', (string) $summary['alert_error']);
    }

    public function testUnreachableEndpointIsAFailedCheckThatAlerts(): void
    {
        $delivery = new FakeMailer();
        $alerts = new FakeMailer();
        $monitor = $this->monitor($delivery, $alerts, failTransport: true);

        $summary = $monitor->run();

        self::assertSame(1, $summary['checks_ran']);
        self::assertSame(1, $summary['failures']);
        self::assertSame(1, $summary['alerts_sent']);
        self::assertStringContainsString('token fetch failed', $summary['results'][0]['detail']);
    }

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
        // The old probe is gone. (Asserted by its distinctive timestamp rather
        // than its id, which SQLite may reuse for the run's own fresh probe.)
        $stale = $this->db->fetchAll(
            "SELECT id FROM submissions WHERE created_at = '2000-01-01 00:00:00'"
        );
        self::assertSame([], $stale);
    }

    public function testSecretIsGeneratedAndWrittenBackOnFirstRun(): void
    {
        // No secret configured yet, and no active forms so no check needs it.
        $this->forms->setActive($this->canaryId, false);
        $configPath = $this->configDir . '/config.php';

        $summary = $this->monitor(new FakeMailer(), new FakeMailer(), secret: '', configPath: $configPath)->run();

        self::assertTrue($summary['secret_generated']);
        self::assertSame(0, $summary['checks_ran']);

        self::assertFileExists($configPath);
        $written = require $configPath;
        self::assertArrayHasKey('MONITOR_SECRET', $written);
        self::assertNotSame('', (string) $written['MONITOR_SECRET']);
    }

    public function testStatusReportsCanaryAndLastResult(): void
    {
        $this->monitor(new FakeMailer(), new FakeMailer())->run();

        $status = $this->monitor(new FakeMailer(), new FakeMailer())->status();

        self::assertCount(1, $status);
        self::assertSame($this->canaryId, $status[0]['form_id']);
        self::assertTrue($status[0]['is_canary']);
        self::assertTrue($status[0]['last_ok']);
        self::assertSame('every run', $status[0]['next_due']);
    }

    // --- Harness ----------------------------------------------------------

    private function monitor(
        FakeMailer $delivery,
        FakeMailer $alerts,
        bool $failTransport = false,
        string $secret = self::MONITOR_SECRET,
        ?string $configPath = null
    ): MonitorService {
        $config = Config::fromValues([
            'APP_ENV'            => 'testing',
            'APP_SECRET'         => 'app-signing-secret',
            'MONITOR_SECRET'     => $secret,
            'MONITOR_BASE_URL'   => self::BASE_URL,
            // Keep rate limits out of the way of repeated synthetic POSTs.
            'RATE_IP_PER_MINUTE' => '1000',
            'RATE_FORM_PER_HOUR' => '1000',
            'MIN_SUBMIT_SECONDS' => '3',
        ]);

        $app = AppFactory::create($config, $this->db, $this->dns, $this->clock, $delivery);
        $http = new InProcessHttpClient($app, self::BASE_URL);
        $http->failTransport = $failTransport;

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);

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
            new FakeSleeper($this->clock)
        );
    }
}
