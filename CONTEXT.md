# OpenSendForm — current state

Last updated: 2026-10-15 (fix/cron-noise-and-monitor-cost, Claude Code)

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
Suite: **626 tests**. CI runs tests + a package build/verify on every PR/push.

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

## Cron noise + monitor cost (this sprint — fix/cron-noise-and-monitor-cost)
Fixes the two reasons a week of live cPanel soak made the operator delete the
cron jobs: too much mail and host resource warnings.
- **Quiet cron mode.** `bin/osf mail:retry` and `monitor:run` accept `--quiet`:
  silent stdout on an unremarkable run, full output only when a human should
  read it (mail:retry when it attempted anything; monitor:run when a check
  failed, an alert was sent, or an alert could not be sent). Exit codes
  unchanged; the "could not alert" stderr line still prints under --quiet.
  Under cPanel (which mails any cron stdout) this makes output = "needs
  attention". `Install\CronCommands` bakes `--quiet` into both commands, so the
  installer step and admin Email `#cron` block emit it; README/INSTALL.txt +
  both template blocks explain the mail-on-output behaviour.
- **Monitor cost.** `MONITOR_SEND_TIMEOUT_SECONDS` default 30→10; the delivery
  poll is now an immediate read + a few short re-checks at `timeout/5` (delivery
  is synchronous, so the immediate read almost always sees 'sent'; a row still
  'received' at timeout = honest fail). Pre-POST min-time wait = configured
  `MIN_SUBMIT_SECONDS` + 1 margin (never a fixed worst case). New
  `MONITOR_TIME_BUDGET_SECONDS` (default 45): the canary always runs, then the
  run stops starting new form checks once the budget is spent and defers the
  rest to the next run (deferral is not a failure). Non-quiet output ends with a
  one-line `runtime:` summary (elapsed, checks run/deferred, budget).
- **Cadence.** README/INSTALL.txt/installer note hourly is the recommended
  default and lower-powered hosts can run monitor:run less often (the cron
  schedule is the knob; `MONITOR_FORM_INTERVAL_HOURS` spaces non-canary checks).
  README gained a Troubleshooting section (cron mail + resource warnings).

## Chrome / rhythm / onboarding (prior sprint — feature/chrome-rhythm-onboarding)
- **One header component (`.osf-appbar`).** `src/Admin/appbar()` renders the
  entire two-row header (brand row + tab strip) in one wrapper; `full` (admin)
  and `chrome-only` (login, installer, SubmitHtmlPage, error pages) variants.
  AppbarTest walks every page for exactly one appbar; titles `osf - {page}`.
- **Component-owned vertical rhythm.** Block components + `.osf-actions` own
  their `--osf-space` margins; a guard test forbids inline block-spacing in
  `templates/**`; `rhythm-check.mjs` (non-CI) checks computed spacing.
- **Installer is an 8-step stepper.** Welcome → Requirements → Database → Admin
  → Email → Scheduled tasks → Bot protection → Finish. "Step N of 7"; Finish is
  terminal, outside the count. Scheduled-tasks step emits the cron commands via
  `Install\CronCommands`; Finish is a persisted-state checklist.
- **Scheduled-tasks (cron) state.** `CRON_SETUP` config key ('' | done | later).
  Command block findable at `/admin/mail#cron` with a "mark set up" control; a
  dashboard reminder banner shows while `later` + no monitor run observed
  (`MonitorRepository::hasAnyCheck()`), self-clearing on evidence or mark-done.
- **Small admin items.** Account menu "Reinstall app" link; `bin/osf admin:list`.

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
- Synthetic monitoring: `bin/osf monitor:run [--quiet]` (cron), state in
  `monitor_checks`; `MONITOR_*` config incl. send timeout (10s) + time budget
  (45s). `bin/osf mail:retry [--quiet]` is the delivery retry sweep.
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
cron-noise/monitor-cost decisions: MONITOR_SECRET generation is silent under
`--quiet` (per the prescriptive noteworthy list); the delivery poll interval is
derived as `timeout/5`; `monitor:status` is unchanged (deferral surfaces in the
run summary, a deferred form just reads "due now" until the next run).

## Planned increment sequence
0–9 (skeleton → … → synthetic monitoring) + hardening sweep + onboarding v2 +
chrome/rhythm/onboarding + cron-noise/monitor-cost. ALL DONE. Next sprint: the
Status tab (rides on this chrome); then the rebrand (Sprint D).
