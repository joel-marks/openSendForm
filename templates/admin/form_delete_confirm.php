<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * @var int    $formId
 * @var string $formName
 * @var string $formKey
 * @var int    $submissionCount
 * @var string $csrf
 */
?>
<h1>Delete form</h1>

<p>
    You are about to permanently delete the form
    <strong><?= h($formName) ?></strong>
    (key <code><?= h($formKey) ?></code>).
</p>

<p role="alert">
    <strong>This cannot be undone.</strong> Deleting this form will also
    destroy
    <?php if ($submissionCount === 0): ?>
        its <strong>0 stored submissions</strong>.
    <?php else: ?>
        its <strong><?= h((string) $submissionCount) ?> stored
        submission<?= $submissionCount === 1 ? '' : 's' ?></strong>.
    <?php endif; ?>
    Its embed snippet will stop working immediately. If you only want to stop
    accepting new submissions, go back and <strong>disable</strong> the form
    instead - that keeps its data and is reversible.
</p>

<form method="post" action="/admin/forms/<?= h((string) $formId) ?>/delete">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <div class="osf-actions">
        <button type="submit" class="osf-danger"><?= icon('trash-2') ?> Permanently delete <?= h($formName) ?></button>
        <a href="/admin/forms" role="button" class="secondary">Cancel</a>
    </div>
</form>
