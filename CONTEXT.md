# openSendForm — current state

Last updated: 2026-10-18 (fix/installer-polish-r2, Claude Code)

## Status
The service is end-to-end and release-ready as **v0.2.0** (the openSendForm /
osf-php rebrand release), now with an installer/admin polish pass on top. A
versioned v1 API drives an ordered validation/abuse pipeline; passing
submissions are stored and relayed by authenticated SMTP (in-request send +
operator retry sweep). Full admin panel (auth, 2FA, forms/submissions CRUD,
Email + Deliverability + Status tabs, an 8-step browser installer), a
client-site embed with a no-JS fallback, dev tooling, migrations, guarded
deletion, release packaging, synthetic monitoring + alerting, and a
defence-in-depth hardening layer are all built and green.
Suite: **676 tests**. CI runs tests + a package build/verify on every PR/push.

## Product definition
Free, open-source, self-hostable **PHP form-to-email app for shared cPanel/PHP
hosting** — display name **openSendForm**, short form **osf-php**. One install
serves many websites via an embedded snippet + JS posting to a central endpoint.
Validated, abuse-filtered, relayed by authenticated SMTP to the site owner. It
does one job; it is not a general-purpose library/framework.

## Copy rule (standing, added this sprint)
No em dashes in user-facing copy (CLAUDE.md standing rule): templates,
installer, CLI output, flash/error messages and README/INSTALL.txt use a
plain hyphen "-" instead of "—"; source-code comments/docblocks are exempt.
`tests/NoEmDashTest.php` tokenizes PHP files (so comments are correctly
excluded) and greps README.md to lock it.

## Decisions locked
- PHP 8.1+ / Slim 4 / Composer / PHPMailer / SQLite default (MySQL optional via
  PDO). Server-rendered admin UI on a bespoke `--osf-*` token stylesheet; no JS
  framework, no build step, no daemon in production. All deps ship in the zip.
- All SQL portable across sqlite + mysql; timestamps stored UTC `Y-m-d H:i:s`.
  Form keys are PUBLIC identifiers, stored plain.
- Response contract (FROZEN): JSON only by default. Success `{"ok":true}`;
  failure `{"ok":false,"error":{"code","message"}}`. A text/html client gets HTML.
- Bot-facing checks fail SILENTLY (honeypot; missing/forged/too-young token →
  fake success). Expired token → honest `400 token_expired`. No-JS path with
  `allow_nojs=0` → honest `400 javascript_required`.
- Config precedence: defaults < var/config.php < environment (env always wins).
- **Branding (v0.2.0):** display name "openSendForm" everywhere user-facing;
  short form "osf-php" for the release zip + package metadata. Page titles stay
  `osf - {page}`. The PHP namespace `OpenSendForm` and all code identifiers are
  EXEMPT (never renamed).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist →
per-IP then per-form rate limits → honeypot → token → Turnstile (optional) →
email (bounded MX/A) → store → delivery (terminal, always succeeds). Locked by
SubmitPipelineOrderTest. Synthetic marking rides on the store stage only.

## Installer/admin polish r2 (this sprint — fix/installer-polish-r2)
- **Stepper skip control, standardised.** Skippable steps (Email sending,
  Scheduled tasks) carry ONE top-of-content, right-aligned "Skip" control
  (`.osf-step-skip-top` + `.osf-link-button`, wired to the real `<form>` via
  the HTML `form=` attribute so it works with JS off); the old per-step
  bottom-row skip button is gone. Bot protection (last step) is the named
  exception: its skip stays bottom-row, next to "Finish setup", relabelled
  "Skip and complete".
- **Appbar row 1 order:** account menu (innermost) → Docs → theme toggle
  (far right); was Docs → toggle → account. Same `.osf-appbar` component
  (`src/Admin/appbar.php`) for both variants, so the installer's chrome-only
  pages show Docs + the theme toggle on every step (never the account menu).
- **Reinstall link moved** from the account-menu dropdown to a plain line
  ("Instructions to reinstall app") under the dashboard's two-factor-setup
  line; same guide URL.
- **Mailpit prefill is a button** ("Use test settings", secondary/small),
  not a link; its "?" help affordance renders inside a CSS-drawn circle
  (`.osf-help-link`); the prefill now fills every required field (added
  `mail_from_name`) so the form saves without further input.
- **Content-width drift fixed:** `scrollbar-gutter: stable` on `html`
  (admin.css) — a page tall enough to scroll no longer narrows
  `main.container` versus a short page. Non-CI Playwright script
  `tests/browser/content-width-check.mjs` added (same pattern as the
  existing header/rhythm checks) alongside a PHPUnit rule-presence lock.
- **Mail-page switch fixed:** the checkbox-markup `.osf-switch` (mail
  "send emails" toggle) now repeats `width`/`height` on its own
  highest-specificity selector (`input[type="checkbox"].osf-switch`), so a
  browser default (`input[type="checkbox"] { width: auto }`, more specific
  than the bare `.osf-switch` class) can no longer collapse it — it now
  renders identically to the button-markup switches (admins page).

## Status tab
`Admin\StatusController` (/admin/status, rightmost nav tab, Lucide `activity`)
renders a live, uncached operational readout; logic in a testable
`Admin\StatusReport`:
- **Environment:** re-probed every view via the installer's `Requirements` +
  `SystemProbe` (PHP vs required, extensions, var/ writability) plus a live
  DB-reachability row, shown with the installer's pass/warn/fail badges.
- **Security:** the viewing admin's 2FA state (red + setup link when off) and a
  world-writable app-directory probe (0002 bit; red when found).
- **Mail:** configured yes/no + sending on/off, then the FULL deliverability
  readout from the shared partial `templates/admin/_deliverability.php` (the one
  markup source, also used by the Deliverability tab — not forked).
- **Operations:** app/schema versions, pending migrations, monitor last-run +
  result from `monitor_checks` (red "not run recently" when the newest check is
  older than 2× the hourly cadence; `MonitorRepository::latest()`), CRON_SETUP.

## Mail form
- **Optional SMTP auth.** Empty username = no auth (anonymous relays / Mailpit),
  honoured by `PhpMailerMailer` (asserted via a `configure()` seam). The admin
  page and installer step reject a password with no username in plain language
  and save fine with empty auth; From address stays required.
- **Deliverability advice** skips placeholder/reserved From domains (localhost,
  example.*, .test/.invalid/.local/…) with a plain "real domains only" notice;
  the DMARC `rua` references the From domain's own postmaster.
- `.osf-switch-field` owns component-level vertical rhythm (was `margin:0`).

## Prior sprints (condensed — see HISTORY)
- **Cron noise + monitor cost:** `--quiet` on `mail:retry`/`monitor:run` (output
  = needs-attention under cPanel); send-timeout 10s; `MONITOR_TIME_BUDGET_SECONDS`
  (45) defers overflow form checks; hourly recommended cadence documented.
- **Chrome/rhythm/onboarding:** one `.osf-appbar` header component (full /
  chrome-only); component-owned vertical rhythm with a template guard;
  8-step installer stepper; `CRON_SETUP` state + dashboard reminder.
- **Onboarding v2:** installer email step; mail page in cPanel field order;
  Deliverability tab split out; CLI headings/verdicts; stamped permission policy.
- **Security hardening:** installer session destruction; `admin:reset-2fa`;
  production error pages (frozen JSON / minimal HTML); TOTP replay prevention;
  no-auto-migration doctrine; config-driven rate limits; strict headers.

## Mail delivery + retry
In-request DeliveryStage makes at most one send then always returns success
(skipped when no mailer / MAIL_ENABLED falsy / SMTP_HOST empty). Retry sweep
(`DeliveryService::retryDue`) re-attempts due failed + never-attempted received
rows, escalating to 'dead' at MAIL_MAX_ATTEMPTS. Email-domain DNS check bounded,
fail-open.

## Design system (condensed — see HISTORY)
- Single colour source `public/assets/tokens.css` (`--osf-*`), DesignSystemTest
  forbids hardcoded colour outside it; values frozen. `.osf-appbar` is the one
  header component; block components own their margins. Versioned asset URLs.
- Nav tabs: Dashboard, Forms, Submissions, Email, Deliverability, Admins, Status.

## Packaging
`bin/build-release.php` / `verify-release.php`; `src/Version.php` (0.2.0). The
zip's single top-level folder is `osf-php/`; the artefact is
`osf-php-vX.Y.Z.zip`. CI uploads it as `osf-php-release-zip`. Modes stamped
(dirs 755, files 644, bin/osf 755) and verified.

## Known gaps / not built (by design)
- No AUTOMATIC migration trigger on upgrade (manual `bin/osf migrate` + banner).
- Password reset by email, roles/permissions, audit log; file uploads / redirect
  success URLs (out of embed scope).
- MySQL live-test, NativeSession `$_SESSION`, real SMTP/UDP DNS sockets, the
  monitor's real CurlHttpClient socket and osf.js DOM behaviour stay
  un-unit-tested (all bound behind seams the suite drives). The three Firefox
  browser checks (header surface, action-row rhythm, content width) are non-CI.
- Docs site (opensendform.com/guides/…) links are agreed permanent URLs but the
  site is not yet live.

## Open items
None blocking. QUESTIONS.md carries prior notes plus three non-blocking
installer-polish-r2 decisions (help-circle glyph implementation, top-skip
button style, content-width fix verified by assertion rather than by
reproducing the pixel drift in this dev container's headless Firefox).

## Planned increment sequence
0–9 (skeleton → … → synthetic monitoring) + hardening sweep + onboarding v2 +
chrome/rhythm/onboarding + cron-noise/monitor-cost + Status tab + rebrand +
installer-polish-r2. ALL DONE — v0.2.0 ready.
