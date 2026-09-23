<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * Step 6: Scheduled tasks (cron). Plain-language purpose of the two jobs, the
 * exact copy-paste commands with runtime-derived absolute paths, and the cPanel
 * recipe. The app cannot see cron from a request, so there is no verification —
 * the operator's choice is simply recorded so Finish can report it.
 *
 * @var string $csrf
 * @var string $error
 * @var string $phpBinary  The PHP CLI binary baked into the cron commands.
 * @var string $monitorCmd Full monitor:run command (php + path + subcommand).
 * @var string $retryCmd   Full mail:retry command.
 */
?>
<?php require __DIR__ . '/_progress.php'; ?>
<h1>Set up your scheduled tasks</h1>

<p>
    OpenSendForm needs two small jobs that your host runs on a schedule (a
    “cron job”). They keep everything working while nobody is watching:
</p>

<ul>
    <li><strong>Monitoring</strong> — checks every hour that your forms still
        work and emails you if one stops.</li>
    <li><strong>Retry failed emails</strong> — re-sends, once an hour, any
        submission emails that failed the first time (for example if your mail
        server was briefly unreachable).</li>
</ul>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<div class="osf-flash osf-flash--info" role="status">
    <p><strong>On cPanel:</strong> open <strong>Cron Jobs</strong>, choose
        <strong>Once Per Hour (0 * * * *)</strong> under Common Settings, paste
        the first command into the <strong>Command</strong> box and click
        <strong>Add New Cron Job</strong>. Add the second the same way, changing
        its <strong>Minute</strong> field from <code>0</code> to <code>5</code>
        so the two jobs don’t run at the same moment.</p>
</div>

<h2>Job 1 — monitoring</h2>
<p class="osf-copy">
    <code><?= h($monitorCmd) ?></code>
    <button type="button" class="secondary outline" data-copy="<?= h($monitorCmd) ?>"><?= icon('copy') ?> Copy</button>
</p>

<h2>Job 2 — retry failed emails</h2>
<p class="osf-copy">
    <code><?= h($retryCmd) ?></code>
    <button type="button" class="secondary outline" data-copy="<?= h($retryCmd) ?>"><?= icon('copy') ?> Copy</button>
</p>

<p><small>If cron reports that the PHP path (<code><?= h($phpBinary) ?></code>)
    does not work, your host’s PHP command line is usually
    <code>/usr/local/bin/php</code> — replace the path at the start of each
    command with that.</small></p>

<p><small>No rush — if you’d rather do this later, you can. The commands stay
    available from the admin <strong>Email</strong> tab, and OpenSendForm will
    remind you until they’re running.</small></p>

<form method="post" action="/install/scheduled">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <div class="osf-actions osf-step-actions">
        <button type="submit" name="action" value="later" class="secondary outline osf-step-skip">I’ll do this later</button>
        <button type="submit" name="action" value="done"><?= icon('clock') ?> I’ve set these up</button>
    </div>
</form>
