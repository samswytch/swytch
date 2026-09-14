import { contextName } from '@/lib/contexts';
import { csvFilename, csvResponse, toCsv } from '@/lib/csv';
import { listLogEntries } from '@/lib/log';

/**
 * CSV of the log (BRIEF.md §10). The cards export joins it in phase 2, when
 * there are cards.
 */

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

const ISO = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/London',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

function timestamp(value: Date | null): string {
  if (!value) return '';
  const parts = ISO.formatToParts(value);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')}`;
}

export async function GET() {
  try {
    const entries = await listLogEntries();
    const csv = toCsv(
      ['id', 'kind', 'context', 'logged_at', 'question', 'answer', 'her_note', 'note_added_at'],
      entries.map((entry) => [
        entry.id,
        entry.kind === 'decision' ? 'Logged decision' : 'Parked item',
        contextName(entry.context_key),
        timestamp(entry.created_at),
        entry.question,
        entry.answer,
        entry.note,
        timestamp(entry.note_added_at),
      ]),
    );
    return csvResponse(csv, csvFilename('marketing-cover-log'));
  } catch (error) {
    return new Response(
      error instanceof Error ? error.message : 'The log could not be exported. Try again.',
      { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } },
    );
  }
}
