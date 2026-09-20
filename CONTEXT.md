# OpenSendForm — current state

Last updated: 2026-09-20 (feature/onboarding-v2, Claude Code)

## Status
The service is end-to-end and has been through pre-deployment hardening and an
onboarding/mail-UX polish sprint informed by a live cPanel walk-through. A
versioned v1 API drives an ordered validation/abuse pipeline; passing
submissions are stored and relayed by authenticated SMTP (in-request send +
operator retry sweep). Full admin panel (auth, 2FA, forms/submissions CRUD,
Email + Deliverability tabs, browser installer with a skippable email step), a
client-site embed with a no-JS fallback, dev tooling, migrations, guarded
deletion, release packaging (v0.1.0 zip, now with a stamped/verified permission
policy), synthetic monitoring + alerting, and a defence-in-depth hardening layer
are all built and green. Suite: **600 tests**. CI runs tests + a package
build/verify on every PR/push.

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
  failure `{"ok":false,"error":{"code","message"}}`. HTTP 200/400/403/405/413/429
  in normal operation; 404/500 error pages also use this shape for API callers.
  A text/html client (native browser POST) gets HTML.
- Bot-facing checks fail SILENTLY (honeypot; missing/forged/too-young token →
  fake success). Expired token → honest `400 token_expired`. No-JS path with
  `allow_nojs=0` → honest `400 javascript_required`.
- Config precedence: defaults < var/config.php < environment (env always wins).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist →
per-IP then per-form rate limits → honeypot → token → Turnstile (optional) →
email (bounded MX/A) → store → delivery (terminal, always succeeds). Locked by
SubmitPipelineOrderTest. Synthetic marking rides on the store stage only.

## Onboarding + mail UX (this sprint — feature/onboarding-v2)
- **Installer email step.** New skippable `/install/mail` after admin creation
  (welcome → database → admin → mail → finish → done). Opens with cPanel
  guidance (subdomain ≠ mailbox; Email Accounts → Connect Devices). Save / test
  send / skip; skip leaves MAIL_ENABLED=0. Test send builds a mailer from the
  just-typed settings via the `MailerFactory` seam (no config written yet).
- **commit() writes mail + MONITOR_BASE_URL.** `InstallerService::commit($db,
  $extra)` persists the SMTP config (when configured) and MONITOR_BASE_URL,
  captured from the request scheme+host at completion (env still overrides).
- **Done screen.** Prints the two cron commands verbatim (monitor:run,
  mail:retry) with real paths baked in (PHP_BINARY + /usr/local/bin/php fallback
  note; bin/osf path from the app's own location) and a three-step cPanel recipe.
- **Mail page (/admin/mail).** cPanel field order (user, pass, host); encryption
  select → port auto-fill (587/465/25); SSL/TLS the fresh-setup default; password
  show/hide toggle; From-address suggestion from the SMTP host domain;
  MAIL_ENABLED a switch-styled checkbox. Shared SMTP fields live in
  `templates/_shared/mail_fields.php`; vocabulary in `Mail\MailSettingsForm`.
- **Deliverability tab (/admin/deliverability).** SPF/DKIM/DMARC checker split
  out of Email (new `DeliverabilityController`). Each result names its live-DNS
  source; page states the records are for the SENDING domain only (recipients
  need no DNS).
- **Forms.** Creating a form redirects to its own page anchored to the embed
  panel (`#embed`).
- **CLI UX.** monitor:run / monitor:status / mail:status: heading + plain verdict
  + empty-state guidance, machine-parsable detail beneath.
- **Release permissions.** Build stamps dirs 755 / files 644 / bin/osf 755 into
  the zip (`osf_release_mode` + `osf_zip_dir` `$modeFor`); verify-release asserts
  them from the archive's own attributes. INSTALL.txt + README rewritten:
  friction-free order, path discovery (no absolute paths), dedicated vs shared
  layouts, AutoSSL step, upgrade path, external-mailbox deliverability note.

## Security hardening (prior sprint — feature/hardening-sweep)
Defence in depth for a shared cPanel unix user co-hosting unrelated sites:
installer session destruction on commit; `bin/osf admin:reset-2fa ID`;
production error pages (frozen JSON / minimal HTML, no trace); TOTP replay
prevention (migration 011, strictly-later timestep); no-auto-migration doctrine
locked (web → banner, CLI/installer migrate); config-driven rate limits
(`RATE_*`); strict security headers + app-wide `BaselineHeadersMiddleware`
(nosniff everywhere); trusted-proxy ruling (`ClientIpResolver` + `TRUSTED_PROXIES`,
XFF ignored unless the peer is trusted). See HISTORY for detail.

## Mail delivery + retry
- In-request: DeliveryStage makes at most one send, then always returns success.
  Skipped ('received') when no mailer wired / MAIL_ENABLED falsy / SMTP_HOST
  empty. Retry sweep (`DeliveryService::retryDue`, shared by `mail:retry`, the
  dashboard/submissions retry buttons) re-attempts due 'failed' + never-attempted
  'received' rows; escalates backoff → 'dead' at MAIL_MAX_ATTEMPTS.
- Email-domain DNS check is BOUNDED (`BoundedDnsChecker` + `UdpDnsTransport`,
  ≤3 s total, FAIL-OPEN — only a definitive negative rejects).

## Design system (condensed — see HISTORY)
- Single colour source `public/assets/tokens.css` (`--osf-*`), DesignSystemTest
  forbids hardcoded colour outside it; values frozen. `.osf-field` is the
  label+control wrapper; `.osf-switch` now also styles a `:checked` checkbox
  (mail toggle). Versioned asset URLs via `OpenSendForm\Admin\asset()`.
- Nav tabs: Dashboard, Forms, Submissions, Email, Deliverability, Admins.

## Other subsystems — condensed; see HISTORY
- Admin deletion (hard + deactivate, three guards); form/submission deletion.
- Synthetic monitoring: `bin/osf monitor:run` (cron), state in `monitor_checks`,
  ok↔fail alerts, dashboard banner, auto-purge.
- Dev tooling: `composer serve` → `public/dev-router.php`; `bin/osf migrate`.
- Packaging: `bin/build-release.php` / `verify-release.php` + `bin/release_lib.php`
  → dist zip (mode policy stamped/verified); `src/Version.php` (0.1.0) sole
  version source.
- Auth stack: argon2id, TOTP/recovery (replay-protected), CSRF, hardened sessions.

## Known gaps / not built (by design)
- No AUTOMATIC migration trigger on upgrade (manual `bin/osf migrate` + banner).
- No `bin/osf admin:list` (reset-2fa takes a raw ID; first admin is #1).
- Password reset by email, roles/permissions, audit log; file uploads / redirect
  success URLs (out of embed scope).
- MySQL live-test, NativeSession `$_SESSION`, real SMTP, real UDP DNS socket, the
  monitor's real CurlHttpClient socket and osf.js DOM behaviour stay un-unit-tested
  (all bound behind seams the suite drives).
- Docs site (opensendform.com/guides/…) links are agreed permanent URLs but the
  site is not yet live.

## Open items
None blocking. QUESTIONS.md carries prior notes plus four non-blocking
onboarding-v2 decisions: checkbox-styled MAIL_ENABLED switch; fresh-install
SSL/TLS default via config-key absence; cron PHP path via PHP_BINARY + fallback;
which installer "Continue" button got the spacing fix.

## Planned increment sequence
0–9 (skeleton → schema → pipeline → SMTP → Turnstile → admin auth → design
system → installer → embed → packaging → synthetic monitoring) + hardening sweep
+ onboarding v2. ALL DONE.
