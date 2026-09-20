<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use PHPUnit\Framework\TestCase;

use function OpenSendForm\Admin\icon;

/**
 * Contract tests for the increment 5d design system. These are file-level
 * assertions (no HTTP harness needed): the single-source-of-colour rule, the
 * blocking theme bootstrap ordering, the vendored icon helper, the top-nav /
 * no-sidebar shape and the responsive card-collapse hooks. The HTTP-rendered
 * behaviour (CSP, asset refs, live nav) is covered by AdminUiTest.
 */
final class DesignSystemTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::root() . '/' . $relative);
    }

    // --- Single source of colour ------------------------------------------

    /**
     * The ONLY stylesheet allowed to carry literal colour values is
     * tokens.css. Every template, admin.css and the JS enhancers must consume
     * the --osf-* tokens instead. vendor/qrcode.js and the out-of-scope embed
     * (public/embed/osf.js) are the documented exemptions.
     */
    public function testNoHardcodedColoursOutsideTokens(): void
    {
        $files = array_merge(
            glob(self::root() . '/templates/admin/*.php'),
            glob(self::root() . '/templates/install/*.php'),
            glob(self::root() . '/public/assets/*.css'),
            glob(self::root() . '/public/assets/*.js')
        );

        $exempt = [
            self::root() . '/public/assets/tokens.css',        // the token contract itself
            self::root() . '/public/assets/vendor/qrcode.js',  // vendored dependency (not in glob, listed for clarity)
        ];

        // A hex colour is exactly 3/4/6/8 hex digits after '#'. HTML numeric
        // entities (&#10; etc.) are stripped first so they never false-match.
        $hex = '/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})\b/';
        $func = '/\b(?:rgb|rgba|hsl|hsla)\s*\(/i';
        // Named CSS colours, matched only as a CSS value (in .css/.js files).
        // The trailing (?![\w-]) stops hyphenated properties like
        // "white-space" or "border-*" from false-matching a colour name.
        $named = '/[:\s(](?:white|black|red|green|blue|yellow|orange|purple|pink|'
            . 'gray|grey|silver|gold|brown|cyan|magenta|maroon|navy|olive|teal|'
            . 'lime|aqua|fuchsia|coral|crimson|indigo|violet|khaki|salmon)(?![\w-])/i';

        foreach ($files as $file) {
            if (in_array($file, $exempt, true)) {
                continue;
            }
            $raw = (string) file_get_contents($file);
            $stripped = preg_replace('/&#\d+;/', '', $raw);

            self::assertDoesNotMatchRegularExpression(
                $hex,
                $stripped,
                "Hardcoded hex colour in " . basename($file) . " — use an --osf-* token from tokens.css"
            );
            self::assertDoesNotMatchRegularExpression(
                $func,
                $stripped,
                "Hardcoded rgb/hsl colour in " . basename($file) . " — use an --osf-* token from tokens.css"
            );

            if (preg_match('/\.(css|js)$/', $file)) {
                self::assertDoesNotMatchRegularExpression(
                    $named,
                    $stripped,
                    "Named CSS colour in " . basename($file) . " — use an --osf-* token from tokens.css"
                );
            }
        }
    }

    public function testTokensDefineTheFullContractForBothSchemes(): void
    {
        $tokens = self::read('public/assets/tokens.css');

        // Dark default on :root, light under [data-theme="light"].
        self::assertStringContainsString(':root', $tokens);
        self::assertStringContainsString('[data-theme="light"]', $tokens);

        // A representative slice of the canonical contract must be present.
        foreach ([
            '--osf-bg', '--osf-bg-raised', '--osf-bg-inset', '--osf-bg-overlay',
            '--osf-text', '--osf-text-muted', '--osf-text-subtle', '--osf-text-on-accent',
            '--osf-border', '--osf-border-muted',
            '--osf-accent', '--osf-accent-emphasis', '--osf-accent-subtle',
            '--osf-success', '--osf-success-subtle', '--osf-warning', '--osf-warning-subtle',
            '--osf-danger', '--osf-danger-subtle', '--osf-info', '--osf-info-subtle',
            '--osf-focus-ring',
            '--osf-font-body', '--osf-font-heading', '--osf-font-mono',
            '--osf-radius', '--osf-radius-lg',
        ] as $token) {
            self::assertStringContainsString($token, $tokens, "tokens.css is missing {$token}");
        }
    }

    // --- Theme bootstrap: no flash of the wrong theme ---------------------

    /**
     * theme-init.js must be the first HTML element inside <head> so the stored
     * theme is applied before first paint. (PHP comment blocks don't count as
     * elements; strip them before checking.)
     */
    public function testThemeInitIsFirstInHead(): void
    {
        foreach (['templates/admin/layout.php', 'templates/install/layout.php'] as $layout) {
            $raw = self::read($layout);
            $noPhp = preg_replace('/<\?php.*?\?>/s', '', $raw);
            // The src is emitted through the versioning asset() helper (a short
            // echo tag in the raw template), so match the theme-init reference
            // loosely rather than a literal path.
            self::assertMatchesRegularExpression(
                '#<head>\s*<script src="[^"]*theme-init\.js[^"]*"></script>#',
                (string) $noPhp,
                "theme-init.js is not the first element in <head> of {$layout}"
            );
        }
    }

    public function testThemeInitReadsStorageAndSetsAttributes(): void
    {
        $js = self::read('public/assets/theme-init.js');
        self::assertStringContainsString("'osf-theme'", $js);
        self::assertStringContainsString('data-theme', $js);
        self::assertStringContainsString('data-theme-mode', $js);
        self::assertStringContainsString('prefers-color-scheme', $js);
    }

    // --- Vendored Lucide icons --------------------------------------------

    public function testIconHelperRendersValidInlineSvg(): void
    {
        require_once self::root() . '/src/Admin/helpers.php';
        require_once self::root() . '/src/Admin/icons.php';

        $required = [
            'sun', 'moon', 'monitor', 'copy', 'download', 'trash-2', 'pencil',
            'check', 'x', 'alert-triangle', 'info', 'book-open', 'log-out',
            'eye', 'eye-off', 'chevron-down',
            'layout-dashboard', 'file-text', 'inbox', 'mail', 'users',
        ];

        foreach ($required as $name) {
            $svg = icon($name);
            self::assertStringStartsWith('<svg', $svg, "icon('{$name}') did not render an <svg>");
            self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
            self::assertStringContainsString('stroke="currentColor"', $svg);
            self::assertStringEndsWith('</svg>', $svg, "icon('{$name}') is not a closed <svg>");
        }

        // Unknown icons render nothing (fail closed, no broken markup).
        self::assertSame('', icon('no-such-icon'));

        // A label makes the icon non-decorative.
        self::assertStringContainsString('role="img"', icon('trash-2', '', 'Delete'));
        self::assertStringContainsString('aria-label="Delete"', icon('trash-2', '', 'Delete'));

        // The ISC licence note is retained in the vendored file.
        $iconsFile = self::read('src/Admin/icons.php');
        self::assertStringContainsString('ISC', $iconsFile);
        self::assertStringContainsString('Lucide', $iconsFile);
    }

    // --- Top nav in the header, no sidebar --------------------------------

    public function testNavIsATopHeaderWithDocsLinkAndNoSidebar(): void
    {
        // The header markup now lives in the single shared component
        // (src/Admin/appbar.php), not a per-area nav partial.
        $nav = self::read('src/Admin/appbar.php');

        // Header bar shape (not a docs layout), plus the tab bar beneath it.
        self::assertStringContainsString('osf-header', $nav);
        self::assertStringContainsString('osf-tabnav', $nav);

        // The five primary destinations live in the tab bar (labels are
        // passed as string args to the tab builder in the template).
        foreach (['Dashboard', 'Forms', 'Submissions', 'Email', 'Admins'] as $label) {
            self::assertStringContainsString("'{$label}'", $nav, "Nav is missing the {$label} tab");
        }

        // Account is NOT a tab — it lives on the admin-name link in the header.
        self::assertStringNotContainsString("'Account'", $nav);
        self::assertStringContainsString('osf-admin-name', $nav);

        // Docs link: external, new tab, noopener, book-open icon — in the header.
        self::assertStringContainsString('href="https://opensendform.com"', $nav);
        self::assertStringContainsString('target="_blank"', $nav);
        self::assertStringContainsString('rel="noopener"', $nav);
        self::assertStringContainsString("icon('book-open')", $nav);

        // Theme toggle uses the three Lucide glyphs (not the old text glyph).
        self::assertStringContainsString('data-theme-toggle', $nav);
        self::assertStringContainsString("icon('sun'", $nav);
        self::assertStringContainsString("icon('moon'", $nav);
        self::assertStringContainsString("icon('monitor'", $nav);

        // Each of the five tabs is built with its own Lucide icon argument.
        foreach ([
            'dashboard'   => 'layout-dashboard',
            'forms'       => 'file-text',
            'submissions' => 'inbox',
            'mail'        => 'mail',
            'admins'      => 'users',
        ] as $key => $iconName) {
            self::assertMatchesRegularExpression(
                "/\\\$tab\\('{$key}', '[^']*', '[^']*', '{$iconName}'\\)/",
                $nav,
                "Tab '{$key}' is not wired to the '{$iconName}' icon"
            );
        }

        // No docs-style furniture anywhere in the admin templates.
        foreach (array_merge(
            glob(self::root() . '/templates/admin/*.php'),
            [self::root() . '/templates/install/layout.php']
        ) as $file) {
            $html = (string) file_get_contents($file);
            self::assertStringNotContainsString('<aside', $html, 'No sidebar in ' . basename($file));
            self::assertStringNotContainsString('role="complementary"', $html, 'No sidebar in ' . basename($file));
            self::assertDoesNotMatchRegularExpression('/class="[^"]*sidebar/i', $html, 'No sidebar in ' . basename($file));
        }
    }

    // --- Header: two rows on ONE shared surface, one hairline under the
    // second (architect art-direction ruling, reverses fix/5d-polish-v3's
    // surface split) --------------------------------------------------------

    public function testHeaderIsTwoRowsOnOneSharedSurfaceWithOneHairlineUnderTheSecond(): void
    {
        $css = self::read('public/assets/admin.css');

        self::assertMatchesRegularExpression('/\.osf-header\s*\{([^}]*)\}/s', $css, '.osf-header rule not found');
        preg_match('/\.osf-header\s*\{([^}]*)\}/s', $css, $headerBlock);
        // Top row: near-black --osf-bg-inset, matching github.com's header.
        // Exact token match (word-boundary on the closing paren) so a stray
        // --osf-bg-raised/--osf-bg-overlay can't slip past a loose substring
        // check — those tokens contain "--osf-bg-inset" is not a risk here,
        // but the mirrored --osf-bg-raised exclusion below guards the case
        // that actually broke the tab row.
        self::assertStringContainsString('--osf-bg-inset', $headerBlock[1]);
        self::assertStringNotContainsString(
            '--osf-bg-raised',
            $headerBlock[1],
            'Top row must not reference the raised surface'
        );
        self::assertStringNotContainsString(
            'border-bottom',
            $headerBlock[1],
            'No divider between the header\'s two rows'
        );

        self::assertMatchesRegularExpression('/\.osf-tabnav\s*\{([^}]*)\}/s', $css, '.osf-tabnav rule not found');
        preg_match('/\.osf-tabnav\s*\{([^}]*)\}/s', $css, $tabnavBlock);
        // Tab row: SAME surface as the top row, --osf-bg-inset — the two rows
        // are one shared block, not two distinct surfaces. Match the exact
        // token (not followed by "-raised"/"-overlay") so a stray raised
        // surface can't slip past a loose substring check.
        self::assertMatchesRegularExpression(
            '/background:\s*var\(--osf-bg-inset\)/',
            $tabnavBlock[1],
            'Tab row background must resolve to exactly var(--osf-bg-inset), matching the top row'
        );
        self::assertStringNotContainsString('--osf-bg-raised', $tabnavBlock[1]);
        self::assertStringContainsString(
            'border-bottom',
            $tabnavBlock[1],
            'The single hairline sits under the second (tab) row'
        );

        // No surface between the two rows: the header closes and the tab <nav>
        // opens immediately (the single .osf-appbar wrapper is the shared
        // surface, not a raised one between them). Verified against the
        // component source and — end to end — by AppbarTest on rendered pages.
        $appbar = self::read('src/Admin/appbar.php');
        self::assertStringContainsString('</div></header>', $appbar);
        self::assertStringContainsString('<nav class="osf-tabnav"', $appbar);

        // No other rule in the stylesheet backgrounds .osf-header or
        // .osf-tabnav (e.g. a broader "header, nav" or wrapper selector) —
        // each selector must be declared exactly once.
        self::assertSame(1, preg_match_all('/\.osf-header\s*\{/', $css), 'Exactly one .osf-header rule expected');
        self::assertSame(1, preg_match_all('/\.osf-tabnav\s*\{/', $css), 'Exactly one .osf-tabnav rule expected');
    }

    // --- Header/tab bar: full-width surfaces, content aligned to the column

    public function testHeaderAndTabBarSpanTheViewportWithColumnAlignedContent(): void
    {
        $css = self::read('public/assets/admin.css');
        $nav = self::read('src/Admin/appbar.php');

        // The bars themselves (.osf-header/.osf-tabnav) carry no max-width —
        // only their "-inner container" children are column-constrained.
        self::assertDoesNotMatchRegularExpression('/\.osf-header\s*\{[^}]*max-width/s', $css);
        self::assertDoesNotMatchRegularExpression('/\.osf-tabnav\s*\{[^}]*max-width/s', $css);
        self::assertStringContainsString('osf-header-inner container', $nav);
        self::assertStringContainsString('osf-tabnav-inner container', $nav);
    }

    // --- Tab bar: architect-supplied active-tab orange ---------------------

    public function testTabBarUsesTheArchitectSuppliedActiveTabToken(): void
    {
        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression(
            '/\.osf-tab-link\[aria-current="page"\]\s*\{[^}]*--osf-tab-active/s',
            $css,
            'The active tab underline does not reference --osf-tab-active'
        );

        $tokens = self::read('public/assets/tokens.css');
        self::assertStringContainsString('--osf-tab-active:', $tokens);
        self::assertStringContainsString('#f78166', $tokens);
    }

    // --- Account menu: <details>/<summary>, no standalone logout button ---

    public function testAccountMenuIsADetailsDropdownWithLogoutInsideIt(): void
    {
        $nav = self::read('src/Admin/appbar.php');

        // The admin name is a native <details>/<summary> dropdown (no JS
        // needed to open/close it, CSP-safe) rather than a plain link.
        self::assertStringContainsString('<details class="osf-account-menu">', $nav);
        self::assertStringContainsString('<summary class="osf-nav-link osf-admin-name">', $nav);
        self::assertStringContainsString("icon('chevron-down'", $nav);

        // The panel holds the account link, the external Reinstall guide link
        // (styled like Docs: new tab + noopener) and the logout form.
        self::assertStringContainsString('class="osf-account-panel"', $nav);
        self::assertStringContainsString('href="/admin/account">Your account</a>', $nav);
        self::assertStringContainsString('href="https://opensendform.com/guides/reinstall"', $nav);
        self::assertStringContainsString('action="/admin/logout"', $nav);
        self::assertStringContainsString('osf-account-item--danger', $nav);

        // Exactly one logout form in the nav partial — no standalone logout
        // button sits outside the dropdown.
        self::assertSame(1, substr_count($nav, 'action="/admin/logout"'));
    }

    // --- Responsive tables collapse to cards ------------------------------

    public function testDataTablesCarryCardCollapseHooks(): void
    {
        $tableTemplates = [
            'templates/admin/dashboard.php',
            'templates/admin/forms_list.php',
            'templates/admin/submissions.php',
            'templates/admin/admins.php',
            'templates/install/requirements.php',
        ];

        foreach ($tableTemplates as $tpl) {
            $html = self::read($tpl);
            // Matches "osf-table" as a whole class token — forms_list.php also
            // carries the osf-table--forms fixed-layout modifier alongside it.
            self::assertMatchesRegularExpression(
                '/class="osf-table(?:\s|")/',
                $html,
                "{$tpl} table is not marked osf-table"
            );
            self::assertStringContainsString('data-label="', $html, "{$tpl} cells carry no data-label for card collapse");
        }

        // The stylesheet actually collapses them at narrow widths using the
        // data-label values as the per-cell headings.
        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression('/@media\s*\(max-width:\s*640px\)/', $css);
        self::assertStringContainsString('attr(data-label)', $css);
    }

    public function testSubmissionErrorsAreExpandable(): void
    {
        foreach (['templates/admin/submissions.php', 'templates/admin/dashboard.php'] as $tpl) {
            $html = self::read($tpl);
            // No-JS-safe expander for the full error text.
            self::assertStringContainsString('<details class="osf-error-detail">', $html, "{$tpl} error cell is not expandable");
            self::assertStringContainsString('<summary>', $html);
        }
    }

    // --- Admins: status toggle switch replaces Deactivate/Reactivate ------

    public function testAdminsStatusColumnIsASwitchNotButtons(): void
    {
        $html = self::read('templates/admin/admins.php');

        self::assertStringNotContainsString('>Deactivate<', $html);
        self::assertStringNotContainsString('>Reactivate<', $html);
        self::assertStringContainsString('class="osf-switch"', $html);
        self::assertStringContainsString('role="switch"', $html);
        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('aria-pressed="false"', $html);
        // Disabled state (last active admin) carries an explanatory title.
        self::assertStringContainsString('disabled', $html);
        self::assertStringContainsString('title="The last active admin cannot be deactivated."', $html);

        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression('/\.osf-switch\s*\{/', $css);
        self::assertMatchesRegularExpression('/\.osf-switch\[aria-pressed="true"\]\s*\{[^}]*--osf-success/s', $css);
    }

    // --- Forms page: equal-width small buttons -----------------------------

    public function testFormsRowActionsAreEqualWidth(): void
    {
        $html = self::read('templates/admin/forms_list.php');
        self::assertMatchesRegularExpression(
            '/class="secondary osf-btn-sm osf-btn-equal"[^>]*>.*?Edit/s',
            $html
        );
        self::assertMatchesRegularExpression(
            '/class="<\?= \$active[^"]*osf-btn-sm osf-btn-equal"/s',
            $html
        );

        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression('/\.osf-btn-equal\s*\{[^}]*min-width/s', $css);
    }

    // --- Forms table: fixed layout so no row state can force horizontal
    // scroll (fix/forms-table-scroll-and-stat-links) ------------------------

    /**
     * A disabled form's wider "disabled" badge / "Enable" action previously
     * widened the Status/Actions columns for every row under auto table
     * layout, pushing the table past its container and triggering a
     * horizontal scrollbar. Locking table-layout: fixed (with explicit,
     * summed-to-100% column widths) on the forms table specifically means no
     * single row's content can ever grow the table wider than its wrap.
     */
    public function testFormsTableUsesFixedLayoutToPreventHorizontalOverflow(): void
    {
        $html = self::read('templates/admin/forms_list.php');
        self::assertMatchesRegularExpression(
            '/class="osf-table osf-table--forms"/',
            $html,
            'forms_list.php table must carry the osf-table--forms fixed-layout modifier'
        );

        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression(
            '/\.osf-table--forms\s*\{[^}]*table-layout:\s*fixed/s',
            $css,
            '.osf-table--forms must set table-layout: fixed'
        );

        // Every column gets an explicit width, and they sum to 100% — no
        // column is left to auto-size off row content.
        preg_match_all(
            '/\.osf-table--forms t[hd]:nth-child\((\d)\)[^{]*\{\s*width:\s*(\d+)%/',
            $css,
            $matches,
            PREG_SET_ORDER
        );
        $widthByColumn = [];
        foreach ($matches as $m) {
            $widthByColumn[(int) $m[1]] = (int) $m[2];
        }
        self::assertCount(6, $widthByColumn, 'Expected an explicit width for each of the 6 forms-table columns');
        self::assertSame(100, array_sum($widthByColumn), 'Forms table column widths must sum to 100%');
    }

    // --- Header-block surfaces pinned (fix/header-surfaces-pinned) ---------

    /**
     * Defence-in-depth on top of testHeaderIsTwoRowsOnOneSharedSurfaceWithOneHairlineUnderTheSecond:
     * the generic .container rule must never carry a background (layout only),
     * and every child wrapper between .osf-header/.osf-tabnav and their tab
     * links must be pinned to transparent so nothing can paint a raised
     * surface over the ruled header/tab-row backgrounds.
     */
    public function testContainerIsLayoutOnlyAndHeaderBlockChildrenArePinnedTransparent(): void
    {
        $css = self::read('public/assets/admin.css');

        self::assertMatchesRegularExpression('/(?<!main)\.container\s*\{([^}]*)\}/s', $css, '.container rule not found');
        preg_match('/(?<!main)\.container\s*\{([^}]*)\}/s', $css, $containerBlock);
        self::assertStringNotContainsString(
            'background',
            $containerBlock[1],
            '.container must be layout-only (max-width/padding/margin), no background'
        );

        self::assertMatchesRegularExpression('/main\.container\s*\{([^}]*)\}/s', $css, 'main.container rule not found');
        preg_match('/main\.container\s*\{([^}]*)\}/s', $css, $mainBlock);
        self::assertStringNotContainsString(
            'background',
            $mainBlock[1],
            'main.container must carry no background — body supplies --osf-bg'
        );

        foreach ([
            '.osf-header-inner' => 'transparent',
            '.osf-tabnav-inner' => 'transparent',
        ] as $selector => $expected) {
            $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/s';
            self::assertMatchesRegularExpression($pattern, $css, "{$selector} rule not found");
            preg_match($pattern, $css, $block);
            self::assertMatchesRegularExpression(
                '/background:\s*' . $expected . '/',
                $block[1],
                "{$selector} must be pinned to background: {$expected}"
            );
        }

        // .osf-tab-link and its hover/active states: transparent only, never
        // a raised/inset surface.
        foreach ([
            '/\.osf-tab-link\s*\{([^}]*)\}/s',
            '/\.osf-tab-link:hover\s*\{([^}]*)\}/s',
            '/\.osf-tab-link\[aria-current="page"\]\s*\{([^}]*)\}/s',
        ] as $pattern) {
            self::assertMatchesRegularExpression($pattern, $css);
            preg_match($pattern, $css, $block);
            self::assertMatchesRegularExpression('/background:\s*transparent/', $block[1]);
            self::assertStringNotContainsString('--osf-bg-raised', $block[1]);
            self::assertStringNotContainsString('--osf-bg-inset', $block[1]);
        }
    }

    // --- Vertical rhythm: component-owned, single source (Task 2) ----------

    /**
     * Rhythm lives in admin.css, never per page. The greppable rule (defined
     * pragmatically here): no page template may carry an inline `style="..."`
     * attribute that sets BLOCK SPACING — margin, padding or gap. A non-spacing
     * inline style such as the embed snippet's honeypot `display:none` is fine;
     * only ad-hoc block spacing is forbidden, because that is exactly what the
     * component rules in admin.css now own.
     */
    public function testTemplatesCarryNoInlineBlockSpacingAndAdminCssOwnsRhythm(): void
    {
        $templates = array_merge(
            glob(self::root() . '/templates/admin/*.php'),
            glob(self::root() . '/templates/install/*.php'),
            glob(self::root() . '/templates/_shared/*.php')
        );

        $inlineSpacing = '/style="[^"]*(?:margin|padding|gap)[^"]*"/i';
        foreach ($templates as $tpl) {
            $html = (string) file_get_contents($tpl);
            self::assertDoesNotMatchRegularExpression(
                $inlineSpacing,
                $html,
                basename($tpl) . ' sets block spacing inline — admin.css owns vertical rhythm'
            );
        }

        // admin.css is the single source: the block components carry their
        // standard margins from the --osf-space scale.
        $css = self::read('public/assets/admin.css');

        // Action rows own their top gap (so no page hand-adds one).
        self::assertMatchesRegularExpression(
            '/\.osf-actions\s*\{[^}]*margin-top:\s*var\(--osf-space-5\)/s',
            $css,
            '.osf-actions must own a standard top margin from the scale'
        );
        // The installer step action row's OWN rule carries no margin any more —
        // it only lays the row out; the gap comes from .osf-actions.
        self::assertMatchesRegularExpression('/\.osf-step-actions\s*\{([^}]*)\}/s', $css);
        preg_match('/\.osf-step-actions\s*\{([^}]*)\}/s', $css, $stepBlock);
        self::assertStringNotContainsString(
            'margin',
            $stepBlock[1],
            '.osf-step-actions must not re-declare a one-off margin (single source of rhythm)'
        );
        // Fields, tables and panels carry their own vertical margins.
        self::assertMatchesRegularExpression('/\.osf-field\s*\{[^}]*margin-bottom:\s*var\(--osf-space/s', $css);
        self::assertMatchesRegularExpression('/\.osf-table-wrap\s*\{[^}]*margin-bottom:\s*var\(--osf-space/s', $css);
        self::assertMatchesRegularExpression('/section\s*\{[^}]*margin-bottom:\s*var\(--osf-space/s', $css);
    }

    // --- Versioned asset URLs (structural cache-busting) ---------------------

    public function testAssetHelperAppendsTheAppVersion(): void
    {
        require_once self::root() . '/src/Admin/helpers.php';

        $url = \OpenSendForm\Admin\asset('/assets/admin.css');
        self::assertSame('/assets/admin.css?v=' . \OpenSendForm\Version::STRING, $url);

        // The version is sourced from the single Version constant, never a
        // hand-typed string in the helper or the templates.
        $helpers = self::read('src/Admin/helpers.php');
        self::assertStringContainsString('Version::STRING', $helpers);
        foreach (['templates/admin/layout.php', 'templates/install/layout.php'] as $layout) {
            $tpl = self::read($layout);
            self::assertDoesNotMatchRegularExpression(
                '/\?v=\d+\.\d+\.\d+/',
                $tpl,
                "{$layout} must not hand-type a version; use asset()"
            );
            self::assertStringContainsString("asset('/assets/theme-init.js')", $tpl);
        }
    }

    // --- Dashboard stat cards: subtle background tint, full-strength edge --

    /**
     * Architect-directed refinement (fix/forms-table-scroll-and-stat-links):
     * the card BACKGROUND stays on the restrained -subtle family exactly as
     * before, but the thin left edge now uses the FULL-STRENGTH status token
     * so it reads brighter — still only 3px wide. Numerals stay uncoloured.
     */
    public function testDashboardStatCardsUseSubtleBackgroundAndFullStrengthEdge(): void
    {
        $css = self::read('public/assets/admin.css');

        $tokensBySuffix = [
            'info'    => ['bg' => '--osf-info-subtle',    'edge' => '--osf-info'],
            'success' => ['bg' => '--osf-success-subtle', 'edge' => '--osf-success'],
            'danger'  => ['bg' => '--osf-danger-subtle',  'edge' => '--osf-danger'],
        ];

        foreach ($tokensBySuffix as $suffix => $tokens) {
            $rulePattern = '/\.osf-stat--' . $suffix . '\s*\{([^}]*)\}/s';
            self::assertMatchesRegularExpression($rulePattern, $css, ".osf-stat--{$suffix} rule not found");
            preg_match($rulePattern, $css, $block);

            self::assertMatchesRegularExpression(
                '/background:\s*var\(' . preg_quote($tokens['bg'], '/') . '\)/',
                $block[1],
                ".osf-stat--{$suffix} background must stay on the subtle token {$tokens['bg']}"
            );
            // The edge token must be the FULL-strength variant — exact match
            // (word boundary on the closing paren) so the -subtle token of the
            // same family can't slip past a loose substring check.
            self::assertMatchesRegularExpression(
                '/border-left:\s*3px\s+solid\s+var\(' . preg_quote($tokens['edge'], '/') . '\)/',
                $block[1],
                ".osf-stat--{$suffix} left edge must use the full-strength token {$tokens['edge']}, not -subtle"
            );
            self::assertStringNotContainsString(
                'border-left: 3px solid var(' . $tokens['bg'] . ')',
                $block[1],
                ".osf-stat--{$suffix} left edge must no longer use the subtle token"
            );
            // Edge stays thin — the refinement only changes colour, not width.
            self::assertMatchesRegularExpression(
                '/border-left:\s*3px\s+solid/',
                $block[1],
                ".osf-stat--{$suffix} left edge must stay 3px"
            );
            self::assertStringNotContainsString(
                'color:',
                $block[1],
                ".osf-stat--{$suffix} must not colour the numerals"
            );
        }

        // The heavy PR#27 accents (coloured values, the --accent/--warning
        // tones) are gone.
        self::assertStringNotContainsString('.osf-stat-value { color:', $css);
        self::assertStringNotContainsString('.osf-stat--accent', $css);
        self::assertStringNotContainsString('.osf-stat--warning', $css);

        // The template computes each card's tone in PHP via statCardToneClass —
        // not a hand-typed modifier and not JS.
        $dashboard = self::read('templates/admin/dashboard.php');
        self::assertStringContainsString('statCardToneClass($activeForms, false)', $dashboard);
        self::assertStringContainsString('statCardToneClass($todayCount, false)', $dashboard);
        self::assertStringContainsString('statCardToneClass($failedCount, true)', $dashboard);
        self::assertStringContainsString('statCardToneClass($deadCount, true)', $dashboard);
    }

    // --- Dashboard stat cards: the whole card is a link to its destination --

    public function testDashboardStatCardsAreLinksToTheirDestinations(): void
    {
        $dashboard = self::read('templates/admin/dashboard.php');

        // Each card's outer element is an <a> (not a div/article) carrying
        // the exact destination — the SubmissionsController's existing
        // status= filter parameter for the two filtered cards, invented
        // nothing new.
        $destinationByLabel = [
            'Active forms'       => '/admin/forms',
            'Submissions today'  => '/admin/submissions',
            'Failed \(retrying\)' => '/admin/submissions?status=failed',
            'Dead \(gave up\)'   => '/admin/submissions?status=dead',
        ];

        foreach ($destinationByLabel as $label => $href) {
            self::assertMatchesRegularExpression(
                '/<a href="' . preg_quote($href, '/') . '" class="osf-stat[^"]*">\s*<div class="osf-stat-value">.*?<div class="osf-stat-label">' . $label . '/s',
                $dashboard,
                "Stat card '{$label}' must be a whole-card link to {$href}"
            );
        }

        // No leftover non-interactive card markup.
        self::assertStringNotContainsString('<article class="osf-stat', $dashboard);

        $css = self::read('public/assets/admin.css');
        // Reset link defaults so the card keeps its existing visual
        // treatment rather than looking like inline text.
        self::assertMatchesRegularExpression(
            '/\.osf-stat\s*\{[^}]*text-decoration:\s*none/s',
            $css,
            '.osf-stat must reset the anchor underline'
        );
        self::assertMatchesRegularExpression(
            '/\.osf-stat\s*\{[^}]*color:\s*inherit/s',
            $css,
            '.osf-stat must reset the anchor colour'
        );
        // A hover affordance exists, using tokens only (asserted separately by
        // testNoHardcodedColoursOutsideTokens — this just checks the rule is
        // present at all).
        self::assertMatchesRegularExpression('/\.osf-stat:hover\s*\{/', $css, '.osf-stat:hover rule not found');
        // Keyboard focus must be visible via the shared focus-ring token.
        self::assertMatchesRegularExpression(
            '/\.osf-stat:focus-visible\s*\{[^}]*--osf-focus-ring/s',
            $css,
            '.osf-stat:focus-visible must use --osf-focus-ring'
        );
    }

    // --- Dashboard stat cards: the value->tone mapping rules ------------------

    /**
     * @dataProvider statToneCases
     */
    public function testStatCardToneMapping(int $value, bool $failureStat, string $expected): void
    {
        require_once self::root() . '/src/Admin/helpers.php';
        self::assertSame($expected, \OpenSendForm\Admin\statCardToneClass($value, $failureStat));
    }

    /** @return array<string, array{int, bool, string}> */
    public static function statToneCases(): array
    {
        return [
            // Zero is always info/blue, regardless of what the stat measures.
            'zero non-failure -> info'      => [0, false, 'osf-stat--info'],
            'zero failure     -> info'      => [0, true, 'osf-stat--info'],
            // Non-zero: success, except failure-measuring stats -> danger.
            'non-zero non-failure -> success' => [3, false, 'osf-stat--success'],
            'non-zero failure     -> danger'  => [2, true, 'osf-stat--danger'],
        ];
    }

    // --- Deletion controls: danger-toned, trash icon -----------------------

    /**
     * Every destructive control introduced by the deletion feature must carry
     * the .osf-danger tone (never a hardcoded colour — that is separately
     * enforced by testNoHardcodedColoursOutsideTokens) and the trash-2 glyph.
     * The row controls also match the small-button tier of their neighbours.
     */
    public function testDeletionControlsAreDangerToned(): void
    {
        // Forms list: a small danger Delete link per row.
        $formsList = self::read('templates/admin/forms_list.php');
        self::assertMatchesRegularExpression(
            '#/delete"[^>]*class="osf-danger osf-btn-sm"#',
            $formsList,
            'Forms row Delete must be a small danger control'
        );

        // Submissions list: a small danger Delete link per row, plus the
        // danger-toned "Delete all" control near the filter bar.
        $subsList = self::read('templates/admin/submissions.php');
        self::assertMatchesRegularExpression(
            '#/delete<\?= h\(\$returnQuery\) \?>"[^>]*class="osf-danger osf-btn-sm"#',
            $subsList,
            'Submissions row Delete must be a small danger control'
        );
        self::assertStringContainsString('delete-all', $subsList, 'A "Delete all" control must exist');

        // The three confirm pages: a danger-toned destructive button + trash icon.
        foreach ([
            'form_delete_confirm',
            'submission_delete_confirm',
            'submissions_delete_all_confirm',
        ] as $tpl) {
            $html = self::read('templates/admin/' . $tpl . '.php');
            self::assertMatchesRegularExpression(
                '/<button type="submit" class="osf-danger"/',
                $html,
                "{$tpl}: the destructive button must be danger-toned"
            );
            self::assertStringContainsString("icon('trash-2')", $html, "{$tpl}: missing the trash glyph");
        }
    }

    // --- Admins: add-admin section spacing ----------------------------------

    public function testAddAdminSectionHasTopSpacing(): void
    {
        $html = self::read('templates/admin/admins.php');
        self::assertMatchesRegularExpression(
            '/<section class="osf-section-top">\s*<h2>Add an admin<\/h2>/',
            $html
        );

        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression(
            '/\.osf-section-top\s*\{[^}]*margin-top:\s*var\(--osf-space-6\)/s',
            $css
        );
    }
}
