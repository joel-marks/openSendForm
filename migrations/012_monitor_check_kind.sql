-- Migration 012: distinguish the two kinds of monitor_checks row.
--
-- The dark-canary redesign retires per-form scheduled delivery checks. One
-- table now records two distinct state machines, so each row carries which one
-- it belongs to:
--
--   'canary' - the hourly dark canary heartbeat: a real, full-pipeline probe
--              against one active form whose message is delivered to the
--              service's own address and never to a human. One row per run.
--   'form'   - PASSIVE per-form delivery health, derived from REAL traffic. A
--              row is written only when a form's last real delivery outcome
--              TRANSITIONS (ok -> failed -> dead -> recovered), so the single-
--              alert machinery has somewhere to remember the last alerted
--              state. detail holds that state word ('ok' | 'failed' | 'dead').
--
-- Portable (TEXT only). Existing rows default to 'canary': historically every
-- row was a synthetic pipeline check, which the canary now is, so the Status
-- page's "last run" and stale-cron detection keep working across the upgrade.
ALTER TABLE monitor_checks ADD COLUMN kind TEXT NOT NULL DEFAULT 'canary';

CREATE INDEX IF NOT EXISTS idx_monitor_checks_kind
    ON monitor_checks (form_id, kind, id);
