<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

/*
 * Vendored inline-SVG icon subset from Lucide (https://lucide.dev).
 *
 * Licence: ISC. Copyright (c) 2020, Lucide Contributors. Permission to use,
 * copy, modify and/or distribute this software for any purpose with or without
 * fee is hereby granted, provided the copyright notice and this permission
 * notice appear in all copies. Only the handful of glyphs the admin/installer
 * UI actually uses are vendored; each is Lucide's stock 24x24 stroke path.
 *
 * The icons inherit the current text colour (stroke="currentColor"), so they
 * are visible in every theme — this is what fixes the old toggle-glyph bug
 * where the icon vanished in light mode. Rendered inline so the strict CSP
 * (no external images, img-src 'self' data:) is untouched.
 */

if (!function_exists('OpenSendForm\\Admin\\icon')) {
    /**
     * The inner markup (paths only) of each vendored Lucide glyph.
     *
     * @return array<string, string>
     */
    function icon_paths(): array
    {
        return [
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/>'
                . '<path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/>'
                . '<path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/>'
                . '<path d="m19.07 4.93-1.41 1.41"/>',
            'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
            'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/>'
                . '<line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
            'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>'
                . '<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
                . '<polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
            'trash-2' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>'
                . '<path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>'
                . '<line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
            'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352'
                . 'a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>'
                . '<path d="M12 9v4"/><path d="M12 17h.01"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'book-open' => '<path d="M12 7v14"/>'
                . '<path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13'
                . 'a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
            'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>'
                . '<polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 '
                . '10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
            'eye-off' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/>'
                . '<path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/>'
                . '<path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/>'
                . '<path d="m2 2 20 20"/>',
            'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
            'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/>'
                . '<rect width="7" height="5" x="14" y="3" rx="1"/>'
                . '<rect width="7" height="9" x="14" y="12" rx="1"/>'
                . '<rect width="7" height="5" x="3" y="16" rx="1"/>',
            'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/>'
                . '<path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/>'
                . '<path d="M16 13H8"/><path d="M16 17H8"/>',
            'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/>'
                . '<path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/>'
                . '<path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
            'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6'
                . 'a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'
                . '<path d="m9 12 2 2 4-4"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>'
                . '<circle cx="9" cy="7" r="4"/>'
                . '<path d="M22 21v-2a4 4 0 0 0-3-3.87"/>'
                . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        ];
    }

    /**
     * Render a vendored Lucide icon as inline SVG.
     *
     * The glyph inherits currentColor. Decorative by default (aria-hidden);
     * pass a $label to expose an accessible name (role="img" + <title>).
     *
     * @param string $name  One of the vendored icon keys.
     * @param string $class Extra CSS class(es) appended to "osf-icon".
     * @param string $label Accessible name; empty means decorative.
     */
    function icon(string $name, string $class = '', string $label = ''): string
    {
        $paths = icon_paths();
        if (!isset($paths[$name])) {
            return '';
        }

        $classAttr = 'osf-icon' . ($class !== '' ? ' ' . $class : '');
        $a11y = $label !== ''
            ? ' role="img" aria-label="' . h($label) . '"'
            : ' aria-hidden="true" focusable="false"';

        return '<svg class="' . h($classAttr) . '" viewBox="0 0 24 24" width="16" height="16"'
            . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round"' . $a11y . '>' . $paths[$name] . '</svg>';
    }
}
