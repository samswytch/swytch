-- Marketing cover app — schema, SQLite.
--
-- Three tables plus the sign-in throttle. The log is what Sam reads on his
-- return; cards are the work; assistant_usage is what stops a shared password
-- in front of an API key running up a bill nobody is watching.
--
-- Timestamps are stored as UTC text, 'YYYY-MM-DD HH:MM:SS'. Dates that belong
-- to a working day — a card's due_date, a close-out's entry_date — are stored
-- as plain 'YYYY-MM-DD' in London terms, because "Thursday" has to keep meaning
-- Thursday across the 25 October clock change.
--
-- Safe to run more than once. bin/setup-db.php also migrates a database made by
-- an earlier version.

-- BRIEF.md §9. Append-only: nothing in here is ever edited or deleted. `note`
-- is the one field written after insert, and only while it is still null.
--
-- Four kinds:
--   decision  — the assistant said go ahead, and logged it
--   parked    — waiting for Sam on 26 October
--   ask_kev   — routed to Kev in the office, same day
--   closeout  — the end of a working day: what moved, what did not
CREATE TABLE IF NOT EXISTS log_entries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    kind          TEXT NOT NULL CHECK (kind IN ('decision', 'parked', 'ask_kev', 'closeout')),
    context_key   TEXT,
    question      TEXT,
    answer        TEXT,
    note          TEXT,
    moved         TEXT,
    not_moved     TEXT,
    entry_date    TEXT,
    created_at    TEXT NOT NULL,
    note_added_at TEXT
);

CREATE INDEX IF NOT EXISTS log_entries_created_at_idx ON log_entries (created_at);

-- BRIEF.md §3. One card type, nothing beyond these fields, and no owner column —
-- every card is Sadie's. Field names stay close to §3 so the CSV exports
-- cleanly back into Asana.
CREATE TABLE IF NOT EXISTS cards (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT NOT NULL,
    context_key  TEXT NOT NULL,
    stream       TEXT NOT NULL CHECK (stream IN ('social', 'email', 'physical', 'premises')),
    due_date     TEXT NOT NULL,
    publish_time TEXT,
    channel      TEXT,
    status       TEXT NOT NULL DEFAULT 'not_started'
                 CHECK (status IN ('not_started', 'in_progress', 'done')),
    priority     TEXT NOT NULL DEFAULT 'normal'
                 CHECK (priority IN ('low', 'normal', 'high')),
    asana_url    TEXT,
    notes        TEXT,
    created_at   TEXT NOT NULL,
    completed_at TEXT
);

CREATE INDEX IF NOT EXISTS cards_due_date_idx ON cards (due_date);
CREATE INDEX IF NOT EXISTS cards_status_idx ON cards (status);
CREATE INDEX IF NOT EXISTS cards_context_idx ON cards (context_key);

-- One row per assistant request. Counted for the daily and per-conversation
-- caps, and incidentally a record of how much the assistant was actually used.
CREATE TABLE IF NOT EXISTS assistant_usage (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id TEXT NOT NULL,
    created_at      TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS assistant_usage_created_at_idx ON assistant_usage (created_at);
CREATE INDEX IF NOT EXISTS assistant_usage_conversation_idx ON assistant_usage (conversation_id);

-- Failed sign-in attempts, so a shared password on the open internet cannot be
-- ground down at machine speed. Rows older than the window are pruned on write.
CREATE TABLE IF NOT EXISTS login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS login_attempts_ip_idx ON login_attempts (ip, created_at);
