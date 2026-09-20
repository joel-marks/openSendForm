<?php
use function OpenSendForm\Admin\h;

/**
 * @var string $csrf
 * @var string $error
 * @var int    $minPasswordLength
 * @var string $email
 * @var string $name
 */
?>
<h1>Create your administrator account</h1>

<p>
    This is the account you’ll use to sign in and manage your forms. You can add
    more administrators later.
</p>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/install/admin">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="osf-field">
        <label for="name">Account name</label>
        <input type="text" id="name" name="name" value="<?= h($name) ?>" required autofocus>
    </div>

    <div class="osf-field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= h($email) ?>"
               autocomplete="username" required>
    </div>

    <div class="osf-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password"
               minlength="<?= h((string) $minPasswordLength) ?>" required>
        <small>At least <?= h((string) $minPasswordLength) ?> characters. Use a long,
            unique password — you can change it later.</small>
    </div>

    <div class="osf-field">
        <label for="password_confirm">Confirm password</label>
        <input type="password" id="password_confirm" name="password_confirm"
               autocomplete="new-password" minlength="<?= h((string) $minPasswordLength) ?>" required>
    </div>

    <div class="osf-actions osf-step-actions">
        <button type="submit">Continue</button>
    </div>
</form>
