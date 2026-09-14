-- Marketing cover app — phase 1 schema.
--
-- Two tables. The log is what Sam reads on his return; assistant_usage is what
-- stops a shared password in front of an API key running up a bill nobody is
-- watching.
--
-- Safe to run more than once.

-- BRIEF.md §9. Append-only: nothing in here is ever edited or deleted. `note`
-- is the one field written after insert, and only while it is still null.
--
-- Close-out notes are the third kind of entry in §9 and arrive with the day
-- view in phase 3. The check constraint widens to include 'closeout' then.
create table if not exists log_entries (
  id            bigserial   primary key,
  kind          text        not null check (kind in ('decision', 'parked')),
  context_key   text        not null,
  question      text        not null,
  answer        text        not null,
  note          text,
  created_at    timestamptz not null default now(),
  note_added_at timestamptz
);

create index if not exists log_entries_created_at_idx on log_entries (created_at);

-- One row per assistant request. Counted for the daily and per-conversation
-- caps, and incidentally a record of how much the assistant was actually used.
create table if not exists assistant_usage (
  id              bigserial   primary key,
  conversation_id text        not null,
  created_at      timestamptz not null default now()
);

create index if not exists assistant_usage_created_at_idx on assistant_usage (created_at);
create index if not exists assistant_usage_conversation_idx on assistant_usage (conversation_id);
