-- Marketing cover app — phase 1 schema, SQLite.
--
-- Two tables. The log is what Sam reads on his return; assistant_usage is what
-- stops a shared password in front of an API key running up a bill nobody is
-- watching.
--
-- Timestamps are stored as UTC text, 'YYYY-MM-DD HH:MM:SS'. The server runs on
-- UTC; everything shown to a person is converted to Europe/London in PHP, which
-- is what makes the 25 October clock change a non-event.
--
-- Safe to run more than once.

-- BRIEF.md §9. Append-only: nothing in here is ever edited or deleted. `note`
-- is the one field written after insert, and only while it is still null.
--
-- Close-out notes are the third kind of entry in §9 and arrive with the day
-- view in phase 3. The CHECK constraint widens to include 'closeout' then.
CREATE TABLE IF NOT EXISTS log_entries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    kind          TEXT NOT NULL CHECK (kind IN ('decision', 'parked')),
    context_key   TEXT NOT NULL,
    question      TEXT NOT NULL,
    answer        TEXT NOT NULL,
    note          TEXT,
    created_at    TEXT NOT NULL,
    note_added_at TEXT
);

CREATE INDEX IF NOT EXISTS log_entries_created_at_idx ON log_entries (created_at);

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
