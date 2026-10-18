<?php
use OpenSendForm\Install\Requirements;
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * The Status tab: a live, uncached operational readout. Everything shown is
 * re-probed on each view by StatusController/StatusReport; this template only
 * renders. Pass/warn/fail rows reuse the installer's badge vocabulary; red
 * (danger/error) marks anything the operator must act on.
 *
 * @var array<string, mixed> $status
 * @var string $selector
 */
$badge = [
    Requirements::PASS => ['osf-badge--ok', 'OK'],
    Requirements::WARN => ['osf-badge--warn', 'Heads up'],
    Requirements::FAIL => ['osf-badge--danger', 'Action needed'],
];

$env  = $status['environment'];
$sec  = $status['security'];
$mail = $status['mail'];
$ops  = $status['operations'];
$mon  = $ops['monitor'];

// The shared deliverability partial reads these from scope.
$report = $mail['report'];

$yesNo = static fn (bool $v): string => $v ? 'Yes' : 'No';
?>
<h1>Status</h1>

<p>A live health check of this installation — re-probed every time you open
    this page. Anything marked in red needs your attention.</p>

<section>
    <h2>Environment</h2>
    <div class="osf-table-wrap">
        <table class="osf-table">
            <thead>
                <tr>
                    <th scope="col">Check</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($env as $check): ?>
                <?php [$class, $label] = $badge[$check['status']] ?? ['osf-badge--muted', $check['status']]; ?>
                <tr>
                    <td data-label="Check">
                        <?= h((string) $check['label']) ?>
                        <?php if ((string) $check['remedy'] !== ''): ?>
                            <br><small><?= h((string) $check['remedy']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status"><span class="osf-badge <?= h($class) ?>"><?= h($label) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section>
    <h2>Security</h2>

    <?php if ($sec['twofa_enabled']): ?>
        <p>
            <span class="osf-badge osf-badge--ok">On</span>
            Two-factor authentication is on for your account.
        </p>
    <?php else: ?>
        <div class="osf-flash osf-flash--error" role="alert">
            <strong>Two-factor authentication is OFF for your account.</strong>
            A stolen password is then all it takes to sign in. Turn it on from
            <a href="<?= h((string) $sec['twofa_setup_url']) ?>">your account's two-factor setup</a>.
        </div>
    <?php endif; ?>

    <?php if ($sec['world_writable'] === []): ?>
        <p>
            <span class="osf-badge osf-badge--ok">OK</span>
            No world-writable application directories found.
        </p>
    <?php else: ?>
        <div class="osf-flash osf-flash--error" role="alert">
            <strong>World-writable application directories found.</strong>
            On shared hosting anyone on the server could drop files into these,
            which is a tampering risk. Tighten their permissions to 0755 (or 0775):
            <ul>
                <?php foreach ($sec['world_writable'] as $dir): ?>
                    <li><code><?= h((string) $dir) ?></code></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>

<section>
    <h2>Mail</h2>
    <p>
        <span class="osf-badge <?= $mail['configured'] ? 'osf-badge--ok' : 'osf-badge--muted' ?>">
            <?= h($yesNo($mail['configured'])) ?></span> SMTP configured &nbsp;
        <span class="osf-badge <?= $mail['sending'] ? 'osf-badge--ok' : 'osf-badge--muted' ?>">
            <?= $mail['sending'] ? 'On' : 'Off' ?></span> Sending
        <?= $mail['sending'] ? '' : '(storage-only mode)' ?>
    </p>

    <?php require __DIR__ . '/_deliverability.php'; ?>
</section>

<section>
    <h2>Operations</h2>

    <p><strong>App version:</strong> <?= h((string) $ops['app_version']) ?></p>
    <p><strong>Schema version:</strong> <?= h((string) $ops['schema_version']) ?></p>

    <?php if ((int) $ops['pending_migrations'] > 0): ?>
        <div class="osf-flash osf-flash--error" role="alert">
            <strong><?= h((string) $ops['pending_migrations']) ?> pending database
            migration(s).</strong> Run <code>bin/osf migrate</code> to apply them.
        </div>
    <?php else: ?>
        <p>
            <span class="osf-badge osf-badge--ok">OK</span>
            Database schema is up to date (no pending migrations).
        </p>
    <?php endif; ?>

    <h3>Monitoring</h3>
    <?php if (!$mon['ever_run']): ?>
        <p>
            <span class="osf-badge osf-badge--warn">Heads up</span>
            Synthetic monitoring has not run yet. It runs from the
            <code>monitor:run</code> cron job.
        </p>
    <?php else: ?>
        <?php if ($mon['stale']): ?>
            <div class="osf-flash osf-flash--error" role="alert">
                <strong>Monitoring has not run recently.</strong> The newest check
                is older than expected (it should run about hourly), so the cron
                job may have stopped. Check the <code>monitor:run</code> cron.
            </div>
        <?php endif; ?>
        <p>
            <strong>Last run:</strong> <?= h((string) $mon['last_run_at']) ?> UTC
            —
            <?php if ($mon['ok']): ?>
                <span class="osf-badge osf-badge--ok">Passed</span>
            <?php else: ?>
                <span class="osf-badge osf-badge--danger">Failed</span>
            <?php endif; ?>
            <?php if ((string) ($mon['detail'] ?? '') !== ''): ?>
                <br><small><?= h((string) $mon['detail']) ?></small>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <h3>Scheduled tasks</h3>
    <?php $cron = (string) $ops['cron_setup']; ?>
    <p>
        <?php if ($cron === 'done'): ?>
            <span class="osf-badge osf-badge--ok">Set up</span>
            Cron jobs are marked as set up.
        <?php elseif ($cron === 'later'): ?>
            <span class="osf-badge osf-badge--warn">Deferred</span>
            Cron setup was deferred. See the <a href="/admin/mail#cron">Email page</a>
            for the commands.
        <?php else: ?>
            <span class="osf-badge osf-badge--muted">Unknown</span>
            Cron setup state is not recorded. See the
            <a href="/admin/mail#cron">Email page</a> for the commands.
        <?php endif; ?>
    </p>
</section>
