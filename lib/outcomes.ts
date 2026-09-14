/**
 * Every assistant reply ends with a machine-readable outcome line, which the
 * server strips before Sadie sees it and uses to decide what goes to the log.
 *
 * A marker on the end of the text was chosen over a tool call because it cannot
 * fail in a way that costs her the answer: if the model omits it, she still gets
 * a complete reply and nothing is logged. The app is deployed once and nobody is
 * on call, so failure modes that degrade quietly to "still useful" beat clever
 * ones that don't.
 */

export const OUTCOMES = ['go_ahead', 'go_ahead_logged', 'ask_kev', 'park'] as const;
export type Outcome = (typeof OUTCOMES)[number];

export const MARKER_PREFIX = '<<OUTCOME:';

const MARKER_GLOBAL = /<<OUTCOME:\s*(go_ahead_logged|go_ahead|ask_kev|park)\s*>>/g;

/**
 * Strip every marker from a finished reply and report the last outcome found.
 *
 * Falls back to `go_ahead` when the model omits the marker. Per the authority
 * envelope, "go ahead" is the common case and uncertainty resolves towards
 * acting, not towards parking, so an absent marker should not turn into a stop.
 */
export function extractOutcome(raw: string): { text: string; outcome: Outcome; markerFound: boolean } {
  const matches = [...raw.matchAll(MARKER_GLOBAL)];
  const outcome = (matches.at(-1)?.[1] ?? 'go_ahead') as Outcome;
  const text = raw.replace(MARKER_GLOBAL, '').trimEnd();
  return { text, outcome, markerFound: matches.length > 0 };
}

/**
 * Split a streaming buffer into the part that is safe to show now and the part
 * that might turn out to be the marker.
 *
 * Without this the marker flashes up in the chat panel for a moment before the
 * reply finishes.
 */
export function splitPending(buffer: string): { emit: string; hold: string } {
  // The marker has started but has not closed yet — hold everything from it.
  const started = buffer.lastIndexOf(MARKER_PREFIX);
  if (started >= 0) {
    return { emit: buffer.slice(0, started), hold: buffer.slice(started) };
  }
  // The buffer ends with something that could still grow into the marker.
  const longest = Math.min(buffer.length, MARKER_PREFIX.length - 1);
  for (let n = longest; n > 0; n--) {
    if (MARKER_PREFIX.startsWith(buffer.slice(buffer.length - n))) {
      return { emit: buffer.slice(0, buffer.length - n), hold: buffer.slice(buffer.length - n) };
    }
  }
  return { emit: buffer, hold: '' };
}

/** What the interface says under a reply. Deliberately plain, and no colour — §12. */
export const OUTCOME_LABELS: Record<Outcome, string> = {
  go_ahead: 'Your call. Nothing logged.',
  go_ahead_logged: 'Your call, and written to the log for Sam.',
  ask_kev: 'Ask Kev. He is in the office and can answer today.',
  park: 'Parked until Monday 26 October, and written to the log.',
};

/** Which outcomes produce a log entry, and of which kind (BRIEF.md §9). */
export function logKindFor(outcome: Outcome): 'decision' | 'parked' | null {
  if (outcome === 'go_ahead_logged') return 'decision';
  if (outcome === 'park') return 'parked';
  return null;
}
