import { query } from './db';

/**
 * The log (BRIEF.md §9). Append-only, one stream, newest last. This is what Sam
 * reads on his return.
 *
 * Entries are written by the server the moment the assistant classifies a reply
 * as logged or parked, so the record exists whether or not Sadie comes back to
 * annotate it. She can then add what she decided — once. `note` is set by a
 * conditional update that only fires while it is still null, so nothing in the
 * log is ever reworded or removed after the fact.
 *
 * Close-out notes are the third kind of entry in §9. They arrive with the day
 * view in phase 3; the `kind` constraint in db/schema.sql widens then.
 */

export type LogKind = 'decision' | 'parked';

export type LogEntry = {
  id: number;
  kind: LogKind;
  context_key: string;
  question: string;
  answer: string;
  note: string | null;
  created_at: Date;
  note_added_at: Date | null;
};

export async function insertLogEntry(entry: {
  kind: LogKind;
  contextKey: string;
  question: string;
  answer: string;
}): Promise<number> {
  const rows = await query<{ id: string }>(
    `insert into log_entries (kind, context_key, question, answer)
     values ($1, $2, $3, $4)
     returning id`,
    [entry.kind, entry.contextKey, entry.question, entry.answer],
  );
  return Number(rows[0].id);
}

/** Newest last, so the screen reads start to finish as the period went. */
export function listLogEntries(): Promise<LogEntry[]> {
  return query<LogEntry>(
    // node-postgres hands bigint back as a string, so the id is narrowed here
    // rather than leaving the declared type lying about what arrives.
    `select id::int as id, kind, context_key, question, answer, note, created_at, note_added_at
     from log_entries
     order by created_at asc, id asc`,
  );
}

export type NoteResult = 'saved' | 'already_noted' | 'not_found';

export async function addNote(id: number, note: string): Promise<NoteResult> {
  const updated = await query<{ id: string }>(
    `update log_entries set note = $1, note_added_at = now()
     where id = $2 and note is null
     returning id`,
    [note, id],
  );
  if (updated.length > 0) return 'saved';

  const exists = await query<{ id: string }>('select id from log_entries where id = $1', [id]);
  return exists.length > 0 ? 'already_noted' : 'not_found';
}
