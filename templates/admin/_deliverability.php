<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * Shared deliverability results — the SPF / DKIM / DMARC readout for the sending
 * domain. This is the SINGLE source of that markup: it is required both by the
 * Deliverability tab (templates/admin/deliverability.php) and by the Status
 * page's Mail section (templates/admin/status.php), so the two can never drift
 * or fork. The caller provides $report (from DeliverabilityChecker::check())
 * and $selector in scope and owns its own page/section heading.
 *
 * @var array<string, mixed> $report
 * @var string $selector
 */
$domain = (string) ($report['domain'] ?? '');

/** One deliverability check row. */
$renderCheck = static function (array $check) use ($domain): void {
    $ok = ($check['ok'] ?? false) === true;
    $badge = $ok ? 'osf-badge--ok' : 'osf-badge--warn';
    $stateText = match ((string) ($check['state'] ?? '')) {
        'present'   => 'Published',
        'found'     => 'Published',
        'absent'    => 'Not found',
        'not_found' => 'Not found',
        default     => 'Unknown',
    };
    ?>
    <article>
        <header>
            <strong><?= h((string) $check['label']) ?></strong>
            <span class="osf-badge <?= h($badge) ?>"><?= h($stateText) ?></span>
        </header>
        <p><small><?= h((string) $check['explain']) ?></small></p>
        <p><small class="osf-source">Source: live DNS lookup of the
            <?= h((string) $check['type']) ?> record at
            <code><?= h((string) ($check['fqdn'] ?? $domain)) ?></code>.</small></p>
        <?php if ((string) ($check['note'] ?? '') !== ''): ?>
            <p><small class="osf-source"><?= h((string) $check['note']) ?></small></p>
        <?php endif; ?>

        <?php if ($ok): ?>
            <p><small>Published value:</small></p>
            <p class="osf-recovery-block"><code><?= h((string) $check['found']) ?></code></p>
        <?php else: ?>
            <?php if ((string) ($check['recommended'] ?? '') !== ''): ?>
                <p><small>Add this <?= h((string) $check['type']) ?> record
                    (name <code><?= h((string) $check['name']) ?></code>):</small></p>
                <span class="osf-copy">
                    <code><?= h((string) $check['recommended']) ?></code>
                    <button type="button" class="secondary outline"
                            data-copy="<?= h((string) $check['recommended']) ?>"><?= icon('copy') ?> Copy</button>
                </span>
            <?php else: ?>
                <p><small>Record to look for: <code><?= h((string) $check['name']) ?></code>.</small></p>
            <?php endif; ?>
            <p><small><?= h((string) $check['where']) ?></small></p>
        <?php endif; ?>
    </article>
<?php
};
?>
<p>
    These three DNS records tell the world your emails are genuine, so they
    reach the inbox instead of the spam folder. We check the domain of your
    <strong>From address</strong>:
    <strong><?= h($domain) ?: '(set a valid From address on the Email tab first)' ?></strong>.
</p>

<p class="osf-flash osf-flash--info" role="status">
    These records concern the <strong>sending domain only</strong> - the domain
    you send mail <em>from</em>. Recipients are unlimited and need no DNS changes:
    people receiving your form emails do not have to set anything up.
</p>

<?php if ($domain === ''): ?>
    <p class="osf-flash osf-flash--info" role="status">
        Set a valid From address at your own domain on the
        <a href="/admin/mail">Email</a> tab and save, then the records to add
        will appear here.
    </p>
<?php elseif (($report['placeholder'] ?? false) === true): ?>
    <p class="osf-flash osf-flash--info" role="status">
        <strong><?= h($domain) ?></strong> looks like a placeholder or local
        testing domain, so it has no public DNS records to publish. Deliverability
        checks apply to a <strong>real domain you own</strong> - set your From
        address to an address at that domain on the <a href="/admin/mail">Email</a>
        tab and save, then the records to add will appear here.
    </p>
<?php else: ?>
    <?php $renderCheck($report['spf']); ?>

    <?php $renderCheck($report['dkim']); ?>
    <form method="get" action="/admin/deliverability">
        <div class="osf-field">
            <label for="dkim_selector">DKIM selector to check</label>
            <div class="grid">
                <input type="text" id="dkim_selector" name="dkim_selector"
                       value="<?= h($selector) ?>" placeholder="default" autocomplete="off">
                <button type="submit" class="secondary outline">Re-check</button>
            </div>
            <small>Most hosts use <code>default</code>. Your provider’s email
                settings tell you the selector if it differs.</small>
        </div>
    </form>

    <?php $renderCheck($report['dmarc']); ?>

    <p><small>After adding a record, it can take a little while for the change
        to spread. Use <strong>Re-check</strong> to look again.</small></p>
<?php endif; ?>
