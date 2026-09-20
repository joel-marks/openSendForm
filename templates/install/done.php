<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * The install-complete screen. Confirms success, then gives the operator the
 * two things a shipped install needs a human to do by hand: set up the two
 * scheduled tasks (cron) and, optionally, per-form bot protection.
 *
 * @var string $version
 * @var string $phpBinary The PHP CLI binary baked into the cron commands.
 * @var string $monitorCmd Full monitor:run command (php + path + subcommand).
 * @var string $retryCmd    Full mail:retry command.
 */
?>
<h1>OpenSendForm is installed 🎉</h1>

<p>Setup is complete. You can now sign in and start creating forms.</p>

<p><a href="/admin/login" role="button">Go to sign in</a></p>

<h2>Set up your scheduled tasks (cron)</h2>

<p>OpenSendForm needs two small scheduled jobs. Copy each command below and add
    it as a cron job in cPanel:</p>

<ol>
    <li>In cPanel open <strong>Cron Jobs</strong>.</li>
    <li>Under <strong>Common Settings</strong> choose <strong>Once Per Hour
        (0 * * * *)</strong>.</li>
    <li>Paste the first command into the <strong>Command</strong> box and click
        <strong>Add New Cron Job</strong>. Then add the second job the same way,
        but change the <strong>Minute</strong> field from <code>0</code> to
        <code>5</code> so the two jobs don’t run at the same moment.</li>
</ol>

<h3>Job 1 — monitoring</h3>
<p><small>Checks every hour that your forms are still working and emails you if
    one stops.</small></p>
<p class="osf-copy">
    <code><?= h($monitorCmd) ?></code>
    <button type="button" class="secondary outline" data-copy="<?= h($monitorCmd) ?>"><?= icon('copy') ?> Copy</button>
</p>

<h3>Job 2 — retry failed emails</h3>
<p><small>Re-sends, once an hour, any submission emails that failed the first
    time (for example if your mail server was briefly unreachable).</small></p>
<p class="osf-copy">
    <code><?= h($retryCmd) ?></code>
    <button type="button" class="secondary outline" data-copy="<?= h($retryCmd) ?>"><?= icon('copy') ?> Copy</button>
</p>

<p><small>If cron reports that the PHP path (<code><?= h($phpBinary) ?></code>)
    does not work, your host’s PHP command line is usually
    <code>/usr/local/bin/php</code> — replace the path at the start of each
    command with that.</small></p>

<h2>Good to know</h2>

<ul>
    <li>
        <strong>Optional bot protection.</strong> Each form can use Cloudflare
        Turnstile — a free, privacy-friendly check that blocks automated spam.
        It’s optional and can be switched on any time from a form’s settings. See
        <a href="https://opensendform.com/guides/turnstile" target="_blank" rel="noopener">the Turnstile guide</a>.
    </li>
    <li>
        <strong>The installer is now locked, and that’s safe.</strong> A visitor
        cannot re-run setup or take over your site. If you ever genuinely need to
        run the installer again, follow
        <a href="https://opensendform.com/guides/reinstall" target="_blank" rel="noopener">the reinstall guide</a>.
    </li>
</ul>

<p><small>Installed version <?= h($version) ?>.</small></p>
