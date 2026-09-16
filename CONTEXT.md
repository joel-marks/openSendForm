# OpenSendForm — current state

Last updated: 2026-09-16 (feature/hardening-sweep, Claude Code)

## Status
The service is end-to-end and now through a final pre-deployment hardening
sweep. A versioned v1 API drives an ordered validation/abuse pipeline; passing
submissions are stored and relayed by authenticated SMTP (in-request send +
operator retry sweep). Full admin panel (auth, 2FA, forms/submissions CRUD,
mail-setup wizard, browser installer), a client-site embed with a no-JS
fallback, dev tooling, an explicit migration command, guarded deletion, release
packaging (v0.1.0 zip), synthetic monitoring + alerting, and a defence-in-depth
hardening layer are all built and green. Suite: **589 tests**. CI runs tests + a
package build/verify on every PR/push.

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
  in normal operation; 404/500 error pages now ALSO use this shape for API
  callers (see hardening). A text/html client (native browser POST) gets HTML.
- Bot-facing checks fail SILENTLY (honeypot; missing/forged/too-young token →
  fake success). Expired token → honest `400 token_expired`. No-JS path with
  `allow_nojs=0` → honest `400 javascript_required`.
- Config precedence: defaults < var/config.php < environment (env always wins).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist →
per-IP then per-form rate limits → honeypot → token → Turnstile (optional) →
email (bounded MX/A) → store → delivery (terminal, always succeeds). Locked by
SubmitPipelineOrderTest. Synthetic marking rides on the store stage only.

## Security hardening (this sprint — feature/hardening-sweep)
Defence in depth for a shared cPanel unix user co-hosting unrelated sites.
- **Installer session destruction.** On successful install commit the whole
  pre-install session (id + data) is destroyed before the done screen; a fresh
  session carries only the one-time done flag. Nothing (stray login, CSRF token,
  installer working state) can cross from the pre-install browser.
- **`bin/osf admin:reset-2fa ID`.** Clears TOTP secret + recovery codes and
  unenrols 2FA (via `disableTotp`, which also clears the replay marker); next
  login needs only the password. No password prompt (shell doctrine); refuses
  unknown IDs. Old recovery codes stop working. Paired with `admin:create` in
  the README "Locked out?" runbook.
- **Production error pages.** `src/Http/ErrorHandler.php` (registered on Slim's
  ErrorMiddleware) renders 404/500 with NO trace/class/path in production: frozen
  JSON contract for the API + `/v1`, minimal design-system HTML for browser/
  admin/installer. Dev stays verbose. `/favicon.ico` → 204 (kills 404 noise).
- **TOTP replay prevention (migration 011).** `admins.totp_last_timestep` records
  the accepted code's timestep; a code is accepted only for a STRICTLY LATER
  timestep, then advances the marker — one shared counter across login step-up
  and every sensitive-action re-auth (and enrolment). Recovery codes already
  single-use (verified). `AuthService::acceptTotpCode` is the single acceptance
  point; `Totp::matchedCounter` returns the matched counter.
- **No auto-migration doctrine LOCKED.** Web requests never migrate (dashboard
  banner only); CLI auto-migrates at boot; installer migrates. Verified reality
  matches and locked both directions in AutoMigrationDoctrineTest.
- **Rate limits config-driven.** Submit limits (`RATE_IP_PER_MINUTE`,
  `RATE_FORM_PER_HOUR`) plus the login limiter (`RATE_LOGIN_PER_IP/PER_EMAIL/
  WINDOW_SECONDS`, `RATE_TOTP_PER_ADMIN/PER_IP`) are env-overridable; defaults
  reproduce prior hard-coded values (no behavioural change). Documented in README.
- **Security headers.** Admin + installer carry strict CSP, X-Frame-Options
  DENY (+ frame-ancestors 'none'), X-Content-Type-Options nosniff, Referrer-Policy
  `strict-origin-when-cross-origin` (was no-referrer), Cache-Control no-store.
  New app-wide `BaselineHeadersMiddleware` adds nosniff to EVERY response incl.
  the public JSON API (which stays CSP/framing-free). No HSTS (README note).
- **Trusted-proxy ruling.** `src/Http/ClientIpResolver.php` + `TRUSTED_PROXIES`
  (CSV IPs/CIDRs, default empty). Client IP is REMOTE_ADDR and X-Forwarded-For is
  IGNORED unless the direct peer is a trusted proxy, then the rightmost XFF entry
  not in the list wins. Feeds the submit rate limiter, the stored `remote_ip`, and
  the admin login/2FA limiters. Spoofed XFF from an untrusted source never wins.

## Mail delivery + retry
- In-request: DeliveryStage makes at most one send, then always returns success.
  Skipped (row 'received') when no mailer wired / MAIL_ENABLED falsy / SMTP_HOST
  empty. Retry sweep (`DeliveryService::retryDue`, shared by `mail:retry`, the
  dashboard/submissions retry buttons) re-attempts due 'failed' rows and
  never-attempted 'received' rows; escalates backoff → 'dead' at MAIL_MAX_ATTEMPTS.
- Email-domain DNS check is BOUNDED (`BoundedDnsChecker` + `UdpDnsTransport`,
  ≤3 s total, FAIL-OPEN — only a definitive negative rejects).

## Design system (condensed — see HISTORY for full evolution)
- Single colour source `public/assets/tokens.css` (`--osf-*`), live-verified
  against github.com; DesignSystemTest forbids hardcoded colour outside it.
  Values frozen. Spacing `--osf-space-1..6`; `.osf-field` is the label+control
  wrapper; two-row header on one `--osf-bg-inset` surface; Dark/Light/Auto theme.
- Versioned asset URLs via `OpenSendForm\Admin\asset()` (`?v=Version::STRING`).
- Dashboard stat cards are whole-card links; tone chosen in PHP. Error pages
  reuse tokens.css + admin.css with an `.osf-error` block (new this sprint).

## Other subsystems — condensed; see HISTORY
- Admin deletion (hard + reversible deactivate, three guards); form/submission
  deletion (web GET-confirm→POST-CSRF + CLI `form:delete`/`submissions:purge`).
- Synthetic monitoring: `bin/osf monitor:run` (cron), real marked submissions
  through the full public pipeline, state in `monitor_checks`, ok↔fail alerts,
  dashboard failure banner, auto-purge. Network/sleep behind interfaces.
- Dev tooling: `composer serve` → `public/dev-router.php`; `bin/osf migrate`
  (idempotent) + red dashboard banner when schema is behind.
- Packaging: `bin/build-release.php`/`verify-release.php` → dist zip;
  `src/Version.php` (0.1.0) sole version source.
- Auth stack: argon2id, TOTP/recovery (now replay-protected), CSRF, hardened
  sessions (HttpOnly, SameSite=Strict, Secure on HTTPS, strict-mode).

## Known gaps / not built (by design)
- No AUTOMATIC migration trigger on upgrade (manual `bin/osf migrate` + banner) —
  now doctrine-locked.
- No `bin/osf admin:list` (reset-2fa takes a raw ID; first admin is #1).
- Password reset by email, roles/permissions, audit log; file uploads / redirect
  success URLs (out of embed scope).
- MySQL live-test, NativeSession `$_SESSION`, real SMTP, real UDP DNS socket, the
  monitor's real CurlHttpClient socket and osf.js DOM behaviour stay un-unit-tested
  (all bound behind seams the suite drives).

## Open items
None blocking. QUESTIONS.md carries prior notes plus three new non-blocking
hardening notes: (1) the TOTP replay counter is shared across login + re-auth (a
just-used code can't immediately re-auth); (2) `admin:reset-2fa` has no
`admin:list` companion; (3) the no-auto-migration doctrine already matched reality.

## Planned increment sequence
0–9 (skeleton → schema → pipeline → SMTP → Turnstile → admin auth → design
system → installer → embed → packaging → synthetic monitoring) + hardening sweep.
ALL DONE.
