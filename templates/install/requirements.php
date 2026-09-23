<?php
use OpenSendForm\Install\Requirements;
use function OpenSendForm\Admin\h;

/**
 * @var array<int, array{key:string, label:string, status:string, remedy:string}> $checks
 * @var bool $hasFailures
 */

$badge = [
    Requirements::PASS => ['osf-badge--ok', 'OK'],
    Requirements::WARN => ['osf-badge--warn', 'Heads up'],
    Requirements::FAIL => ['osf-badge--danger', 'Action needed'],
];
?>
<?php require __DIR__ . '/_progress.php'; ?>
<h1>Hosting check</h1>

<p>
    OpenSendForm checks your hosting before installing. Anything marked
    <strong>Action needed</strong> must be fixed before you can continue.
    <strong>Heads up</strong> items are optional — you can install without them.
</p>

<div class="osf-table-wrap">
    <table class="osf-table">
        <thead>
            <tr>
                <th scope="col">Requirement</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <?php [$class, $label] = $badge[$check['status']] ?? ['osf-badge--muted', $check['status']]; ?>
            <tr>
                <td data-label="Requirement">
                    <?= h($check['label']) ?>
                    <?php if ($check['remedy'] !== ''): ?>
                        <br><small><?= h($check['remedy']) ?></small>
                    <?php endif; ?>
                </td>
                <td data-label="Status"><span class="osf-badge <?= h($class) ?>"><?= h($label) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($hasFailures): ?>
    <p role="alert">
        <strong>Please fix the items marked “Action needed” above, then reload
        this page.</strong>
    </p>
    <div class="osf-actions osf-step-actions">
        <a href="/install/requirements" role="button" class="osf-disabled-link" aria-disabled="true">Continue</a>
    </div>
<?php else: ?>
    <div class="osf-actions osf-step-actions">
        <a href="/install/database" role="button">Continue</a>
    </div>
<?php endif; ?>
