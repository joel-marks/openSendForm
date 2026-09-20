<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/** @var string $activeNav */
$active = $activeNav ?? '';
$tab = static function (string $key, string $href, string $label, string $iconName) use ($active): string {
    $aria = $key === $active ? ' aria-current="page"' : '';
    return '<a class="osf-tab-link" href="' . h($href) . '"' . $aria . '>' . icon($iconName) . ' ' . h($label) . '</a>';
};
?>
<header class="osf-header">
    <div class="osf-header-inner container">
        <a class="osf-brand" href="/admin">OpenSendForm</a>

        <div class="osf-header-actions">
            <?php /* External docs; opens in a new tab, announced accessibly. */ ?>
            <a class="osf-nav-link osf-nav-docs" href="https://opensendform.com"
               target="_blank" rel="noopener">
                <?= icon('book-open') ?> Docs
                <span class="osf-visually-hidden"> (opens in a new tab)</span>
            </a>

            <button type="button" class="osf-theme-toggle" data-theme-toggle
                    aria-label="Toggle colour theme" title="Toggle colour theme">
                <?= icon('sun', 'osf-icon-sun') ?><?= icon('moon', 'osf-icon-moon') ?><?= icon('monitor', 'osf-icon-monitor') ?>
            </button>

            <?php if (($adminName ?? '') !== ''): ?>
                <?php /* No-JS-safe dropdown (native <details>/<summary>, GitHub's
                         own pattern) — CSP-safe, no JS required to open/close. */ ?>
                <details class="osf-account-menu">
                    <summary class="osf-nav-link osf-admin-name">
                        <?= h($adminName) ?><?= icon('chevron-down', 'osf-account-caret') ?>
                    </summary>
                    <div class="osf-account-panel">
                        <a class="osf-account-item" href="/admin/account">Your account</a>
                        <form method="post" action="/admin/logout" class="osf-inline-form">
                            <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
                            <button type="submit" class="osf-account-item osf-account-item--danger">
                                <?= icon('log-out') ?> Log out
                            </button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </div>
</header>
<nav class="osf-tabnav" aria-label="Primary">
    <div class="osf-tabnav-inner container">
        <?= $tab('dashboard', '/admin', 'Dashboard', 'layout-dashboard') ?>
        <?= $tab('forms', '/admin/forms', 'Forms', 'file-text') ?>
        <?= $tab('submissions', '/admin/submissions', 'Submissions', 'inbox') ?>
        <?= $tab('mail', '/admin/mail', 'Email', 'mail') ?>
        <?= $tab('deliverability', '/admin/deliverability', 'Deliverability', 'shield-check') ?>
        <?= $tab('admins', '/admin/admins', 'Admins', 'users') ?>
    </div>
</nav>
