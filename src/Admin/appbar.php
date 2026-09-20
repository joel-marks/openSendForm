<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

/*
 * The ONE application header component (class osf-appbar).
 *
 * Both header rows — row 1 the brand surface, row 2 the tab strip — are emitted
 * here and only here, inside a single .osf-appbar wrapper. It is structurally
 * impossible to render one row without the other: there is exactly one code path
 * and it always produces both. Every layout in the app (admin, installer, login,
 * the HTML submit page and the error pages) renders its chrome through this
 * function, so the header can never drift per page again.
 *
 * Two variants, selected by parameter (never by separate markup):
 *   - full        the signed-in admin header: brand links home, Docs + theme
 *                 toggle + account menu in row 1, the populated tab strip in row 2.
 *   - chrome-only  login / installer / HTML submit + error pages: a no-session
 *                 brand row (product name, Docs, theme toggle — no account menu),
 *                 and row 2 present but EMPTY of nav items, keeping its shared
 *                 surface + hairline. The installer passes a step label for row 2.
 */

if (!function_exists('OpenSendForm\\Admin\\appbar')) {
    /**
     * Render the two-row .osf-appbar.
     *
     * @param array{
     *   variant?: string,     'full' | 'chrome-only' (default 'chrome-only')
     *   active?: string,      full: the active tab key
     *   adminName?: string,   full: signed-in display name (drives the account menu)
     *   csrf?: string,        full: CSRF token for the logout form
     *   stepLabel?: string    chrome-only: optional row-2 label (e.g. installer step)
     * } $o
     */
    function appbar(array $o = []): string
    {
        $variant   = ($o['variant'] ?? 'chrome-only') === 'full' ? 'full' : 'chrome-only';
        $active    = (string) ($o['active'] ?? '');
        $adminName = (string) ($o['adminName'] ?? '');
        $csrf      = (string) ($o['csrf'] ?? '');
        $stepLabel = (string) ($o['stepLabel'] ?? '');

        // --- Row 1: brand surface -----------------------------------------
        // The brand is a link home only when signed in; otherwise inert text.
        $brand = $variant === 'full'
            ? '<a class="osf-brand" href="/admin">OpenSendForm</a>'
            : '<span class="osf-brand">OpenSendForm</span>';

        $docs = '<a class="osf-nav-link osf-nav-docs" href="https://opensendform.com"'
            . ' target="_blank" rel="noopener">'
            . icon('book-open') . ' Docs'
            . '<span class="osf-visually-hidden"> (opens in a new tab)</span></a>';

        $toggle = '<button type="button" class="osf-theme-toggle" data-theme-toggle'
            . ' aria-label="Toggle colour theme" title="Toggle colour theme">'
            . icon('sun', 'osf-icon-sun') . icon('moon', 'osf-icon-moon')
            . icon('monitor', 'osf-icon-monitor') . '</button>';

        // The account menu appears only where a session exists (full variant).
        $account = '';
        if ($variant === 'full' && $adminName !== '') {
            $account = '<details class="osf-account-menu">'
                . '<summary class="osf-nav-link osf-admin-name">'
                . h($adminName) . icon('chevron-down', 'osf-account-caret')
                . '</summary>'
                . '<div class="osf-account-panel">'
                . '<a class="osf-account-item" href="/admin/account">Your account</a>'
                . '<a class="osf-account-item" href="https://opensendform.com/guides/reinstall"'
                . ' target="_blank" rel="noopener">'
                . icon('rotate-ccw') . ' Reinstall app'
                . '<span class="osf-visually-hidden"> (opens in a new tab)</span></a>'
                . '<form method="post" action="/admin/logout" class="osf-inline-form">'
                . '<input type="hidden" name="_csrf" value="' . h($csrf) . '">'
                . '<button type="submit" class="osf-account-item osf-account-item--danger">'
                . icon('log-out') . ' Log out</button>'
                . '</form>'
                . '</div></details>';
        }

        // --- Row 2: the tab strip (full) or an optional step label --------
        if ($variant === 'full') {
            $tab = static function (string $key, string $href, string $label, string $iconName) use ($active): string {
                $aria = $key === $active ? ' aria-current="page"' : '';
                return '<a class="osf-tab-link" href="' . h($href) . '"' . $aria . '>'
                    . icon($iconName) . ' ' . h($label) . '</a>';
            };
            $row2 = $tab('dashboard', '/admin', 'Dashboard', 'layout-dashboard')
                . $tab('forms', '/admin/forms', 'Forms', 'file-text')
                . $tab('submissions', '/admin/submissions', 'Submissions', 'inbox')
                . $tab('mail', '/admin/mail', 'Email', 'mail')
                . $tab('deliverability', '/admin/deliverability', 'Deliverability', 'shield-check')
                . $tab('admins', '/admin/admins', 'Admins', 'users');
        } else {
            $row2 = $stepLabel !== ''
                ? '<span class="osf-appbar-step">' . h($stepLabel) . '</span>'
                : '';
        }

        return '<div class="osf-appbar">'
            . '<header class="osf-header"><div class="osf-header-inner container">'
            . $brand
            . '<div class="osf-header-actions">' . $docs . $toggle . $account . '</div>'
            . '</div></header>'
            . '<nav class="osf-tabnav" aria-label="Primary"><div class="osf-tabnav-inner container">'
            . $row2
            . '</div></nav>'
            . '</div>';
    }
}
