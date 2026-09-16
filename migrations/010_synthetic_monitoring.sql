-- Migration 010: synthetic monitoring.
--
-- Two changes, both portable across sqlite and mysql (TEXT/INTEGER only):
--
--  1. submissions.is_synthetic — a submission produced by the synthetic
--     monitor (bin/osf monitor:run) rather than a real visitor. It is marked
--     at/after the store stage when the request carried the reserved
--     _osf_monitor field with the correct MONITOR_SECRET; a wrong or absent
--     secret leaves it 0, so the reserved field never bypasses any pipeline
--     stage. Synthetic rows are excluded from dashboard stats and the default
--     submissions list and are auto-purged by the monitor after a short
--     retention. Default 0 so every existing and non-synthetic row is a real
--     submission.
--
--  2. monitor_checks — one row per synthetic check the monitor performs, per
--     form. Tracks last-check time and last result so the monitor can compute
--     which forms are due, drive ok->fail / fail->ok alert transitions
--     (state lives here, not in memory) and render the dashboard failure
--     banner. ok is 1 (passed) or 0 (failed); detail carries the failure
--     reason (or a short success note). Portable types only.
ALTER TABLE submissions ADD COLUMN is_synthetic INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS monitor_checks (
    id         INTEGER PRIMARY KEY,
    form_id    INTEGER NOT NULL,
    checked_at TEXT NOT NULL,
    ok         INTEGER NOT NULL,
    detail     TEXT
);

CREATE INDEX IF NOT EXISTS idx_monitor_checks_form
    ON monitor_checks (form_id, id);
