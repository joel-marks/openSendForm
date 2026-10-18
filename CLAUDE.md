# OpenSendForm — standing orders for Claude Code

## What this project is
OpenSendForm is a free, open-source, self-hostable form-to-email service
for shared cPanel/PHP hosting. Site owners install it once (zip upload +
browser installer); any website then posts form submissions to it via a
static HTML snippet + one vanilla JS file. Submissions are validated,
abuse-filtered programmatically, and relayed by authenticated SMTP to the
site owner.

## Hard constraints — never violate
- Production target: shared cPanel hosting. PHP 8.1 is the minimum
  version; write nothing that requires newer syntax or extensions not
  present on stock shared hosting.
- No Node, no Docker, no build step, no daemon in PRODUCTION. Node/Docker
  exist in this dev container only.
- Stack: Slim 4, Composer-managed, PHPMailer, SQLite default (MySQL
  optional via PDO). Server-rendered admin UI, plain PHP templates, no
  JS frameworks.
- All dependencies ship inside the release zip. End users never run
  Composer.
- Security is non-negotiable: prepared statements only, output escaping,
  CSRF tokens on all admin actions, no submitter-supplied content in any
  email header, argon2id for passwords.
- Dev email goes to Mailpit (host: mailpit, port: 1025). Never configure
  a real SMTP server in this environment.
- No em dashes in user-facing copy. User-facing strings (templates,
  installer, CLI output, flash/error messages, README/INSTALL.txt) never
  use the em dash (—); use a plain hyphen "-" instead, or restructure the
  sentence. Source-code comments and docblocks are exempt.

## Project state files — maintain every sprint
These files are read by the project architect between sprints. Keeping
them accurate is part of every task, not optional housekeeping.
- CONTEXT.md — current-state snapshot. Overwrite each sprint: what
  exists, what works, key decisions made, known gaps, open items.
  Keep it under ~150 lines; it is a snapshot, not a log.
- HISTORY.md — append-only sprint log. Add one entry per sprint:
  date, branch, tasks completed, test results, deviations from the
  task prompt and why. Never rewrite past entries.
- QUESTIONS.md — open questions for the architect (see below).

## Workflow rules
- NEVER commit to main. Work on the branch named in the task prompt; if
  none is named, create feature/<short-task-name>.
- NEVER push to main or force-push anything.
- Commit in small logical units with clear messages.
- Every increment must include tests (PHPUnit). Run the full test suite
  before considering any task complete; all tests must pass.
- Do not add a Composer dependency unless the task prompt authorises it.
  If you believe one is needed, record the case in QUESTIONS.md and
  implement without it or stop.
- Do not refactor code outside the scope of the current task.

## When blocked or uncertain
- Do not guess on architecture, security, or scope questions. Append the
  question to QUESTIONS.md (create it if absent), commit what is safely
  complete, and end the session with a summary of state and open items.

## Definition of done for any task
1. Acceptance criteria in the task prompt met.
2. Test suite green.
3. CONTEXT.md updated, HISTORY.md entry appended, any open questions
   in QUESTIONS.md.
4. Code committed to the correct branch.
5. SUMMARY: a short note in the final message covering what changed,
   test results, and anything logged to QUESTIONS.md.