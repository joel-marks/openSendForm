<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Submission;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic monitor probes are excluded from the dashboard stats and the
 * default submissions list, exposed only through the explicit synthetic view,
 * and purged by age (and only synthetics, never real rows).
 */
final class SyntheticExclusionTest extends TestCase
{
    private Database $db;
    private SubmissionRepository $repo;
    private int $formId;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->repo = new SubmissionRepository($this->db);
        $form = (new FormRepository($this->db))->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->formId = (int) $form['id'];
    }

    public function testStatsExcludeSyntheticRows(): void
    {
        $this->record('failed', false);
        $this->record('failed', true);   // synthetic — must not count
        $this->record('dead', true);     // synthetic — must not count
        $this->record('received', false);

        self::assertSame(1, $this->repo->countByStatus('failed'));
        self::assertSame(0, $this->repo->countByStatus('dead'));
        self::assertSame(2, $this->repo->countSince('1970-01-01 00:00:00'));

        $recent = $this->repo->recentByStatuses(['failed', 'dead'], 10);
        self::assertCount(1, $recent);
    }

    public function testDefaultListHidesSyntheticButSyntheticViewShowsOnlyThem(): void
    {
        $this->record('sent', false);
        $this->record('sent', true);
        $this->record('sent', true);

        // Default view: real only.
        self::assertSame(1, $this->repo->countFiltered(null, null));
        self::assertCount(1, $this->repo->listPage(null, null, 50, 0));

        // Synthetic view: probes only, regardless of delivery status.
        self::assertSame(2, $this->repo->countFiltered(null, null, true));
        self::assertCount(2, $this->repo->listPage(null, null, 50, 0, true));
    }

    public function testPurgeRemovesOnlyOldSyntheticRows(): void
    {
        // Two old synthetics, one recent synthetic, one old real row.
        $this->recordAt('sent', true, '2000-01-01 00:00:00');
        $this->recordAt('sent', true, '2000-01-02 00:00:00');
        $this->recordAt('sent', true, '2099-01-01 00:00:00');
        $this->recordAt('sent', false, '2000-01-01 00:00:00');

        $deleted = $this->repo->purgeSyntheticOlderThan('2001-01-01 00:00:00');

        self::assertSame(2, $deleted);
        // The recent synthetic and the old REAL row both survive.
        self::assertSame(1, $this->repo->countFiltered(null, null, true));
        self::assertSame(1, $this->repo->countFiltered(null, null, false));
    }

    public function testMaxAndNewestSyntheticHelpers(): void
    {
        self::assertSame(0, $this->repo->maxSyntheticId($this->formId));

        $this->record('received', false);            // real, ignored
        $syntheticId = $this->record('received', true);

        self::assertSame($syntheticId, $this->repo->maxSyntheticId($this->formId));

        $row = $this->repo->newestSyntheticForForm($this->formId, $syntheticId - 1);
        self::assertNotNull($row);
        self::assertSame($syntheticId, (int) $row['id']);

        // Nothing newer than the high-water id.
        self::assertNull($this->repo->newestSyntheticForForm($this->formId, $syntheticId));
    }

    private function record(string $status, bool $synthetic): int
    {
        return $this->repo->recordSubmission(
            $this->formId,
            '127.0.0.1',
            'https://example.com',
            null,
            '{}',
            $status,
            $synthetic
        );
    }

    private function recordAt(string $status, bool $synthetic, string $createdAt): void
    {
        $id = $this->record($status, $synthetic);
        $this->db->execute(
            'UPDATE submissions SET created_at = :c WHERE id = :id',
            ['c' => $createdAt, 'id' => $id]
        );
    }
}
