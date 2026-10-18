<?php use function OpenSendForm\Admin\h; ?>
<h1>Two-factor authentication</h1>

<p>Enter the 6-digit code from your authenticator app, or one of your
   recovery codes if you've lost access to it.</p>

<p><small>Lost your authenticator? Enter ONE of your recovery codes instead -
   each is 10 characters (letters and numbers) and works a single time.</small></p>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<?php /* One field serves both a TOTP code and a recovery code (same name,
         no length/pattern limits) so it works unchanged with JS off. With JS
         on, admin.js upgrades it to six digit boxes and adds a toggle that
         swaps back to this same plain input for a recovery code — see
         data-totp-recovery-toggle in admin.js. */ ?>
<form method="post" action="/admin/totp">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <div class="osf-field">
        <label for="code">Authentication code</label>
        <input type="text" id="code" name="code" autocomplete="one-time-code"
               autofocus required data-totp-code data-totp-recovery-toggle>
    </div>
    <button type="submit">Verify</button>
</form>
