<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * Step 7: Bot protection (Cloudflare Turnstile). Signpost only — nothing is
 * configured here. OSF runs fine without it (the honeypot, submit tokens and
 * rate limits stand alone); Turnstile is the recommended additional layer,
 * configured per form after install. The form posts to the commit action.
 *
 * @var string $csrf
 */
?>
<?php require __DIR__ . '/_progress.php'; ?>
<h1>Bot protection</h1>

<p>
    OpenSendForm already blocks automated spam on its own — a hidden honeypot,
    signed submit tokens and rate limits all work with nothing extra to set up.
    For an even stronger layer you can add <strong>Cloudflare Turnstile</strong>,
    a free, privacy-friendly “are you human?” check, to any form after install.
</p>

<div class="osf-flash osf-flash--info" role="status">
    <p><?= icon('shield') ?> Turnstile is optional and configured
        <strong>per form</strong> from the form’s settings — there’s nothing to
        enter here. See <a href="https://opensendform.com/guides/turnstile" target="_blank" rel="noopener">the
        Turnstile guide</a> when you’re ready.</p>
</div>

<form method="post" action="/install/finish">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <div class="osf-actions osf-step-actions">
        <button type="submit" class="secondary outline osf-step-skip">Skip for now</button>
        <button type="submit">Finish setup</button>
    </div>
</form>
