<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * @var string $status  Empty for "every submission", else the status scope.
 * @var int    $count
 * @var string $csrf
 */
$scoped = $status !== '';
$backUrl = $scoped ? '/admin/submissions?status=' . rawurlencode($status) : '/admin/submissions';
?>
<h1>Delete all submissions</h1>

<p>
    You are about to permanently delete
    <?php if ($scoped): ?>
        all <strong><?= h((string) $count) ?></strong>
        submission<?= $count === 1 ? '' : 's' ?> with status
        <strong><?= h($status) ?></strong>.
    <?php else: ?>
        <strong>every</strong> submission - all
        <strong><?= h((string) $count) ?></strong> of them, across every form.
    <?php endif; ?>
</p>

<p role="alert"><strong>This cannot be undone.</strong></p>

<form method="post" action="/admin/submissions/delete-all">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <div class="osf-actions">
        <button type="submit" class="osf-danger">
            <?= icon('trash-2') ?>
            <?php if ($scoped): ?>
                Permanently delete all <?= h($status) ?> submissions (<?= h((string) $count) ?>)
            <?php else: ?>
                Permanently delete all submissions (<?= h((string) $count) ?>)
            <?php endif; ?>
        </button>
        <a href="<?= h($backUrl) ?>" role="button" class="secondary">Cancel</a>
    </div>
</form>
