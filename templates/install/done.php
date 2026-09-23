<?php
use function OpenSendForm\Admin\h;

/**
 * The terminal Finish page (replaces the old "Done" screen). A status CHECKLIST,
 * not prose: what is set up and what is still optional/pending. It sits outside
 * the "Step N of 7" count. One primary action: go to the dashboard.
 *
 * @var string $version
 * @var bool   $mailEnabled Whether the email step configured + enabled sending.
 * @var bool   $cronDone    Whether the operator confirmed the cron jobs are set up.
 */

$rows = [
    [
        'label'  => 'App installed',
        'class'  => 'osf-badge--ok',
        'state'  => 'Done',
        'note'   => '',
    ],
    [
        'label'  => 'Email sending',
        'class'  => $mailEnabled ? 'osf-badge--ok' : 'osf-badge--muted',
        'state'  => $mailEnabled ? 'On' : 'Skipped',
        'note'   => $mailEnabled ? '' : 'Set it up any time in the admin Email tab.',
    ],
    [
        'label'  => 'Scheduled tasks',
        'class'  => $cronDone ? 'osf-badge--ok' : 'osf-badge--warn',
        'state'  => $cronDone ? 'Set up' : 'Pending',
        'note'   => $cronDone ? '' : 'The commands are available from the admin Email tab.',
    ],
    [
        'label'  => 'Bot protection',
        'class'  => 'osf-badge--muted',
        'state'  => 'Skipped',
        'note'   => 'Optional, added per form from a form’s settings.',
    ],
];
?>
<h1>OpenSendForm is installed 🎉</h1>

<p>Here’s where things stand. Anything not finished can be done later from the
    admin panel.</p>

<div class="osf-table-wrap">
    <table class="osf-table">
        <thead>
            <tr>
                <th scope="col">Item</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td data-label="Item">
                    <?= h($row['label']) ?>
                    <?php if ($row['note'] !== ''): ?>
                        <br><small><?= h($row['note']) ?></small>
                    <?php endif; ?>
                </td>
                <td data-label="Status"><span class="osf-badge <?= h($row['class']) ?>"><?= h($row['state']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p><small>
    <strong>The installer is now locked, and that’s safe.</strong> A visitor
    cannot re-run setup or take over your site. If you ever genuinely need to
    run the installer again, follow
    <a href="https://opensendform.com/guides/reinstall" target="_blank" rel="noopener">the reinstall guide</a>.
</small></p>

<div class="osf-actions osf-step-actions">
    <a href="/admin/login" role="button">Go to your dashboard</a>
</div>

<p><small>Installed version <?= h($version) ?>.</small></p>
