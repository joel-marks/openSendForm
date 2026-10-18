# openSendForm — current state

Last updated: 2026-10-18 (fix/dark-canary-dmarc-skip, Claude Code)

## Status
The service is end-to-end and release-ready as **v0.2.0**. A versioned v1 API
drives an ordered validation/abuse pipeline; passing submissions are stored and
relayed by authenticated SMTP (in-request send + operator retry sweep). Full
admin panel (auth, 2FA, forms/submissions CRUD, Email + Deliverability + Status
tabs, an 8-step browser installer), a client-site embed with a no-JS fallback,
dev tooling, migrations, guarded deletion, release packaging, **zero-noise
monitoring + alerting**, and a defence-in-depth hardening layer are all built
and green. Suite: **689 tests**. CI runs tests + a package build/verify on
every PR/push.

## Product definition
Free, open-source, self-hostable **PHP form-to-email app for shared cPanel/PHP
hosting** - display name **openSendForm**, short form **osf-php**. One install
serves many websites via an embedded snippet + JS posting to a central endpoint.
Validated, abuse-filtered, relayed by authenticated SMTP to the site owner.

## Copy rule (standing)
No em dashes in user-facing copy (CLAUDE.md): templates, installer, CLI output,
flash/error messages and README/INSTALL.txt use a plain hyphen "-"; source-code
comments/docblocks are exempt. `tests/NoEmDashTest.php` locks it.

## Decisions locked
- PHP 8.1+ / Slim 4 / Composer / PHPMailer / SQLite default (MySQL optional via
  PDO). Server-rendered admin UI on a bespoke `--osf-*` token stylesheet; no JS
  framework, no build step, no daemon in production. All deps ship in the zip.
- All SQL portable across sqlite + mysql; timestamps stored UTC `Y-m-d H:i:s`.
  Form keys are PUBLIC identifiers, stored plain.
- Response contract (FROZEN): JSON only by default. Success `{"ok":true}`;
  failure `{"ok":false,"error":{"code","message"}}`. A text/html client gets HTML.
- Bot-facing checks fail SILENTLY (honeypot; missing/forged/too-young token ->
  fake success). Expired token -> honest `400 token_expired`. No-JS path with
  `allow_nojs=0` -> honest `400 javascript_required`.
- Config precedence: defaults < var/config.php < environment (env always wins).
- Branding (v0.2.0): display name "openSendForm" everywhere user-facing; short
  form "osf-php" for the release zip + package metadata. Page titles stay
  `osf - {page}`. The PHP namespace `OpenSendForm` and all code identifiers are
  EXEMPT (never renamed).

## Submission pipeline order (enforced + tested)
method/body size -> field hygiene -> form lookup by URL key -> origin allowlist
-> per-IP then per-form rate limits -> honeypot -> token -> Turnstile (optional)
-> email (bounded MX/A) -> store -> delivery (terminal, always succeeds). Locked
by SubmitPipelineOrderTest. Synthetic marking rides on the store stage only.

## Monitoring — zero-noise dark canary (this sprint)
**Doctrine: a HEALTHY system sends ZERO email, ever.** Any monitor-origin mail
means "act now". `bin/osf monitor:run` (hourly cron) does two things:
- **Dark canary.** One active form is exercised end to end against the REAL
  public endpoints (token -> min-time -> POST -> store -> SMTP). The probe is
  DARK: `DeliveryService` addresses a synthetic submission to the service's own
  `MAIL_FROM_ADDRESS` (never the form recipient), so a genuine SMTP transaction
  lands in the service mailbox and reaches no human. The mail layer is NOT
  faked. The synthetic row is flagged + auto-purged. One alert on the canary's
  ok<->fail transition.
- **Passive per-form observation.** Each run reads every active form's last REAL
  delivery outcome (`SubmissionRepository::latestRealDeliveryOutcomeForForm`) and
  alerts once on a transition: first entry into `failed`, once on `dead`, and a
  recovery notice when a later delivery succeeds. No synthetic traffic per form.
- State lives in `monitor_checks` (migration 012 adds a `kind` column: `canary`
  vs `form`; detail holds the passive state word). `MonitorRepository` is
  kind-aware. `failingForms()` (dashboard banner) reflects passive `form` state.
- Alerts (to `MONITOR_ALERT_EMAIL`, else first admin) are FAILURE / UNDELIVERABLE
  (dead) / RECOVERED; this is the ONLY monitor-origin email humans receive.
- `monitor:status` + Status tab show canary last run/result and per-form last
  real delivery outcome. No per-form scheduling, no "next due". Retired:
  `MONITOR_FORM_INTERVAL_HOURS`, `MONITOR_TIME_BUDGET_SECONDS`, `MonitorSchedule`.
- Config knobs: `MONITOR_SECRET` (auto-generated first run), `MONITOR_CANARY_FORM_ID`,
  `MONITOR_RETENTION_DAYS`, `MONITOR_SEND_TIMEOUT_SECONDS`, `MONITOR_ALERT_EMAIL`,
  `MONITOR_BASE_URL`.

## Deliverability (this sprint: DMARC fallback)
`DeliverabilityChecker` checks SPF/DKIM/DMARC via the injected `DnsResolver`.
DMARC now implements the RFC 7489 organizational-domain fallback: if
`_dmarc.<from-domain>` is absent it also looks up `_dmarc.<org domain>` and
reports present, labelling the name found (new `note` field in the shared
partial). Org domain is a pragmatic heuristic (NO vendored PSL): last two
labels, or three under a known two-part suffix (co.uk, com.au, ...); limits
noted in a comment on `organizationalDomain()`.

## Status tab
`Admin\StatusController` (/admin/status) -> testable `Admin\StatusReport`:
Environment re-probe (Requirements + SystemProbe + live DB row), Security (2FA +
world-writable probe), Mail (configured/sending + the shared deliverability
partial), Operations (versions, pending migrations, **canary last-run + stale-
cron detection, per-form last real delivery outcome**, cron setup).

## Installer / admin UI
8-step browser installer; one `.osf-appbar` header component; component-owned
vertical rhythm. **This sprint:** the top-right "Skip" on the Email-sending and
Scheduled-tasks steps is a real secondary button (same component as Continue;
primary stays accent-filled), wired to its form via the HTML `form=` attribute;
Bot-protection keeps its bottom-row "Skip and complete" exception.

## Mail delivery + retry
In-request DeliveryStage makes at most one send then always returns success
(skipped when no mailer / MAIL_ENABLED falsy / SMTP_HOST empty). Synthetic rows
are delivered dark (to `MAIL_FROM_ADDRESS`). Retry sweep (`DeliveryService::retryDue`)
re-attempts due failed + never-attempted received rows, escalating to 'dead' at
MAIL_MAX_ATTEMPTS. Email-domain DNS check bounded, fail-open.

## Design system
Single colour source `public/assets/tokens.css` (`--osf-*`); DesignSystemTest
forbids hardcoded colour outside it (named colours too - even in comments).
Nav tabs: Dashboard, Forms, Submissions, Email, Deliverability, Admins, Status.

## Packaging
`bin/build-release.php` / `verify-release.php`; `src/Version.php` (0.2.0). Zip's
single top-level folder is `osf-php/`; artefact `osf-php-vX.Y.Z.zip`. Modes
stamped (dirs 755, files 644, bin/osf 755) and verified. INSTALL.txt generated.

## Known gaps / not built (by design)
- No AUTOMATIC migration trigger on upgrade (manual `bin/osf migrate` + banner).
- Password reset by email, roles/permissions, audit log; file uploads / redirect
  success URLs (out of embed scope).
- DMARC fallback uses a heuristic org-domain, not a vendored PSL (limits noted).
- MySQL live-test, NativeSession, real SMTP/UDP DNS sockets, the monitor's real
  CurlHttpClient socket and osf.js DOM behaviour stay un-unit-tested (bound
  behind seams the suite drives). The three Firefox browser checks are non-CI.
- Docs site (opensendform.com/guides/...) links are agreed permanent URLs but
  the site is not yet live.

## Open items
None blocking. QUESTIONS.md carries prior notes plus five non-blocking
dark-canary/DMARC decisions (self-addressed dark mechanism, retired config keys,
passive signal definition, banner scope, DMARC heuristic limits).
