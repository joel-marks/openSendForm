# OpenSendForm

[![CI](https://github.com/joel-marks/OpenSendForm/actions/workflows/ci.yml/badge.svg)](https://github.com/joel-marks/OpenSendForm/actions/workflows/ci.yml)

**Status: pre-alpha. Nothing here is usable yet. Do not install.**

OpenSendForm will be a free, open-source, self-hostable form-to-email
service for ordinary shared hosting. Install it once on any cPanel/PHP
host — upload a zip, run a browser installer — and any number of
websites (static sites, WordPress, anything) can embed a small HTML
snippet that posts form submissions to it. OpenSendForm validates the
submission, filters abuse programmatically, and relays it by
authenticated email to the site owner. No third-party form service, no
subscriptions, no Node or Docker on the server, and nothing for end
clients to maintain.

## Planned stack
PHP 8.1+ · Slim 4 · SQLite (MySQL optional) · PHPMailer · optional
Cloudflare Turnstile. Server-rendered admin panel with 2FA.

## Installing

OpenSendForm is designed to install on ordinary shared cPanel/PHP hosting with
no command line — just a file upload and a short browser wizard. Grab the
release zip (`opensendform-vX.Y.Z.zip`) — see **Releases & upgrading** below for
where to get it and how to upgrade later.

1. **Upload and extract the zip** into a folder on your host, and point a
   domain or subdomain at that folder's `public/` directory (its document
   root). Everything the app needs is inside the zip — you never run Composer.
2. **Visit your site in a browser.** Until it's set up, every page sends you to
   the installer at `/install`.
3. **Follow the steps:**
   - *Hosting check* — the installer confirms your PHP version, required
     extensions and that its folders are writable. Anything marked
     “Action needed” must be fixed before you can continue; “Heads up” items
     are optional.
   - *Database* — choose the **built-in database (SQLite)** unless your host
     told you to use MySQL. If you pick MySQL, enter the database details from
     cPanel and the installer tests the connection for you.
   - *Administrator* — create the account you'll sign in with (a name, email
     and a password of at least 12 characters).
   - *Finish* — the installer writes its settings and locks itself so it can't
     be run again by accident.
4. **Sign in** at `/admin/login` with the account you just created.

**Email comes after first login.** A fresh install stores submissions but does
not send email yet; you enable email delivery from the admin panel once you're
signed in (see *Setting up email* below). Until then, submissions are safely
saved and can be delivered once mail is set up.

**Re-running the installer** is deliberate: delete the file
`var/install.lock` with your host's file manager, then reload `/install`.

## Releases & upgrading

### The release zip

Each release is a single file, `opensendform-vX.Y.Z.zip`. It contains
everything a shared-hosting install needs — the application code, its PHP
dependencies already bundled in `vendor/`, the database migrations, `bin/`, the
`.htaccess` files and a short `INSTALL.txt`. **You never run Composer** and there
is no build step. Unzipping gives you one folder, `opensendform/`, whose
contents you deploy.

### Installing (cPanel walk-through)

1. **Create the site.** In cPanel, create the subdomain (or domain) you want the
   service to answer on — for example `forms.example.com`.
2. **Upload and extract** `opensendform-vX.Y.Z.zip` into that site's folder
   using the cPanel File Manager, then extract it there.
3. **Point the document root at `public/`.** In *Domains* (or *Subdomains*),
   set the site's document root to the `public/` directory inside the extracted
   `opensendform/` folder. This keeps everything else out of the web root.
   *(Host won't let you move the document root? See the fallback below.)*
4. **Visit the site** in a browser and follow the installer at `/install`
   (hosting check → database → administrator → finish), then sign in at
   `/admin/login`. Set up email delivery from the admin panel.

**Fallback layout for docroot-locked hosts.** If your host won't let you change
the document root, upload the whole `opensendform/` folder into the existing web
root instead and visit `.../public/`. The bundled `.htaccess` files are the
safety net for this case: every server-side folder (`src/`, `templates/`,
`migrations/`, `bin/`, `vendor/`, `var/`) ships a deny-all `.htaccess`, so on an
Apache host a browser still can't reach anything but `public/`. (Deny-all rules
are Apache-specific; the recommended `public/`-as-document-root layout does not
depend on them.)

### Upgrading

Upgrading is a file replace plus a migration:

1. **Download and extract** the new `opensendform-vX.Y.Z.zip`.
2. **Copy the new files over your existing installation, replacing everything
   *except* the `var/` folder.** Do not delete or overwrite `var/` — that is
   where your settings, database and install lock live.
3. **Apply any new database migrations** by running

   ```
   php bin/osf migrate
   ```

   or, if you don't have shell access, open the admin dashboard: when the
   schema is behind, a red *update required* banner appears and links to the
   same step. `bin/osf version` prints the app version, the current schema
   version and how many migrations are pending, so you can confirm before and
   after.

**What is preserved vs replaced.** Everything under `var/` survives an upgrade —
that is `var/config.php` (your settings), the SQLite database under `var/data/`,
and `var/install.lock`. Everything else (the application code, `vendor/`, the
migrations, `bin/`, the `.htaccess` files) is replaced by the new release. This
is why the upgrade step is safe: your data never lives in the files you
overwrite.

> Releases are attached to the project's GitHub releases page; automated
> publishing is planned. To build a zip yourself from a checkout, run
> `php bin/build-release.php` in the dev container (see *Development*).

## Development
This repo is developed AI-assisted (Claude Code in a devcontainer)
under human architectural direction. See CLAUDE.md, CONTEXT.md and
HISTORY.md for project state and process.

### Getting started
1. Open the repo in VS Code and **Reopen in Container** (Dev Containers
   extension). This provisions PHP 8.1, Composer, and a Mailpit sidecar.
2. Install dependencies:
   ```
   composer install
   ```
3. Run the test suite:
   ```
   composer test
   ```
4. Start the dev server (serves `public/` on port 8080):
   ```
   composer serve
   ```
   Then check `http://localhost:8080/health`.

Dev email is captured by **Mailpit** — open its web UI at
`http://localhost:8025`. No real SMTP server is configured in this
environment.

### End-to-end local test (submission → email)

Drive a real submission all the way to a captured email. The dev container
already points SMTP at Mailpit (`SMTP_HOST=mailpit`, `SMTP_PORT=1025`).

1. Create a form and note its `form_key`:
   ```
   php bin/osf form:create --name="Contact" \
     --recipient="owner@example.com" --origin="http://localhost:8080"
   ```
   Metadata is always stored, and submitted content is always held
   in-flight so the relayed email carries the submitted fields and a failed
   send can be retried. The form's `store_content` toggle only decides
   whether that content is *retained* after a successful delivery — off by
   default, it's cleared once the email is sent; on, it stays. Toggle it per
   form from the admin UI (Forms → edit → *Retain content after delivery*),
   or directly for a quick local test:
   ```
   sqlite3 var/data/opensendform.sqlite "UPDATE forms SET store_content = 1;"
   ```

2. Start the dev server:
   ```
   composer serve
   ```

3. Fetch a submit token (`Origin` must match the form's allowlist):
   ```
   curl "http://localhost:8080/v1/form/<form_key>/token" \
     -H "Origin: http://localhost:8080"
   ```

4. Post a submission with that token. Wait ~3 seconds after fetching it —
   submissions faster than `MIN_SUBMIT_SECONDS` are treated as bots and
   silently dropped:
   ```
   curl "http://localhost:8080/v1/form/<form_key>/submit" \
     -H "Origin: http://localhost:8080" \
     --data-urlencode "_osf_token=<token>" \
     --data-urlencode "name=Ada Lovelace" \
     --data-urlencode "email=ada@example.com" \
     --data-urlencode "message=Hello from a real submission"
   ```
   The response is `{"ok":true}`.

5. Open Mailpit at `http://localhost:8025` — the relayed email is waiting,
   `From: OpenSendForm`, with the submitter's address as `Reply-To`.

`php bin/osf submissions:list` shows the row move to status `sent`. If SMTP
is unreachable it is left `failed` with a scheduled retry; `php bin/osf
mail:retry` (wire it to cron on cPanel) re-sends everything due. A quick
transport check without a submission: `php bin/osf mail:test --to=you@example.com`.

## Synthetic monitoring (optional, recommended)

So you learn of breakage before your visitors do, `php bin/osf monitor:run`
sends a real *fake-but-marked* submission through the FULL public pipeline —
token fetch, min-time wait, POST, store, and SMTP delivery to the form's real
recipient (subject/body carry an unmistakable `[OpenSendForm monitor]` marker
so you can filter them). It checks a canary form every run and each other form
at most once a day, staggered. A check fails if any step errors or the
submission is not delivered within the send timeout; on an ok→fail (or
fail→ok) transition it emails one alert (to `MONITOR_ALERT_EMAIL`, or the first
admin), and the dashboard shows a red banner while any form is failing.
Synthetic submissions are excluded from the dashboard stats and the default
submissions list (see the "synthetic" filter) and auto-purged after a couple of
days. Wire it to cron on cPanel, hourly, alongside `mail:retry`:

```
0 * * * * /usr/local/bin/php /home/USER/opensendform/bin/osf monitor:run
```

`php bin/osf monitor:status` prints each form's last check, result and next due
time. The base URL, secret, retention, interval, send timeout and alert
recipient are all configurable via `MONITOR_*` settings (env or config file);
the secret is generated and written back automatically on first run.

## Cloudflare Turnstile (optional, per form)

[Turnstile](https://developers.cloudflare.com/turnstile/) is Cloudflare's
free, privacy-friendly CAPTCHA alternative. It is **optional and configured
per form** — there is no global switch. A form uses Turnstile only once it
has *both* a sitekey and a secret stored; otherwise the challenge is skipped.

1. In the Cloudflare dashboard (free, no paid plan needed) create a Turnstile
   widget and note its **Site Key** (public) and **Secret Key** (private).
2. Enable it for a form, or clear it again:
   ```
   php bin/osf form:turnstile <ID> --sitekey=<SITE_KEY> --secret=<SECRET_KEY>
   php bin/osf form:turnstile <ID> --disable
   ```
   Both keys are required to enable; `--disable` clears both. The secret is
   stored server-side and is **never** returned by any endpoint — the token
   endpoint advertises only the sitekey (`{"...","turnstile":{"sitekey":...}}`)
   so the embed JS can render the widget.

The client sends the widget's token back in the reserved field **`_osf_cf`**.
When Turnstile is enabled a missing token is rejected with `turnstile_required`
and a token Cloudflare rejects with `turnstile_failed`. The policy is
**fail-open**: if Cloudflare's verify API is unreachable, times out or replies
with malformed data the submission is allowed through (the honeypot, submit
token and rate limits still apply) — only a response that positively says the
token is invalid rejects it.

## Admin panel

The admin panel is served from `/admin` — server-rendered plain PHP with a
bespoke stylesheet built on the OpenSendForm design tokens (see *Theming*
below), no JavaScript framework and no build step. Every screen works with
JavaScript disabled; JS only adds progressive enhancements (theme toggle,
click-to-copy, QR code, segmented 2FA inputs). Navigation lives in a single
top header bar (no sidebar); on narrow screens the links simply wrap.

### Screens

- **Dashboard** (`/admin`) — at-a-glance counts (active forms, submissions
  today, failed/dead totals) and the ten most recent failed/dead submissions,
  each linking through to the filtered submissions view.
- **Forms** (`/admin/forms`) — list every form (public key with a copy
  button, recipient, active and Turnstile badges) and create/edit them:
  recipient, allowed origins (one per line), the *retain content after
  delivery* toggle, retention days, active state and the optional Turnstile
  key pair. Forms can be enabled/disabled from the list.
- **Submissions** (`/admin/submissions`) — a paginated (50/page), filterable
  table of delivery **metadata only** — id, form, created, status, attempts
  and the last error. Failed/dead rows can be retried individually, and
  *Retry all due now* runs the whole due queue. The fields a visitor typed
  are never displayed (that content is the in-flight delivery payload; see
  the storage note above).
- **Email** (`/admin/mail`) — the mail-setup wizard: SMTP settings, a test
  send, and an SPF/DKIM/DMARC deliverability checker (see *Setting up email*
  below). While sending is off, the dashboard shows a dismissible nudge here.
- **Account** (`/admin/account`) — your own account: change your display
  name, your email (requires your current password) and your password (current
  password plus a new one, at least 12 characters, twice; your session id is
  rotated on success). Reach it from your name in the top nav.
- **Admins** (`/admin/admins`) — the admin roster (email, name, 2FA and active
  badges, last login); add a new admin with an initial password; deactivate,
  reactivate, or permanently delete accounts.

### Admin model

OpenSendForm is **single-tenant**: an installation has one shared workspace and
every administrator is a co-operator of it. All admins see all forms and all
submissions — there are no roles, per-form permissions or ownership. This is by
design; it keeps a self-hosted, single-site tool simple.

- **The first admin** is created by the browser installer (see *Installing*
  above) or, for developers, from the CLI (`php bin/osf admin:create`, below).
  There is no public sign-up.
- **Adding admins** — any signed-in admin can create another from **Admins →
  Add an admin**, setting an initial password (≥ 12 characters). Share it over
  a secure channel and ask the new admin to change it from their **Account**
  screen after first sign-in.
- **Retiring admins** — the reversible option is to **deactivate** an account
  from the Admins screen. A deactivated admin can no longer sign in, and any
  live session they hold is invalidated on its next request. Reactivate them
  the same way. A safety guard prevents deactivating the **last remaining
  active admin** (including yourself), so an installation can never lock
  itself out of its own admin area.
- **Deleting admins** — an admin can also be **permanently deleted** from the
  same screen. Deletion is irreversible, so it is guarded more heavily than
  deactivation: an admin can never delete their own account; the **last
  remaining active admin** can never be deleted (deleting an *inactive* admin
  is always allowed, whatever the active count); and the confirmation step
  requires the acting admin to re-enter their own current password, states
  plainly that the action cannot be undone, and shows the target's email so
  there is no ambiguity about which account is being removed. All three
  guards are enforced server-side — the delete button is also hidden wherever
  a guard would refuse it, but a forged request cannot bypass the check.
  `bin/osf admin:delete ID` offers the same operation from the command line
  (still refuses the last active admin) without a password prompt, since
  shell access to the server is already a higher privilege than the admin
  panel itself.
- **Sensitive changes are re-authenticated** — changing an email or password,
  disabling 2FA, or deleting an admin always requires the current password
  (and, for disabling 2FA, a current authenticator code). Every action is a
  CSRF-protected POST with flash feedback.

### Theming

Colour is defined in one place: **`public/assets/tokens.css`** declares the
`--osf-*` design-token contract (surfaces, text, borders, accent, status,
focus, type, shape). Dark is the default (`:root`); the light palette lives
under `[data-theme="light"]`. Every other stylesheet — the bespoke
`public/assets/admin.css` — and every template consumes those tokens only; a
PHPUnit test forbids any hardcoded colour outside `tokens.css`.

The palette values are the GitHub **Primer primitives** (`@primer/primitives`,
MIT) vendored as resolved hex, each token annotated with the Primer source it
maps from. All colour pairs meet WCAG AA contrast. The `data-palette`
attribute on `<html>` is reserved for alternative palettes; only `github` is
implemented today. Because the token contract is shared verbatim with the docs
site, the app and **opensendform.com** render in one visual language — the docs
site loads the same `tokens.css`.

The theme toggle in the top nav cycles **Dark → Light → Auto** (Auto follows
`prefers-color-scheme`), persisted in `localStorage` under `osf-theme`. A tiny
blocking script (`public/assets/theme-init.js`, first in `<head>`) applies the
stored choice before first paint, so there is no flash of the wrong theme. Its
sun/moon/monitor icons are from the vendored Lucide subset (ISC) and inherit
`currentColor`, so they stay visible in every theme. Nothing is fetched from a
CDN at runtime, and a strict Content-Security-Policy on `/admin` keeps every
source self-hosted.

### Administrators & 2FA

Administrators are **provisioned both from the CLI and, once you have one
account, from the UI** — there is no public sign-up. Create the first admin
from the CLI (you are prompted for the password interactively; it is never
passed on the command line, and input is hidden when a terminal is
available; passwords must be at least 12 characters):

```
php bin/osf admin:create --email=you@example.com --name="Your Name"
```

Then browse to `/admin/login` (e.g. `http://localhost:8080/admin/login` with
the dev server) and sign in.

Two-factor authentication (TOTP) is optional and set up from the dashboard
after signing in (**Set up two-factor authentication**): scan the QR code
(rendered in-browser; the secret never leaves the page) or enter the key
manually into an authenticator app, confirm with the 6-digit code, then save
the one-time **recovery codes** shown once (copy or download them; each works
exactly once). With 2FA enabled, sign-in requires a current code (or a recovery
code if you lose your device). While 2FA is off, the dashboard shows a
per-session nudge to enable it (dismissible for the session).

From the TOTP screen (`/admin/totp/setup`) you can **regenerate** your recovery
codes (confirmed with a current code) or **disable** two-factor authentication
entirely — disabling requires your current password *and* a current code, and
clears the secret and all recovery codes.

Forms can equally be provisioned from the CLI — see `php bin/osf form:create`
and `php bin/osf form:turnstile` above — so scripted setup and the UI stay
interchangeable.

## Setting up email

Open **Email** in the admin nav (`/admin/mail`) to turn a fresh install from
"saving submissions" into "emailing them to you". The page has three parts and
you can come back to it any time.

1. **Sending account (SMTP).** Enter the host, port, encryption, username and
   password your mailbox or hosting provider gives you — the same details an
   email program uses. Each choice has a one-line explanation. The password is
   write-only: it is never shown back, and leaving it blank on a later save
   keeps the one already stored. Also set the **From** address and name your
   recipients will see, and the **Send emails for new submissions** switch.
   Settings are written to `var/config.php`; a server environment variable, if
   set, still overrides a saved value, and the page tells you when one does.
2. **Send a test email.** This sends one message through your *saved* settings
   (defaulting to your own address). The email arriving is the proof it works —
   if it does and sending is still off, one click turns it on.
3. **Deliverability (SPF, DKIM, DMARC).** These three DNS records are what stop
   your mail landing in spam: **SPF** lists who may send for your domain,
   **DKIM** signs each message so it can't be forged, and **DMARC** tells
   receivers what to do with anything that fails the first two. The page checks
   your From domain and, for anything missing, shows the exact record to add
   (with a copy button) and where to add it — your registrar's DNS manager or
   cPanel's *Zone Editor*. Use **Re-check** after publishing a record.

From the command line, `php bin/osf mail:status` prints the current mail
configuration (never any secret) and runs the same three DNS checks as live
lookups for the configured From domain.

## Embedding a form

Put a form on any website in two pastes. The admin **form-edit** screen shows
this snippet ready-filled with your form's key and this installation's URL
(**Embed code** panel, with a copy button).

**1 — a plain HTML form** (edit the fields to taste; keep the `<form>` wrapper
and its `data-osf-*` attributes):

```html
<form action="https://forms.example.com/v1/form/osf_YOURKEY/submit" method="post"
      data-osf-key="osf_YOURKEY" data-osf-url="https://forms.example.com">
  <label>Your email <input type="email" name="email" required></label>
  <label>Message <textarea name="message" required></textarea></label>
  <button type="submit">Send</button>
</form>
```

**2 — one script line** (anywhere on the page; `?v=` busts the long cache when
you upgrade):

```html
<script src="https://forms.example.com/embed/osf.js?v=0.0.1" defer></script>
```

That is the whole integration — no build step, no dependencies, no other
markup. Multiple forms on one page each initialise independently.

**No-JavaScript guarantee.** With the script absent, blocked or broken the form
still POSTs directly to the endpoint (its `action`) and the server answers with
a readable "Message sent" / error HTML page that links back — so a form is
never dead, but whether the message is actually relayed by email depends on a
per-form setting (the admin form-edit "Allow submissions without JavaScript"
checkbox, `forms.allow_nojs`):
- **Off (default).** A no-JS post carries no submit token (only the JS injects
  one), so it gets an honest error page — *"This form requires JavaScript to
  submit. Your message was not sent."* — and nothing is stored or delivered.
- **On.** A no-JS post is accepted and delivered like any other submission.
  This knowingly waives the token's min-time bot check for that subset of
  traffic; origin allowlist, rate limits, the honeypot (the snippet now embeds
  it as a hidden field so it protects no-JS posts too), and email validation
  still apply. A form with Turnstile enabled cannot be submitted without
  JavaScript either way, since Turnstile itself requires the script.

With the script running, submission goes over `fetch`: a submitting spinner, an
in-page "Message sent" confirmation and a "Send another" reset, inline
field-level errors (e.g. a bad email attaches to the email input), invisible
token refresh-and-retry on expiry, an injected honeypot, and — when the form has
Turnstile enabled — the Cloudflare widget above the submit button.

**Theming.** The script injects one namespaced `<style>` (every class is
`.osf-`-prefixed; no global styles are touched) driven by CSS variables you can
override on the page:

| Variable | Controls |
| --- | --- |
| `--osf-accent` / `--osf-on-accent` | button colour / its text |
| `--osf-error` | error text and field outline |
| `--osf-radius` | corner radius |
| `--osf-surface` / `--osf-overlay-bg` | dialog background / the success overlay |
| `--osf-border` | the submitted-panel border |

It respects `prefers-reduced-motion` and the success dialog is focus-trapped
(Esc or OK closes, focus returns to the form).

**Events.** The script dispatches CustomEvents on the `<form>` element for
integrators: `osf:submit`, `osf:success` (`detail.data` is the JSON response)
and `osf:error` (`detail.code`, `detail.message`). Add `data-osf-ui="none"` to
the form to suppress all built-in UI and drive your own from these events.

There is a manual test checklist for every state at `/embed/manual.html`
(dev only), documented at the top of `tests/embed-manual.html`.

## Public API (v1)

The public API is JSON-only and CORS-aware. All responses share one shape:

```jsonc
// success
{ "ok": true }
// failure
{ "ok": false, "error": { "code": "snake_case_code", "message": "..." } }
```

Error `code` values are stable and machine-readable; `message` is for
humans and may change. HTTP status is `200` on success, and `400` / `403`
/ `405` / `413` / `429` for the various failures.

### `GET /v1/form/{form_key}/token`
Issues a short-lived submit token for a form. The request's `Origin` must
be in the form's allowlist. Returns `{"ok":true,"token":"<ts>.<hmac>"}`.
Failures: `unknown_form` (unknown/inactive key), `origin_not_allowed`.
The embed JS calls this before rendering a form and refreshes it on
`token_expired`.

### `POST /v1/form/{form_key}/submit`
Accepts `application/x-www-form-urlencoded`, `multipart/form-data` or
`application/json`. The form key travels in the URL, not the body, so
CORS preflights can be resolved exactly per form. Reserved body fields:

- `_osf_token` — the token from the endpoint above
- `_osf_hp` — honeypot; must be left empty
- `_osf_cf` — Turnstile token; only required when the form has Turnstile
  enabled (see above)

Everything else is treated as user form data. If a field named `email` is
present its syntax and mail-capable DNS record are checked.

The request passes an ordered gauntlet: method/size → field hygiene →
form lookup → origin allowlist → per-IP then per-form rate limits →
honeypot → token → Turnstile (if enabled) → email validation → store.
Abuse checks that only a
bot would trip (filled honeypot; missing, forged or too-fast token) return
a normal `{"ok":true}` and silently discard the submission — bots get no
signal. A legitimate but **expired** token is the one honest timing
failure: it returns `400 token_expired` so the client can refresh and
retry. Other failures return specific codes: `invalid_fields`,
`unknown_form`, `origin_not_allowed`, `rate_limited`, `payload_too_large`,
`method_not_allowed`, `invalid_email`, `email_domain_invalid`,
`turnstile_required`, `turnstile_failed`.

The one exception to "bot checks fail silently" is the HTML-negotiated (no-JS)
path on a form with `allow_nojs` off: there a missing/invalid token returns an
honest `400 javascript_required` HTML page instead of the fake-success JSON
behaviour above, because a full-page navigation that silently claims success
would mislead a genuine no-JS submitter. See the no-JS guarantee above.

A stored submission is relayed by authenticated SMTP to the form's
recipient. One send is attempted in-request; a failure never changes the
`{"ok":true}` response — the submission is safely stored and retried by the
operator's `mail:retry` cron. `From` is always the configured service
address; the submitter's `email` field, when valid, becomes `Reply-To` and
nothing else the submitter typed can reach a mail header. With no SMTP host
configured the app runs storage-only and submissions stay at `received`.
`OPTIONS` on both routes returns a `204` CORS preflight.

The response is JSON by default and for any client that sends
`Accept: application/json` (the embed `fetch` does). A native browser form POST
— a top-level navigation that prefers `text/html` — instead receives a
self-contained HTML success/error page (the no-JS fallback); the JSON contract
above is unchanged for API and `fetch` callers.

## License
MIT — see LICENSE.