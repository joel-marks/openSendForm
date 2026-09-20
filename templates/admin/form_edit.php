<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * Create/edit form. Shared by "New form" and "Edit form": $isNew toggles the
 * heading, the POST action and the read-only key display. The Turnstile secret
 * is write-only — it is never rendered back; $turnstileSecretSet drives a
 * set/not-set hint instead.
 *
 * @var bool        $isNew
 * @var int|null    $formId
 * @var string|null $formKey
 * @var string      $name
 * @var string      $recipient
 * @var string      $origins
 * @var bool        $storeContent
 * @var int         $retentionDays
 * @var bool        $isActive
 * @var bool        $allowNojs
 * @var string      $turnstileSitekey
 * @var bool        $turnstileSecretSet
 * @var string      $error
 * @var string      $csrf
 * @var string      $installUrl   Installation base URL; empty hides the embed panel.
 * @var string      $embedVersion Embed asset version for the ?v= cache-buster.
 */
$action = $isNew ? '/admin/forms' : '/admin/forms/' . (int) $formId;

$installUrl = $installUrl ?? '';
$embedVersion = $embedVersion ?? '';

// The copy-paste embed snippet: a plain HTML form (whose action IS the submit
// URL, so it works with JavaScript off) plus the one script tag. Built only
// for a saved form with a known installation URL.
$snippet = '';
if (($installUrl) !== '' && $formKey !== null) {
    $base = rtrim($installUrl, '/');
    $submitUrl = $base . '/v1/form/' . $formKey . '/submit';
    $scriptUrl = $base . '/embed/osf.js?v=' . $embedVersion;
    $snippet = '<form action="' . $submitUrl . '" method="post"' . "\n"
        . '      data-osf-key="' . $formKey . '" data-osf-url="' . $base . '">' . "\n"
        . '  <label>Your email' . "\n"
        . '    <input type="email" name="email" required>' . "\n"
        . '  </label>' . "\n"
        . '  <label>Message' . "\n"
        . '    <textarea name="message" required></textarea>' . "\n"
        . '  </label>' . "\n"
        . '  <input type="text" name="_osf_hp" style="display:none" aria-hidden="true" tabindex="-1" autocomplete="off">' . "\n"
        . '  <button type="submit">Send</button>' . "\n"
        . '</form>' . "\n"
        . '<script src="' . $scriptUrl . '" defer></script>' . "\n";
}
?>
<h1><?= $isNew ? 'New form' : 'Edit form' ?></h1>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<?php if (!$isNew && $formKey !== null): ?>
    <p>
        <strong>Form key</strong> (public identifier used by the embed snippet):<br>
        <span class="osf-copy">
            <code><?= h($formKey) ?></code>
            <button type="button" class="secondary outline" data-copy="<?= h($formKey) ?>"><?= icon('copy') ?> Copy</button>
        </span>
    </p>
<?php endif; ?>

<form method="post" action="<?= h($action) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="osf-field">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= h($name) ?>" required>
    </div>

    <div class="osf-field">
        <label for="recipient">Recipient email</label>
        <input type="email" id="recipient" name="recipient" value="<?= h($recipient) ?>" required>
        <small>Where passing submissions are relayed. Never shown to submitters.</small>
    </div>

    <div class="osf-field">
        <label for="origins">Allowed origins (one per line)</label>
        <textarea id="origins" name="origins" rows="3"
                  placeholder="https://example.com&#10;https://www.example.com" required><?= h($origins) ?></textarea>
        <small>Scheme + host (+ optional port), no path. A token/submit is only
            issued to a page whose origin is listed here.</small>
    </div>

    <fieldset>
        <div class="osf-field">
            <label for="store_content">
                <input type="checkbox" id="store_content" name="store_content" value="1"
                       <?= $storeContent ? 'checked' : '' ?>>
                Retain submitted content after delivery
            </label>
            <small>
                Submitted fields are always held while a message is in flight so a
                failed send can be retried. With this <strong>off</strong> (the
                default), that content is cleared once the email is delivered — only
                metadata is kept. Turn it <strong>on</strong> to keep the submitted
                content in storage after a successful delivery too.
            </small>
        </div>
    </fieldset>

    <div class="osf-field">
        <label for="retention_days">Retention (days)</label>
        <input type="number" id="retention_days" name="retention_days"
               value="<?= h((string) $retentionDays) ?>" min="1" max="3650" required>
        <small>Submissions older than this are purged. 1–3650 days.</small>
    </div>

    <div class="osf-field">
        <label for="is_active">
            <input type="checkbox" id="is_active" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
            Active
        </label>
        <small>Inactive forms reject token/submit requests.</small>
    </div>

    <fieldset>
        <div class="osf-field">
            <label for="allow_nojs">
                <input type="checkbox" id="allow_nojs" name="allow_nojs" value="1"
                       <?= $allowNojs ? 'checked' : '' ?>>
                Allow submissions without JavaScript
            </label>
            <small>
                Off (the default) rejects a no-JS submission with an honest
                "requires JavaScript" page and sends nothing. On, a no-JS
                submission is delivered but with reduced bot protection (the
                token's min-time check is skipped for it); a form with Turnstile
                enabled still cannot be submitted without JavaScript either way.
            </small>
        </div>
    </fieldset>

    <fieldset>
        <legend><strong>Cloudflare Turnstile</strong> (optional)</legend>
        <small>
            Provide <em>both</em> a site key and a secret to enable Turnstile,
            or clear the site key to disable it. The secret is stored
            server-side and never displayed.
        </small>

        <div class="osf-field">
            <label for="turnstile_sitekey">Site key</label>
            <input type="text" id="turnstile_sitekey" name="turnstile_sitekey"
                   value="<?= h($turnstileSitekey) ?>" autocomplete="off">
        </div>

        <div class="osf-field">
            <label for="turnstile_secret">Secret key</label>
            <input type="password" id="turnstile_secret" name="turnstile_secret"
                   autocomplete="off"
                   placeholder="<?= $turnstileSecretSet ? '•••••••• (leave blank to keep)' : 'not set' ?>">
            <small>
                <?php if ($turnstileSecretSet): ?>
                    A secret is currently <strong>set</strong>. Leave this blank
                    to keep it, or type a new one to replace it.
                <?php else: ?>
                    <strong>Not set.</strong>
                <?php endif; ?>
            </small>
        </div>
    </fieldset>

    <div class="osf-actions">
        <button type="submit"><?= $isNew ? 'Create form' : 'Save changes' ?></button>
        <a href="/admin/forms" role="button" class="secondary">Cancel</a>
    </div>
</form>

<?php if ($snippet !== ''): ?>
    <hr>
    <section id="embed" aria-labelledby="embed-heading">
        <h2 id="embed-heading">Embed code</h2>
        <p>Paste this into any page you want the form on. It is two parts: a plain
            HTML form and one <code>&lt;script&gt;</code> line. The form posts to
            this installation directly, so it still works if JavaScript is off;
            the script adds inline validation, a sent confirmation and spam
            protection. Edit the fields between the <code>&lt;label&gt;</code>
            tags to suit — only the wrapping <code>&lt;form&gt;</code> and the
            script line must stay as-is.</p>
        <p class="osf-copy">
            <button type="button" class="secondary outline" data-copy="<?= h($snippet) ?>"><?= icon('copy') ?> Copy embed code</button>
        </p>
        <pre><code><?= h($snippet) ?></code></pre>
        <small>Theme it with CSS variables (<code>--osf-accent</code>,
            <code>--osf-error</code>, <code>--osf-radius</code>, …) and listen for
            <code>osf:success</code> / <code>osf:error</code> events. See the
            project README for the full list.</small>
    </section>
<?php endif; ?>
