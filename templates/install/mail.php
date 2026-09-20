<?php
use function OpenSendForm\Admin\h;

/**
 * The installer's skippable "Email sending" step. Reuses the shared SMTP-fields
 * partial (identical to the admin Email page). Opens with cPanel guidance for a
 * non-technical operator, offers a test send, and can be skipped entirely —
 * leaving email off until it is set up later.
 *
 * @var string $csrf
 * @var string $error
 * @var string $smtpHost
 * @var string $smtpPort
 * @var string $smtpEncryption
 * @var string $smtpUser
 * @var bool   $passwordSet
 * @var string $fromAddress
 * @var string $fromName
 * @var bool   $passwordError
 * @var string $testRecipient
 */
?>
<?php require __DIR__ . '/_progress.php'; ?>
<h1>Set up email sending</h1>

<p>
    OpenSendForm delivers each form submission to you by email. Enter the details
    of the mailbox it should send from — or skip this and set it up later; either
    way your setup finishes on the next screen.
</p>

<div class="osf-flash osf-flash--info" role="status">
    <p><strong>On cPanel:</strong> creating a subdomain does <strong>not</strong>
        create a mailbox. First make an email account under
        <strong>Email Accounts</strong>, then open its
        <strong>Connect Devices</strong> page — that lists the SMTP host, port and
        encryption to type below. The username is the full email address and the
        password is that mailbox’s password.</p>
    <p><small>Not on cPanel? Any provider’s outgoing-mail (SMTP) settings work the
        same way — host, port, username and password.</small></p>
</div>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/install/mail">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <?php require dirname(__DIR__) . '/_shared/mail_fields.php'; ?>

    <hr>

    <div class="osf-field">
        <label for="test_recipient">Send a test to (optional)</label>
        <input type="email" id="test_recipient" name="test_recipient"
               value="<?= h($testRecipient) ?>" placeholder="you@example.com" autocomplete="off">
        <small>Use <strong>Send test email</strong> below to check the details work
            before finishing. Defaults to your From address if left blank.</small>
    </div>

    <div class="osf-actions osf-step-actions">
        <button type="submit" name="action" value="skip" class="secondary outline osf-step-skip" formnovalidate>Skip for now</button>
        <button type="submit" name="action" value="test" class="secondary">Send test email</button>
        <button type="submit" name="action" value="save">Save and continue</button>
    </div>
    <p><small>Skipping leaves email off: submissions will be stored but not
        emailed until you set this up from the admin panel.</small></p>
</form>
