<?php
use function OpenSendForm\Admin\h;

/**
 * The mail-setup page: SMTP settings, the enable switch and a test send. The
 * SPF/DKIM/DMARC checker now lives on its own "Deliverability" tab. Non-technical
 * audience — plain language, copy-paste values. No inline scripts/handlers
 * (strict CSP); the enhancements (password show/hide, port auto-fill, From
 * suggestion) reuse admin.js.
 *
 * @var string $error
 * @var string $smtpHost
 * @var string $smtpPort
 * @var string $smtpEncryption
 * @var string $smtpUser
 * @var bool   $passwordSet
 * @var string $fromAddress
 * @var string $fromName
 * @var bool   $mailEnabled
 * @var array<int, string> $shadowed
 * @var bool   $offerEnable
 * @var string $testRecipient
 * @var string $csrf
 */
?>
<h1>Email</h1>

<p>
    Set up how OpenSendForm sends the emails your forms produce. Enter your
    mailbox’s SMTP details, turn sending on, then send yourself a test — the
    email arriving is the proof it all works. The DNS records that keep your
    messages out of spam live on the <a href="/admin/deliverability">Deliverability</a> tab.
</p>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<?php if ($shadowed !== []): ?>
    <div class="osf-flash osf-flash--info" role="status">
        <strong>Heads up:</strong> the following
        <?= count($shadowed) === 1 ? 'setting is' : 'settings are' ?> currently set
        by a server environment variable, which overrides what you save here:
        <strong><?= h(implode(', ', $shadowed)) ?></strong>.
        Editing them below has no effect until that override is removed.
    </div>
<?php endif; ?>

<!-- ================= SMTP settings ================= -->
<section>
    <h2>Sending account (SMTP)</h2>
    <p><small>Your host or mailbox provider gives you these details. They are the
        same settings an email program uses to send mail.</small></p>

    <form method="post" action="/admin/mail">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

        <?php
        // Re-render after a failed save clears the password box (3c).
        $passwordError = ($error ?? '') !== '';
        require dirname(__DIR__) . '/_shared/mail_fields.php';
        ?>

        <div class="osf-field">
            <label class="osf-switch-field">
                <input type="checkbox" class="osf-switch" name="mail_enabled" value="1"
                       role="switch" <?= $mailEnabled ? 'checked' : '' ?>>
                <span>Send emails for new submissions</span>
            </label>
            <small>On, passing submissions are emailed to the form’s recipient.
                Off is <strong>storage-only mode</strong>: submissions are still
                saved, they just aren’t emailed — useful while testing or before
                launch.</small>
        </div>

        <div class="osf-actions">
            <button type="submit">Save email settings</button>
        </div>
    </form>
</section>

<!-- ================= Test send ================= -->
<section>
    <h2>Send a test email</h2>
    <p><small>Sends one email using the settings you have <strong>saved</strong>
        (save first if you just changed them). The email arriving is the proof
        it all works.</small></p>

    <form method="post" action="/admin/mail/test">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <div class="osf-field">
            <label for="test_recipient">Send test to</label>
            <input type="email" id="test_recipient" name="test_recipient"
                   value="<?= h($testRecipient) ?>" placeholder="you@example.com" autocomplete="off">
        </div>
        <button type="submit" class="secondary">Send test email</button>
    </form>

    <?php if ($offerEnable): ?>
        <div class="osf-flash osf-flash--info" role="status">
            <p>Your test worked, but email sending is still <strong>off</strong>.
                Turn it on so real submissions get emailed.</p>
            <form method="post" action="/admin/mail/enable" class="osf-inline-form">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                <button type="submit">Enable sending now</button>
            </form>
        </div>
    <?php endif; ?>
</section>
