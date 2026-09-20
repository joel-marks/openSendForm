<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * The Deliverability tab: live SPF / DKIM / DMARC checks of the sending domain.
 * Non-technical audience — plain language, copy-paste values, and every result
 * names its source (a live DNS lookup of a named record for a named domain).
 *
 * @var array<string, mixed> $report
 * @var string $selector
 * @var string $csrf
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
<h1>Deliverability</h1>

<p>
    These three DNS records tell the world your emails are genuine, so they
    reach the inbox instead of the spam folder. We check the domain of your
    <strong>From address</strong>:
    <strong><?= h($domain) ?: '(set a valid From address on the Email tab first)' ?></strong>.
</p>

<p class="osf-flash osf-flash--info" role="status">
    These records concern the <strong>sending domain only</strong> — the domain
    you send mail <em>from</em>. Recipients are unlimited and need no DNS changes:
    people receiving your form emails do not have to set anything up.
</p>

<?php if (($report['valid'] ?? false) !== true): ?>
    <p class="osf-flash osf-flash--info" role="status">
        Set a valid From address at your own domain on the
        <a href="/admin/mail">Email</a> tab and save, then the records to add
        will appear here.
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
