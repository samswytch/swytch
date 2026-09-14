import { addNote } from '@/lib/log';

/**
 * The one field written after an entry is inserted, and only while it is still
 * empty. Nothing in the log is ever reworded or removed.
 */

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

export async function POST(request: Request) {
  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return Response.json({ error: 'That could not be saved. Try again.' }, { status: 400 });
  }

  const { id, note } = body as { id?: unknown; note?: unknown };
  const entryId = typeof id === 'number' && Number.isInteger(id) && id > 0 ? id : null;
  const text = typeof note === 'string' ? note.trim() : '';

  if (entryId === null) {
    return Response.json({ error: 'That could not be saved. Try again.' }, { status: 400 });
  }
  if (text === '') {
    return Response.json({ error: 'Write something first.' }, { status: 400 });
  }
  if (text.length > 5000) {
    return Response.json({ error: 'That note is too long. Keep it to the decision itself.' }, { status: 400 });
  }

  try {
    const result = await addNote(entryId, text);
    if (result === 'not_found') {
      return Response.json({ error: 'That log entry is no longer there.' }, { status: 404 });
    }
    if (result === 'already_noted') {
      return Response.json(
        { error: 'A note is already on that entry. The log cannot be edited once written.' },
        { status: 409 },
      );
    }
    return Response.json({ ok: true });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : 'That could not be saved. Try again.' },
      { status: 503 },
    );
  }
}
