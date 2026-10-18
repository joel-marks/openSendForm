# openSendForm — current state

Last updated: 2026-10-18 (feature/status-tab-and-rebrand, Claude Code)

## Status
The service is end-to-end and release-ready as **v0.2.0** (the openSendForm /
osf-php rebrand release). A versioned v1 API drives an ordered validation/abuse
pipeline; passing submissions are stored and relayed by authenticated SMTP
(in-request send + operator retry sweep). Full admin panel (auth, 2FA,
forms/submissions CRUD, Email + Deliverability + **Status** tabs, an 8-step
browser installer), a client-site embed with a no-JS fallback, dev tooling,
migrations, guarded deletion, release packaging, synthetic monitoring +
alerting, and a defence-in-depth hardening layer are all built and green.
Suite: **666 tests**. CI runs tests + a package build/verify on every PR/push.

## Product definition
Free, open-source, self-hostable **PHP form-to-email app for shared cPanel/PHP
hosting** — display name **openSendForm**, short form **osf-php**. One install
serves many websites via an embedded snippet + JS posting to a central endpoint.
Validated, abuse-filtered, relayed by authenticated SMTP to the site owner. It
does one job; it is not a general-purpose library/framework.

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
  EXEMPT (never renamed). See QUESTIONS.md for the exact scope of the sweep.

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist →
per-IP then per-form rate limits → honeypot → token → Turnstile (optional) →
email (bounded MX/A) → store → delivery (terminal, always succeeds). Locked by
SubmitPipelineOrderTest. Synthetic marking rides on the store stage only.

## Status tab (this sprint — feature/status-tab-and-rebrand)
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

## Mail form (this sprint)
- **Optional SMTP auth.** Empty username = no auth (anonymous relays / Mailpit),
  honoured by `PhpMailerMailer` (asserted via a `configure()` seam). The admin
  page and installer step reject a password with no username in plain language
  and save fine with empty auth; From address stays required.
- **Deliverability advice** skips placeholder/reserved From domains (localhost,
  example.*, .test/.invalid/.local/…) with a plain "real domains only" notice;
  the DMARC `rua` references the From domain's own postmaster.
- **Dev-only Mailpit prefill** link (+ a ? help link) on the shared mail-fields
  partial, driven by admin.js, hidden in production (admin + installer).
- `.osf-switch-field` now owns component-level vertical rhythm (was `margin:0`).

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
  un-unit-tested (all bound behind seams the suite drives). The two Firefox
  browser checks (header surface, action-row rhythm) are non-CI.
- Docs site (opensendform.com/guides/…) links are agreed permanent URLs but the
  site is not yet live (now includes /guides/local-testing for the Mailpit link).

## Open items
None blocking. QUESTIONS.md carries prior notes plus five non-blocking
status-tab/rebrand decisions (package.json name left per literal instruction;
composer name `opensendform/osf-php`; rebrand sweep scope; monitor staleness
window; world-writable probe directory set).

## Planned increment sequence
0–9 (skeleton → … → synthetic monitoring) + hardening sweep + onboarding v2 +
chrome/rhythm/onboarding + cron-noise/monitor-cost + Status tab + rebrand.
ALL DONE — v0.2.0 ready.
