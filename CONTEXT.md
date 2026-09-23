# OpenSendForm — current state

Last updated: 2026-09-20 (feature/chrome-rhythm-onboarding, Claude Code)

## Status
The service is end-to-end and has been through pre-deployment hardening plus two
onboarding/UX polish sprints informed by live cPanel walk-throughs. A versioned
v1 API drives an ordered validation/abuse pipeline; passing submissions are
stored and relayed by authenticated SMTP (in-request send + operator retry
sweep). Full admin panel (auth, 2FA, forms/submissions CRUD, Email +
Deliverability tabs, an 8-step browser installer), a client-site embed with a
no-JS fallback, dev tooling, migrations, guarded deletion, release packaging
(v0.1.0 zip, stamped/verified permission policy), synthetic monitoring +
alerting, and a defence-in-depth hardening layer are all built and green.
Suite: **614 tests**. CI runs tests + a package build/verify on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared cPanel/PHP
hosting. One installation serves many websites via an embedded snippet + JS
posting to a central endpoint. Validated, abuse-filtered, relayed by
authenticated SMTP to the site owner.

## Decisions locked
- PHP 8.1+ / Slim 4 / Composer / PHPMailer / SQLite default (MySQL optional via
  PDO). Server-rendered admin UI on a bespoke `--osf-*` token stylesheet; no JS
  framework, no build step, no daemon in production. All deps ship in the zip.
- All SQL portable across sqlite + mysql; timestamps stored UTC `Y-m-d H:i:s`.
  Form keys are PUBLIC identifiers, stored plain.
- Response contract (FROZEN): JSON only by default. Success `{"ok":true}`;
  failure `{"ok":false,"error":{"code","message"}}`. A text/html client (native
  browser POST) gets HTML.
- Bot-facing checks fail SILENTLY (honeypot; missing/forged/too-young token →
  fake success). Expired token → honest `400 token_expired`. No-JS path with
  `allow_nojs=0` → honest `400 javascript_required`.
- Config precedence: defaults < var/config.php < environment (env always wins).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist →
per-IP then per-form rate limits → honeypot → token → Turnstile (optional) →
email (bounded MX/A) → store → delivery (terminal, always succeeds). Locked by
SubmitPipelineOrderTest. Synthetic marking rides on the store stage only.

## Chrome / rhythm / onboarding (this sprint — feature/chrome-rhythm-onboarding)
- **One header component (`.osf-appbar`).** `src/Admin/appbar()` renders the
  ENTIRE two-row header (row 1 brand surface: product name, Docs, theme toggle,
  account menu where a session exists; row 2 the tab strip) inside ONE wrapper.
  Both rows live only here — impossible to render one without the other. Two
  variants by parameter: `full` (admin) and `chrome-only` (login, installer,
  SubmitHtmlPage, error pages — no account menu, row 2 present but empty of nav,
  keeping its surface + hairline via `.osf-tabnav-inner:empty`). The login
  chrome-free styling was REVERSED. AppbarTest walks every rendered page
  asserting exactly one `.osf-appbar` with both rows; browser titles are all
  `osf - {page name}` (walk-tested).
- **Component-owned vertical rhythm.** Block components carry standard margins
  from the `--osf-space` scale in admin.css; action rows (`.osf-actions`) own a
  top margin so no page hand-adds one (table-cell / toolbar action groups pinned
  tight). The `.osf-step-actions` one-off margin was stripped (it now only lays
  the stepper row out). Guard test forbids inline block-spacing in `templates/**`.
  `tests/browser/rhythm-check.mjs` (non-CI) asserts nonzero computed action-row
  top spacing in both themes.
- **Installer is an 8-step onboarding stepper.** Welcome → Requirements →
  Database → Admin account → Email sending → Scheduled tasks → Bot protection →
  Finish. Each step shares the shell (chrome-only appbar + step label, "Step N
  of 7", one action row: primary right, secondary/skip left). Finish is the
  terminal page, OUTSIDE the count (Step N of **7**). NEW steps: Scheduled tasks
  (cron purpose + copy-paste commands via `Install\CronCommands` + cPanel recipe;
  records the choice) and Bot protection (Turnstile signpost only). Finish
  replaced Done as a status CHECKLIST reporting what was persisted, keeping the
  reinstall note and a single "Go to your dashboard".
- **Scheduled-tasks (cron) state.** New `CRON_SETUP` config key ('' | done |
  later). The cron command block is findable post-install in the admin Email tab
  (`/admin/mail#cron`) with a "mark set up" control. A dashboard reminder banner
  shows while the choice is `later` and no monitor run has been observed
  (`MonitorRepository::hasAnyCheck()`); it self-clears once the monitor cron runs
  or the tasks are marked done.
- **Small admin items.** Account menu gained an external "Reinstall app" link
  (→ /guides/reinstall, styled like Docs). New `bin/osf admin:list` (id, name,
  email, active, 2FA state; usage text updated).

## Onboarding + mail UX (prior sprint — feature/onboarding-v2)
Installer email step; `commit($db,$extra)` persists SMTP + MONITOR_BASE_URL;
mail page in cPanel field order with encryption→port auto-fill and a From
suggestion; Deliverability tab split out; CLI headings/verdicts; release
permission policy stamped + verified. (See HISTORY.)

## Security hardening (prior sprint — feature/hardening-sweep)
Installer session destruction on commit; `admin:reset-2fa`; production error
pages (frozen JSON / minimal HTML, no trace) — now wearing the chrome-only
appbar; TOTP replay prevention; no-auto-migration doctrine; config-driven rate
limits; strict security headers + `BaselineHeadersMiddleware`; trusted-proxy
ruling. (See HISTORY.)

## Mail delivery + retry
In-request DeliveryStage makes at most one send then always returns success
(skipped when no mailer / MAIL_ENABLED falsy / SMTP_HOST empty). Retry sweep
(`DeliveryService::retryDue`) re-attempts due failed + never-attempted received
rows, escalating to 'dead' at MAIL_MAX_ATTEMPTS. Email-domain DNS check bounded,
fail-open.

## Design system (condensed — see HISTORY)
- Single colour source `public/assets/tokens.css` (`--osf-*`), DesignSystemTest
  forbids hardcoded colour outside it; values frozen. `.osf-appbar` is the one
  header component; `.osf-field`/`.osf-actions`/tables/`section` own their
  margins. Versioned asset URLs via `OpenSendForm\Admin\asset()`.
- Nav tabs: Dashboard, Forms, Submissions, Email, Deliverability, Admins.

## Other subsystems — condensed; see HISTORY
- Admin deletion (hard + deactivate, three guards); form/submission deletion.
- Synthetic monitoring: `bin/osf monitor:run` (cron), state in `monitor_checks`.
- Dev tooling: `composer serve` → `public/dev-router.php`; `bin/osf migrate`.
- Packaging: `bin/build-release.php` / `verify-release.php`; `src/Version.php`.
- Auth stack: argon2id, TOTP/recovery (replay-protected), CSRF, hardened sessions.

## Known gaps / not built (by design)
- No AUTOMATIC migration trigger on upgrade (manual `bin/osf migrate` + banner).
- Password reset by email, roles/permissions, audit log; file uploads / redirect
  success URLs (out of embed scope).
- MySQL live-test, NativeSession `$_SESSION`, real SMTP/UDP DNS sockets, the
  monitor's real CurlHttpClient socket and osf.js DOM behaviour stay
  un-unit-tested (all bound behind seams the suite drives). The two Firefox
  browser checks (header surface, action-row rhythm) are non-CI.
- Docs site (opensendform.com/guides/…) links are agreed permanent URLs but the
  site is not yet live.

## Open items
None blocking. QUESTIONS.md carries prior notes plus three non-blocking
chrome-rhythm-onboarding decisions: chrome-only appbar keeps Docs + theme toggle
on no-session pages; "Step N of 7" counts the seven configurable steps with
Finish terminal outside it; the cron reminder self-clears on monitor_checks
evidence (not mail:retry, which leaves no table trace).

## Planned increment sequence
0–9 (skeleton → … → synthetic monitoring) + hardening sweep + onboarding v2 +
chrome/rhythm/onboarding. ALL DONE. Next sprint: the Status tab (rides on this
chrome); then the rebrand (Sprint D).
