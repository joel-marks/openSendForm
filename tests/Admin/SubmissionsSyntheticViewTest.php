<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Tests\Support\FakeSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The submissions screen hides synthetic monitor probes by default and exposes
 * them only under the explicit "synthetic" filter.
 */
final class SubmissionsSyntheticViewTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private Database $db;
    private int $realId;
    private int $syntheticId;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $form = (new FormRepository($this->db))->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $submissions = new SubmissionRepository($this->db);
        $this->realId = $submissions->recordSubmission((int) $form['id'], '127.0.0.1', null, null, '{}', 'sent', false);
        $this->syntheticId = $submissions->recordSubmission((int) $form['id'], '127.0.0.1', null, null, '{}', 'sent', true);
    }

    public function testDefaultListHidesSyntheticProbes(): void
    {
        $body = (string) $this->get('/admin/submissions')->getBody();

        self::assertStringContainsString('#' . $this->realId, $this->idCell($body));
        self::assertStringNotContainsString('#' . $this->syntheticId, $this->idCell($body));
    }

    public function testSyntheticFilterShowsOnlyProbes(): void
    {
        $body = (string) $this->get('/admin/submissions?status=synthetic')->getBody();

        // The synthetic row is present; the real one is not.
        self::assertMatchesRegularExpression(
            '/data-label="ID">' . $this->syntheticId . '</',
            $body
        );
        self::assertDoesNotMatchRegularExpression(
            '/data-label="ID">' . $this->realId . '</',
            $body
        );
    }

    /**
     * Collapse the body to just its ID cells, prefixed with '#', so a bare id
     * substring can be asserted without matching unrelated numbers.
     */
    private function idCell(string $body): string
    {
        preg_match_all('/data-label="ID">(\d+)</', $body, $m);

        return '#' . implode(' #', $m[1]);
    }

    // --- Harness ----------------------------------------------------------

    private function get(string $path): ResponseInterface
    {
        $session = new FakeSession();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        (new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher)))
            ->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'submissions-synthetic-secret']);
        $app = AppFactory::create($config, $this->db, null, null, null, null, $session);

        $csrf = $this->csrfFrom($app->handle($this->request('GET', '/admin/login')));
        $app->handle($this->request('POST', '/admin/login')->withParsedBody([
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]));

        return $app->handle($this->request('GET', $path));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        // Slim needs the query string parsed into the request.
        $parts = explode('?', $path, 2);
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
            $request = $request->withQueryParams($query);
        }

        return $request;
    }

    private function csrfFrom(ResponseInterface $response): string
    {
        $matched = preg_match('/name="_csrf" value="([a-f0-9]+)"/', (string) $response->getBody(), $m);
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }
}
