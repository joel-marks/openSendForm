<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;
use function OpenSendForm\Admin\truncate;
use function OpenSendForm\Admin\statusBadgeClass;

/**
 * Submissions table — METADATA ONLY, never the submitted content.
 *
 * WHY no content is shown: a submission's content is the in-flight delivery
 * payload (see SubmissionRepository), retained only so a failed send can be
 * retried, and — for privacy — cleared on successful delivery unless the form
 * opts to keep it. The admin surface deliberately exposes delivery metadata
 * (status, attempts, last error) and never renders the fields a visitor typed.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var array<int, array<string, mixed>> $forms
 * @var array<int, string> $statuses
 * @var string   $status
 * @var int|null $formId
 * @var int      $page
 * @var int      $pages
 * @var int      $total
 * @var string   $csrf
 */
// Hidden fields that let a retry/delete action return to this exact view.
$return = [
    'status' => $status,
    'form'   => $formId === null ? '' : (string) $formId,
    'page'   => (string) $page,
];
// Query string carrying the current filter onto a per-row delete-confirm link.
$returnParams = [];
if ($status !== '') {
    $returnParams['status'] = $status;
}
if ($formId !== null) {
    $returnParams['form'] = (string) $formId;
}
if ($page > 1) {
    $returnParams['page'] = (string) $page;
}
$returnQuery = $returnParams === [] ? '' : '?' . http_build_query($returnParams);
?>
<h1>Submissions</h1>

<div class="osf-toolbar">
    <form method="get" action="/admin/submissions" role="search" class="osf-inline-form">
        <div role="group" aria-label="Filter submissions" class="osf-filter-bar">
            <div class="osf-field">
                <select name="status" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                    <?php /* Synthetic monitor probes are hidden from every other
                             view; this option is the only way to see them. */ ?>
                    <option value="synthetic" <?= ($syntheticView ?? false) ? 'selected' : '' ?>>synthetic (monitor)</option>
                </select>
            </div>
            <div class="osf-field">
                <select name="form" aria-label="Filter by form">
                    <option value="">All forms</option>
                    <?php foreach ($forms as $f): ?>
                        <option value="<?= h((string) $f['id']) ?>" <?= $formId === (int) $f['id'] ? 'selected' : '' ?>>
                            <?= h((string) $f['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Filter</button>
        </div>
    </form>

    <div class="osf-actions">
        <form method="post" action="/admin/submissions/retry-due" class="osf-inline-form">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="status" value="<?= h($return['status']) ?>">
            <input type="hidden" name="form" value="<?= h($return['form']) ?>">
            <input type="hidden" name="page" value="<?= h($return['page']) ?>">
            <button type="submit" class="secondary">Retry all due now</button>
        </form>

        <?php
        // "Delete all" respects the current STATUS filter only (never the form
        // filter). Disabled when there is nothing in scope to delete — no
        // dead-end confirm page for zero rows.
        $deleteAllLabel = $status !== ''
            ? 'Delete all ' . h($status) . ' submissions (' . h((string) $deleteAllCount) . ')'
            : 'Delete all submissions (' . h((string) $deleteAllCount) . ')';
        $deleteAllUrl = '/admin/submissions/delete-all'
            . ($status !== '' ? '?status=' . rawurlencode($status) : '');
        ?>
        <?php if ($deleteAllCount > 0): ?>
            <a href="<?= h($deleteAllUrl) ?>" role="button" class="osf-danger"><?= icon('trash-2') ?> <?= $deleteAllLabel ?></a>
        <?php else: ?>
            <button type="button" class="osf-danger" disabled title="No submissions to delete."><?= icon('trash-2') ?> <?= $deleteAllLabel ?></button>
        <?php endif; ?>
    </div>
</div>

<p><small><?= h((string) $total) ?> submission(s) match.</small></p>

<?php if ($rows === []): ?>
    <p>No submissions match this filter.</p>
<?php else: ?>
    <div class="osf-table-wrap">
        <table class="osf-table">
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Form</th>
                    <th scope="col">Created</th>
                    <th scope="col">Status</th>
                    <th scope="col">Attempts</th>
                    <th scope="col">Last error</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $rstatus = (string) $row['status'];
                $retryable = $rstatus === 'failed' || $rstatus === 'dead' || $rstatus === 'received';
                $error = (string) ($row['last_error'] ?? '');
                ?>
                <tr>
                    <td data-label="ID"><?= h((string) $row['id']) ?></td>
                    <td data-label="Form"><?= h((string) ($row['form_name'] ?? ('#' . $row['form_id']))) ?></td>
                    <td data-label="Created"><?= h((string) $row['created_at']) ?></td>
                    <td data-label="Status"><span class="osf-badge <?= h(statusBadgeClass($rstatus)) ?>"><?= h($rstatus) ?></span></td>
                    <td data-label="Attempts"><?= h((string) $row['attempts']) ?></td>
                    <td data-label="Last error">
                        <?php if ($error === ''): ?>
                            <span aria-hidden="true">—</span>
                        <?php elseif (mb_strlen($error) <= 60): ?>
                            <?= h($error) ?>
                        <?php else: ?>
                            <details class="osf-error-detail">
                                <summary><?= h(truncate($error, 60)) ?></summary>
                                <pre><?= h($error) ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions">
                        <div class="osf-actions">
                            <?php if ($retryable): ?>
                                <form class="osf-inline-form" method="post"
                                      action="/admin/submissions/<?= h((string) $row['id']) ?>/retry">
                                    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="status" value="<?= h($return['status']) ?>">
                                    <input type="hidden" name="form" value="<?= h($return['form']) ?>">
                                    <input type="hidden" name="page" value="<?= h($return['page']) ?>">
                                    <button type="submit" class="osf-btn-sm">Retry</button>
                                </form>
                            <?php endif; ?>
                            <a href="/admin/submissions/<?= h((string) $row['id']) ?>/delete<?= h($returnQuery) ?>"
                               role="button" class="osf-danger osf-btn-sm"><?= icon('trash-2') ?> Delete</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav aria-label="Pagination">
            <?php
            $qs = static function (int $p) use ($status, $formId): string {
                $params = [];
                if ($status !== '') {
                    $params['status'] = $status;
                }
                if ($formId !== null) {
                    $params['form'] = (string) $formId;
                }
                $params['page'] = (string) $p;
                return '/admin/submissions?' . http_build_query($params);
            };
            ?>
            <?php if ($page > 1): ?>
                <a href="<?= h($qs($page - 1)) ?>" role="button" class="secondary">&laquo; Previous</a>
            <?php endif; ?>
            <span>Page <?= h((string) $page) ?> of <?= h((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a href="<?= h($qs($page + 1)) ?>" role="button" class="secondary">Next &raquo;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
