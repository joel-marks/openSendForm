<?php

declare(strict_types=1);

namespace OpenSendForm\Submission;

use OpenSendForm\Storage\Database;

/**
 * Data-access layer for submissions.
 *
 * Metadata is always stored. Message content is always persisted at record
 * time too — it is the in-flight delivery payload, needed regardless of the
 * owning form's store_content toggle so a failed send can be retried. On
 * successful delivery, DeliveryService clears the content column unless
 * store_content is on: the toggle means "retain content after successful
 * delivery", not "store content at all". Failed/dead submissions keep their
 * content until the normal retention purge, so operators can recover
 * undelivered mail. All access goes through prepared statements on the
 * Database wrapper.
 */
final class SubmissionRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Record a submission for a form.
     *
     * Content is always persisted as the in-flight delivery payload,
     * regardless of the owning form's store_content toggle — see the class
     * docblock for the full lifecycle.
     *
     * @return int The new submission id.
     */
    public function recordSubmission(
        int $formId,
        string $remoteIp,
        ?string $origin,
        ?string $userAgent,
        ?string $contentJson = null,
        string $status = 'received',
        bool $isSynthetic = false
    ): int {
        $this->db->execute(
            'INSERT INTO submissions
                (form_id, created_at, remote_ip, origin, user_agent, status, content, is_synthetic)
             VALUES
                (:form_id, :created_at, :remote_ip, :origin, :user_agent, :status, :content, :is_synthetic)',
            [
                'form_id'      => $formId,
                'created_at'   => self::now(),
                'remote_ip'    => $remoteIp,
                'origin'       => $origin,
                'user_agent'   => $userAgent,
                'status'       => $status,
                'content'      => $contentJson,
                'is_synthetic' => $isSynthetic ? 1 : 0,
            ]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Delete synthetic submissions created before a portable 'Y-m-d H:i:s' UTC
     * cutoff. Called by the monitor to auto-purge its own probes after a short
     * retention. Only ever touches is_synthetic = 1 rows, so real submissions
     * are never affected regardless of the cutoff.
     *
     * @return int Rows deleted.
     */
    public function purgeSyntheticOlderThan(string $cutoff): int
    {
        return $this->db->execute(
            'DELETE FROM submissions WHERE is_synthetic = 1 AND created_at < :cutoff',
            ['cutoff' => $cutoff]
        )->rowCount();
    }

    /**
     * The newest synthetic submission for a form with an id greater than
     * $afterId, or null. The monitor captures the pre-check high-water id, then
     * uses this to find the exact row its POST created so it can poll delivery.
     *
     * @return array<string, mixed>|null
     */
    public function newestSyntheticForForm(int $formId, int $afterId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM submissions
              WHERE form_id = :form_id AND is_synthetic = 1 AND id > :after_id
              ORDER BY id DESC
              LIMIT 1',
            ['form_id' => $formId, 'after_id' => $afterId]
        );
    }

    /**
     * The highest synthetic submission id for a form (0 when none). Used as the
     * pre-check high-water mark so a subsequent probe row can be told apart.
     */
    public function maxSyntheticId(int $formId): int
    {
        $row = $this->db->fetchOne(
            'SELECT MAX(id) AS m FROM submissions WHERE form_id = :form_id AND is_synthetic = 1',
            ['form_id' => $formId]
        );

        return (int) ($row['m'] ?? 0);
    }

    /**
     * Delete submissions older than each form's retention_days.
     *
     * Cutoffs are computed per form in PHP so the SQL stays portable
     * across sqlite and mysql (no dialect-specific date arithmetic).
     *
     * @return int Total rows deleted.
     */
    public function purgeExpired(): int
    {
        $forms = $this->db->fetchAll('SELECT id, retention_days FROM forms');

        $deleted = 0;
        foreach ($forms as $form) {
            $retentionDays = (int) $form['retention_days'];
            $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

            $statement = $this->db->execute(
                'DELETE FROM submissions WHERE form_id = :form_id AND created_at < :cutoff',
                [
                    'form_id' => (int) $form['id'],
                    'cutoff'  => $cutoff,
                ]
            );

            $deleted += $statement->rowCount();
        }

        return $deleted;
    }

    /**
     * Find a submission by id, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM submissions WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Delete a single submission by id.
     *
     * @return int Number of rows deleted (0 or 1).
     */
    public function deleteById(int $id): int
    {
        $statement = $this->db->execute(
            'DELETE FROM submissions WHERE id = :id',
            ['id' => $id]
        );

        return $statement->rowCount();
    }

    /**
     * Delete every submission belonging to one form. Used by the form-deletion
     * cascade, which deletes submissions first (so the count is reportable)
     * and then the form row.
     *
     * @return int Number of rows deleted.
     */
    public function deleteAllForForm(int $formId): int
    {
        $statement = $this->db->execute(
            'DELETE FROM submissions WHERE form_id = :form_id',
            ['form_id' => $formId]
        );

        return $statement->rowCount();
    }

    /**
     * Delete submissions in bulk. With no status, every submission is removed;
     * with a status, only rows in that status. The scope is status-only (never
     * per form) — the admin "Delete all" respects the current STATUS filter.
     *
     * @return int Number of rows deleted.
     */
    public function deleteAll(?string $status = null): int
    {
        if ($status === null) {
            return $this->db->execute('DELETE FROM submissions')->rowCount();
        }

        $statement = $this->db->execute(
            'DELETE FROM submissions WHERE status = :status',
            ['status' => $status]
        );

        return $statement->rowCount();
    }

    /**
     * Null the content column. Called on successful delivery when the
     * owning form's store_content is off, so the toggle governs retention
     * after a successful send rather than storage of the in-flight payload.
     */
    public function clearContent(int $id): void
    {
        $this->db->execute(
            'UPDATE submissions SET content = NULL WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Mark a submission delivered.
     */
    public function markSent(int $id, int $attempts, string $attemptedAt): void
    {
        $this->db->execute(
            'UPDATE submissions
                SET status = :status,
                    attempts = :attempts,
                    last_attempt_at = :attempted_at,
                    next_attempt_at = NULL,
                    last_error = NULL
             WHERE id = :id',
            [
                'status'       => 'sent',
                'attempts'     => $attempts,
                'attempted_at' => $attemptedAt,
                'id'           => $id,
            ]
        );
    }

    /**
     * Mark a delivery attempt failed and schedule the next retry.
     */
    public function markFailed(
        int $id,
        int $attempts,
        string $error,
        string $attemptedAt,
        string $nextAttemptAt
    ): void {
        $this->db->execute(
            'UPDATE submissions
                SET status = :status,
                    attempts = :attempts,
                    last_error = :error,
                    last_attempt_at = :attempted_at,
                    next_attempt_at = :next_attempt_at
             WHERE id = :id',
            [
                'status'          => 'failed',
                'attempts'        => $attempts,
                'error'           => $error,
                'attempted_at'    => $attemptedAt,
                'next_attempt_at' => $nextAttemptAt,
                'id'              => $id,
            ]
        );
    }

    /**
     * Mark a submission permanently undeliverable (retries exhausted). No
     * next_attempt_at, so retryDue never picks it up again.
     */
    public function markDead(int $id, int $attempts, string $error, string $attemptedAt): void
    {
        $this->db->execute(
            'UPDATE submissions
                SET status = :status,
                    attempts = :attempts,
                    last_error = :error,
                    last_attempt_at = :attempted_at,
                    next_attempt_at = NULL
             WHERE id = :id',
            [
                'status'       => 'dead',
                'attempts'     => $attempts,
                'error'        => $error,
                'attempted_at' => $attemptedAt,
                'id'           => $id,
            ]
        );
    }

    /**
     * Submissions the retry sweep should re-attempt now. Two kinds qualify:
     *
     *  - 'failed' submissions whose scheduled next_attempt_at has come due
     *    (<= $now) — the normal backoff-driven retry, and
     *  - 'received' submissions with attempts = 0 — accepted but NEVER
     *    attempted (stored while mail was disabled or SMTP was unavailable
     *    pre-attempt). They have no next_attempt_at because no attempt ever
     *    failed to schedule one; they are treated as immediately due, with no
     *    backoff, so enabling mail later finally delivers them.
     *
     * Comparison is lexicographic on the portable 'Y-m-d H:i:s' UTC text,
     * which sorts chronologically, so no dialect date arithmetic is needed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDueForRetry(string $now): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM submissions
              WHERE (status = 'failed'
                     AND next_attempt_at IS NOT NULL
                     AND next_attempt_at <= :now)
                 OR (status = 'received' AND attempts = 0)
              ORDER BY next_attempt_at ASC, id ASC",
            ['now' => $now]
        );
    }

    /**
     * Summary rows for the CLI listing: never any submitted content.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSummaries(?string $status = null, int $limit = 100): array
    {
        $sql = 'SELECT s.id, s.form_id, f.form_key, f.name AS form_name,
                       s.created_at, s.status, s.attempts
                  FROM submissions s
                  LEFT JOIN forms f ON f.id = s.form_id';
        $params = [];

        if ($status !== null) {
            $sql .= ' WHERE s.status = :status';
            $params['status'] = $status;
        }

        // $limit is cast to int here (never interpolated from user text), so
        // it is safe to inline — some drivers reject a bound LIMIT parameter.
        $sql .= ' ORDER BY s.id DESC LIMIT ' . max(1, $limit);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count submissions created at or after a portable 'Y-m-d H:i:s' UTC
     * cutoff (used for the dashboard's "today" figure — the caller derives
     * the cutoff from the clock so it stays testable).
     */
    public function countSince(string $cutoff): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE created_at >= :cutoff AND is_synthetic = 0',
            ['cutoff' => $cutoff]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Count submissions in a given status. Synthetic monitor probes are
     * excluded so the dashboard figures reflect only real traffic.
     */
    public function countByStatus(string $status): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE status = :status AND is_synthetic = 0',
            ['status' => $status]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Most recent submissions in any of the given statuses, newest first.
     * Metadata only (never content); includes last_error for the dashboard's
     * problem list.
     *
     * @param array<int, string> $statuses
     * @return array<int, array<string, mixed>>
     */
    public function recentByStatuses(array $statuses, int $limit): array
    {
        if ($statuses === []) {
            return [];
        }

        [$placeholders, $params] = self::inClause($statuses, 'st');

        $sql = 'SELECT s.id, s.form_id, f.form_key, f.name AS form_name,
                       s.created_at, s.status, s.attempts, s.last_error
                  FROM submissions s
                  LEFT JOIN forms f ON f.id = s.form_id
                 WHERE s.status IN (' . $placeholders . ')
                   AND s.is_synthetic = 0
                 ORDER BY s.id DESC
                 LIMIT ' . max(1, $limit);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * One page of submissions for the admin table, filtered optionally by
     * status and/or form, newest first. Metadata only — content is never
     * selected.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPage(?string $status, ?int $formId, int $limit, int $offset, bool $syntheticOnly = false): array
    {
        [$where, $params] = self::filter($status, $formId, $syntheticOnly);

        $sql = 'SELECT s.id, s.form_id, f.form_key, f.name AS form_name,
                       s.created_at, s.status, s.attempts, s.last_error
                  FROM submissions s
                  LEFT JOIN forms f ON f.id = s.form_id'
            . $where
            . ' ORDER BY s.id DESC'
            . ' LIMIT ' . max(1, $limit)
            . ' OFFSET ' . max(0, $offset);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Total submissions matching the same optional status/form filter, for
     * pagination.
     */
    public function countFiltered(?string $status, ?int $formId, bool $syntheticOnly = false): int
    {
        [$where, $params] = self::filter($status, $formId, $syntheticOnly);

        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM submissions s' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Build a WHERE fragment (leading space, or empty) and its bound params
     * for the optional status/form filter shared by listPage/countFiltered.
     *
     * Synthetic monitor probes are hidden by default (is_synthetic = 0); pass
     * $syntheticOnly to invert that and show ONLY probes (is_synthetic = 1),
     * which is how the admin "synthetic" filter view exposes them.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function filter(?string $status, ?int $formId, bool $syntheticOnly = false): array
    {
        $conditions = [$syntheticOnly ? 's.is_synthetic = 1' : 's.is_synthetic = 0'];
        $params = [];

        if ($status !== null && $status !== '') {
            $conditions[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($formId !== null) {
            $conditions[] = 's.form_id = :form_id';
            $params['form_id'] = $formId;
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    /**
     * Build a placeholder list and bound params for a values IN (...) clause,
     * keeping every value parameterised.
     *
     * @param array<int, string> $values
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function inClause(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $name = $prefix . $i;
            $placeholders[] = ':' . $name;
            $params[$name] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * Current UTC timestamp in a portable, lexicographically-sortable form.
     */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
