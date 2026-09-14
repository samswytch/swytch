import { Markdown } from '@/components/Markdown';
import { contextColour, contextName } from '@/lib/contexts';
import { listLogEntries, type LogEntry } from '@/lib/log';

/**
 * The log (BRIEF.md §9). One screen, readable start to finish, newest last.
 * Nothing on this page edits or deletes anything.
 *
 * Close-out notes are the third kind of entry and arrive with the day view in
 * phase 3.
 */

export const dynamic = 'force-dynamic';

const WHEN = new Intl.DateTimeFormat('en-GB', {
  timeZone: 'Europe/London',
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

function Entry({ entry }: { entry: LogEntry }) {
  return (
    <article className="log-entry">
      <div className="log-head">
        <span className="log-kind">{entry.kind === 'decision' ? 'Logged decision' : 'Parked item'}</span>
        <span className="log-context" style={{ ['--context-colour' as string]: contextColour(entry.context_key) }}>
          <span className="log-dot" aria-hidden="true" />
          {contextName(entry.context_key)}
        </span>
        <span className="meta">{WHEN.format(entry.created_at)}</span>
      </div>

      <div className="log-field">
        <p className="log-field-label">She asked</p>
        <p>{entry.question}</p>
      </div>

      <div className="log-field log-answer">
        <p className="log-field-label">The assistant said</p>
        <Markdown source={entry.answer} />
      </div>

      {entry.note ? (
        <div className="log-field">
          <p className="log-field-label">{entry.kind === 'decision' ? 'What she decided' : 'Her note'}</p>
          <p>{entry.note}</p>
        </div>
      ) : null}
    </article>
  );
}

export default async function LogPage() {
  let entries: LogEntry[];
  try {
    entries = await listLogEntries();
  } catch (error) {
    return (
      <main className="main main-wide">
        <h1 className="page-title">Log</h1>
        <p className="notice">{error instanceof Error ? error.message : 'The log could not be read.'}</p>
      </main>
    );
  }

  return (
    <main className="main main-wide">
      <h1 className="page-title">Log</h1>
      <p className="lede">
        Every logged decision and parked item, oldest first. Nothing here can be edited or removed.
        This is what Sam reads when he is back on Monday 26 October.
      </p>

      {entries.length === 0 ? (
        <p className="instruction">
          Nothing logged yet. Decisions the assistant marks for Sam, and anything parked until he is
          back, land here as you go.
        </p>
      ) : (
        <div style={{ marginTop: '1.5rem', borderTop: '1px solid var(--rule)' }}>
          {entries.map((entry) => (
            <Entry key={entry.id} entry={entry} />
          ))}
        </div>
      )}
    </main>
  );
}
