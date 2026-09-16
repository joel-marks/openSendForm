<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Submit;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Tests\Support\FakeDnsChecker;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The synthetic marking contract: a submission is flagged is_synthetic ONLY
 * when it carries the reserved _osf_monitor field with the correct
 * MONITOR_SECRET. A wrong, absent or unconfigured secret leaves it an ordinary
 * submission, and — crucially — the reserved field never lets a submission skip
 * a pipeline stage: a filled honeypot is still silently discarded even with the
 * right secret.
 */
final class SyntheticMarkingTest extends TestCase
{
    private const SECRET = 'app-signing-secret';
    private const MONITOR = 'monitor-shared-secret';
    private const T0 = 1_700_000_000;
    private const ORIGIN = 'https://example.com';

    private Database $db;
    private FixedClock $clock;
    private FakeDnsChecker $dns;
    /** @var array<string, mixed> */
    private array $form;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->clock = new FixedClock(self::T0);
        $this->dns = new FakeDnsChecker(true);
        $this->form = (new FormRepository($this->db))->createForm('Contact', 'owner@example.com', [self::ORIGIN]);
    }

    public function testCorrectSecretMarksSubmissionSynthetic(): void
    {
        $response = $this->submit([
            SubmitContext::FIELD_TOKEN   => $this->freshToken(),
            SubmitContext::FIELD_MONITOR => self::MONITOR,
            'message'                    => 'hello',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $this->lastSubmission()['is_synthetic']);
    }

    public function testWrongSecretIsAnOrdinarySubmission(): void
    {
        $this->submit([
            SubmitContext::FIELD_TOKEN   => $this->freshToken(),
            SubmitContext::FIELD_MONITOR => 'not-the-secret',
            'message'                    => 'hello',
        ]);

        self::assertSame(0, (int) $this->lastSubmission()['is_synthetic']);
    }

    public function testAbsentMarkerIsAnOrdinarySubmission(): void
    {
        $this->submit([
            SubmitContext::FIELD_TOKEN => $this->freshToken(),
            'message'                  => 'hello',
        ]);

        self::assertSame(0, (int) $this->lastSubmission()['is_synthetic']);
    }

    public function testEmptyConfiguredSecretNeverMarksSynthetic(): void
    {
        // MONITOR_SECRET unset: even an empty marker must not match an empty
        // secret, so the submission is ordinary.
        $this->submit([
            SubmitContext::FIELD_TOKEN   => $this->freshToken(),
            SubmitContext::FIELD_MONITOR => '',
            'message'                    => 'hello',
        ], monitorSecret: '');

        self::assertSame(0, (int) $this->lastSubmission()['is_synthetic']);
    }

    public function testReservedFieldNeverBypassesStagesEvenWithCorrectSecret(): void
    {
        // A filled honeypot with the correct secret is STILL silently discarded:
        // marking happens only at store, which a honeypot hit never reaches.
        $response = $this->submit([
            SubmitContext::FIELD_TOKEN    => $this->freshToken(),
            SubmitContext::FIELD_MONITOR  => self::MONITOR,
            SubmitContext::FIELD_HONEYPOT => 'i am a bot',
            'message'                     => 'hello',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $response->getBody(), true));
        self::assertSame(0, $this->submissionCount());
    }

    // --- Harness ----------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     */
    private function submit(array $fields, string $monitorSecret = self::MONITOR): ResponseInterface
    {
        $config = Config::fromValues([
            'APP_ENV'        => 'testing',
            'APP_SECRET'     => self::SECRET,
            'MONITOR_SECRET' => $monitorSecret,
        ]);
        $app = AppFactory::create($config, $this->db, $this->dns, $this->clock);

        return $app->handle($this->submitRequest($fields));
    }

    private function freshToken(): string
    {
        $token = (new SubmitToken(self::SECRET, $this->clock, 3, 3600))->issue($this->form['form_key']);
        $this->clock->advance(3); // age past MIN_SUBMIT_SECONDS

        return $token;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submitRequest(array $fields): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/v1/form/' . $this->form['form_key'] . '/submit', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Origin', self::ORIGIN)
            ->withHeader('Accept', 'application/json');

        $request->getBody()->write(http_build_query($fields));
        $request->getBody()->rewind();

        return $request;
    }

    private function submissionCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM submissions')['c'];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastSubmission(): array
    {
        $row = $this->db->fetchOne('SELECT * FROM submissions ORDER BY id DESC LIMIT 1');
        self::assertNotNull($row, 'Expected a stored submission.');

        return $row;
    }
}
