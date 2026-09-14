'use client';

import { useState } from 'react';

/**
 * "What she decided" (BRIEF.md §9). The entry itself is already in the log —
 * the server wrote it the moment the assistant classified the reply — so this
 * fills in the one field that is hers, once. It cannot be edited afterwards.
 */
export function NoteForm({ logId, kind }: { logId: number; kind: 'decision' | 'parked' }) {
  const [note, setNote] = useState('');
  const [saved, setSaved] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (saved !== null) {
    return (
      <p className="note-saved">
        <span className="meta">{kind === 'decision' ? 'What you decided' : 'Your note'}: </span>
        {saved}
      </p>
    );
  }

  async function save() {
    const text = note.trim();
    if (text === '') return;
    setBusy(true);
    setError(null);
    try {
      const response = await fetch('/api/log/note', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: logId, note: text }),
      });
      if (!response.ok) {
        const payload = (await response.json().catch(() => null)) as { error?: string } | null;
        setError(payload?.error ?? 'That could not be saved. Try again.');
        return;
      }
      setSaved(text);
    } catch {
      setError('That could not be saved — you may be offline. Try again.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="note-form">
      <label className="note-label" htmlFor={`note-${logId}`}>
        {kind === 'decision' ? 'What you decided. Optional.' : 'Your note on this. Optional.'}
      </label>
      <textarea
        id={`note-${logId}`}
        rows={2}
        value={note}
        onChange={(event) => setNote(event.target.value)}
        placeholder={kind === 'decision' ? 'Ran it with the second headline.' : 'Drafted it; holding until the 26th.'}
      />
      <div className="composer-row">
        <button type="button" className="button" onClick={save} disabled={busy || note.trim() === ''}>
          {busy ? 'Saving…' : 'Add to log'}
        </button>
      </div>
      {error ? <p className="outcome-warning">{error}</p> : null}
    </div>
  );
}
