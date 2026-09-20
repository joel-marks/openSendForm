<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;
use OpenSendForm\Mail\MailSettingsForm;

/**
 * The shared SMTP + From identity fields, used identically by the admin Email
 * page (/admin/mail) and the browser installer's "Email sending" step so the
 * two can never drift apart. The enclosing <form>, the CSRF token, the enable
 * toggle (admin only) and the skip/continue actions live in each caller.
 *
 * Field order follows the cPanel convention a non-technical operator meets:
 * username, password, then hostname; the encryption select leads its row and
 * auto-fills the conventional port (progressive enhancement in admin.js).
 *
 * Expected in scope (extracted by the caller's renderer):
 * @var string $smtpHost
 * @var string $smtpPort
 * @var string $smtpEncryption
 * @var string $smtpUser
 * @var bool   $passwordSet   A password is already stored (write-only field).
 * @var string $fromAddress
 * @var string $fromName
 * @var bool   $passwordError Re-render after a failed save (password cleared).
 */
$passwordError = $passwordError ?? false;
?>
<div class="osf-field">
    <label for="smtp_user">Username</label>
    <input type="text" id="smtp_user" name="smtp_user" value="<?= h($smtpUser) ?>"
           placeholder="you@yourdomain.com" autocomplete="off">
    <small>Usually the full email address of the mailbox you are sending from.</small>
</div>

<div class="osf-field">
    <label for="smtp_pass">Password</label>
    <div class="osf-password">
        <input type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password"
               data-password-input
               placeholder="<?= $passwordSet ? '••••••••  (leave blank to keep the saved password)' : '' ?>">
        <button type="button" class="secondary outline osf-password-toggle"
                data-password-toggle="smtp_pass" aria-label="Show password">
            <?= icon('eye', 'osf-icon-shown') ?><?= icon('eye-off', 'osf-icon-hidden') ?>
        </button>
    </div>
    <?php if ($passwordError): ?>
        <small class="osf-hint-warn">For your security the password box was cleared —
            please type it again before saving.</small>
    <?php else: ?>
        <small><?= $passwordSet
            ? 'A password is saved. Leave this blank to keep it, or type a new one to replace it.'
            : 'The mailbox password. Leave blank only if your server sends without a login.' ?></small>
    <?php endif; ?>
</div>

<div class="osf-field">
    <label for="smtp_host">SMTP host</label>
    <input type="text" id="smtp_host" name="smtp_host" value="<?= h($smtpHost) ?>"
           placeholder="mail.yourdomain.com" autocomplete="off" data-smtp-host>
    <small>The outgoing mail server. In cPanel you find it under
        <strong>Email Accounts → Connect Devices</strong>.</small>
</div>

<div class="grid">
    <div class="osf-field">
        <label for="smtp_encryption">Encryption</label>
        <select id="smtp_encryption" name="smtp_encryption" data-encryption-select>
            <?php foreach (MailSettingsForm::encryptionOptions() as $opt): ?>
                <option value="<?= h($opt['value']) ?>" data-default-port="<?= h($opt['port']) ?>"
                    <?= $smtpEncryption === $opt['value'] ? 'selected' : '' ?>>
                    <?= h($opt['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="osf-field">
        <label for="smtp_port">Port</label>
        <input type="text" id="smtp_port" name="smtp_port" value="<?= h($smtpPort) ?>"
               placeholder="465" inputmode="numeric" autocomplete="off" data-smtp-port>
    </div>
</div>

<hr>

<div class="osf-field">
    <label for="mail_from_address">From address</label>
    <input type="email" id="mail_from_address" name="mail_from_address"
           value="<?= h($fromAddress) ?>" placeholder="hello@yourdomain.com"
           autocomplete="off" data-from-address required>
    <small>What recipients see the email came from. Use an address at your own
        domain so the deliverability records can vouch for it.</small>
</div>

<div class="osf-field">
    <label for="mail_from_name">From name</label>
    <input type="text" id="mail_from_name" name="mail_from_name"
           value="<?= h($fromName) ?>" placeholder="Your Website" autocomplete="off" required>
</div>
