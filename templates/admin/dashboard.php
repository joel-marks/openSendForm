<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\truncate;
use function OpenSendForm\Admin\statusBadgeClass;
use function OpenSendForm\Admin\statCardToneClass;
use function OpenSendForm\Admin\icon;

/**
 * @var int   $activeForms
 * @var int   $todayCount
 * @var int   $failedCount
 * @var int   $deadCount
 * @var bool  $totpEnabled
 * @var array<int, array<string, mixed>> $recent
 */
?>
<h1>Dashboard</h1>

<?php if (($pendingMigrations ?? 0) > 0): ?>
    <?php /* Not dismissible: this stays until the admin actually runs the
             migration, unlike the 2FA/mail nudges below. */ ?>
    <div class="osf-flash osf-flash--error" role="alert">
        <?= icon('alert-triangle') ?>
        <span><strong>Database update required</strong> &mdash; run <code>bin/osf migrate</code>
        (<?= h((string) $pendingMigrations) ?> pending migration<?= $pendingMigrations === 1 ? '' : 's' ?>).</span>
    </div>
<?php endif; ?>

<?php if (($monitorFailures ?? []) !== []): ?>
    <?php /* Not dismissible: a failing synthetic check means visitors may be
             unable to submit (or their submissions are not reaching the owner).
             It clears itself as soon as a later check passes. */ ?>
    <div class="osf-flash osf-flash--error" role="alert">
        <?= icon('alert-triangle') ?>
        <span><strong>Synthetic monitoring detected a problem</strong> &mdash;
        <?php
        $names = array_map(static fn (array $f): string => h((string) $f['form_name']), $monitorFailures);
        ?>
        the latest check failed for
        <?= count($names) === 1 ? 'form' : 'forms' ?> <?= implode(', ', $names) ?>.
        See <code>bin/osf monitor:status</code> for detail.</span>
    </div>
<?php endif; ?>

<?php if (($showNudge ?? false) === true): ?>
    <?php /* Dismissible-per-session nudge urging 2FA enrolment. Dismissing
             sets a session flag (see AdminController::dismissNudge); it
             returns after 2FA is disabled again. */ ?>
    <div class="osf-flash osf-flash--info osf-nudge" role="note">
        <span>
            <?= icon('info') ?>
            <strong>Protect your account.</strong>
            Two-factor authentication is not enabled.
            <a href="/admin/totp/setup">Set it up now</a>.
        </span>
        <form method="post" action="/admin/nudge/dismiss" class="osf-inline-form">
            <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
            <button type="submit" class="secondary outline osf-btn-sm" aria-label="Dismiss">
                <?= icon('x') ?>
            </button>
        </form>
    </div>
<?php endif; ?>

<?php if (($showMailNudge ?? false) === true): ?>
    <?php /* Second dismissible nudge, mirroring the 2FA one: email sending is
             off, so submissions are saved but not delivered. Points at the
             mail-setup wizard. Returns if sending is turned off again. */ ?>
    <div class="osf-flash osf-flash--info osf-nudge" role="note">
        <span>
            <?= icon('info') ?>
            <strong>Email sending is not set up yet.</strong>
            Submissions are being saved but not emailed to you.
            <a href="/admin/mail">Set up email now</a>.
        </span>
        <form method="post" action="/admin/nudge/mail/dismiss" class="osf-inline-form">
            <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
            <button type="submit" class="secondary outline osf-btn-sm" aria-label="Dismiss">
                <?= icon('x') ?>
            </button>
        </form>
    </div>
<?php endif; ?>

<?php /* Tone per card is decided in PHP (see statCardToneClass): a zero value
         is always info/blue; a non-zero value is success, except the two
         failure-measuring stats (Failed, Dead) which go danger. -subtle family
         only — a restrained accent, no coloured numerals. Each card links to
         its obvious destination; the filtered submissions links reuse the
         existing status= query parameter (see SubmissionsController). */ ?>
<section class="osf-stats">
    <a href="/admin/forms" class="osf-stat <?= h(statCardToneClass($activeForms, false)) ?>">
        <div class="osf-stat-value"><?= h((string) $activeForms) ?></div>
        <div class="osf-stat-label">Active forms</div>
    </a>
    <a href="/admin/submissions" class="osf-stat <?= h(statCardToneClass($todayCount, false)) ?>">
        <div class="osf-stat-value"><?= h((string) $todayCount) ?></div>
        <div class="osf-stat-label">Submissions today</div>
    </a>
    <a href="/admin/submissions?status=failed" class="osf-stat <?= h(statCardToneClass($failedCount, true)) ?>">
        <div class="osf-stat-value"><?= h((string) $failedCount) ?></div>
        <div class="osf-stat-label">Failed (retrying)</div>
    </a>
    <a href="/admin/submissions?status=dead" class="osf-stat <?= h(statCardToneClass($deadCount, true)) ?>">
        <div class="osf-stat-value"><?= h((string) $deadCount) ?></div>
        <div class="osf-stat-label">Dead (gave up)</div>
    </a>
</section>

<h2>Recent delivery problems</h2>
<?php if ($recent === []): ?>
    <p>No failed or dead submissions. 🎉</p>
<?php else: ?>
    <div class="osf-table-wrap">
        <table class="osf-table">
            <thead>
                <tr>
                    <th scope="col">Form</th>
                    <th scope="col">Created</th>
                    <th scope="col">Status</th>
                    <th scope="col">Attempts</th>
                    <th scope="col">Last error</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <?php
                $status = (string) $row['status'];
                $error = (string) ($row['last_error'] ?? '');
                ?>
                <tr>
                    <td data-label="Form">
                        <a href="/admin/submissions?status=<?= h($status) ?>&amp;form=<?= h((string) $row['form_id']) ?>">
                            <?= h((string) ($row['form_name'] ?? ('#' . $row['form_id']))) ?>
                        </a>
                    </td>
                    <td data-label="Created"><?= h((string) $row['created_at']) ?></td>
                    <td data-label="Status"><span class="osf-badge <?= h(statusBadgeClass($status)) ?>"><?= h($status) ?></span></td>
                    <td data-label="Attempts"><?= h((string) $row['attempts']) ?></td>
                    <td data-label="Last error">
                        <?php if ($error === ''): ?>
                            <span aria-hidden="true">—</span>
                        <?php elseif (mb_strlen($error) <= 80): ?>
                            <?= h($error) ?>
                        <?php else: ?>
                            <details class="osf-error-detail">
                                <summary><?= h(truncate($error, 80)) ?></summary>
                                <pre><?= h($error) ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<p>
    <?php if ($totpEnabled === false): ?>
        <a href="/admin/totp/setup">Set up two-factor authentication</a>
    <?php else: ?>
        <a href="/admin/totp/setup">Manage two-factor authentication</a>
    <?php endif; ?>
</p>
