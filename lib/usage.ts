import { query } from './db';
import { env } from './env';

/**
 * Caps on the assistant route (BRIEF.md §11).
 *
 * A shared password sitting in front of an API key deserves a ceiling, and
 * nobody is watching the bill during the cover period. Both caps are counted in
 * Postgres rather than in memory so they survive a cold start.
 *
 * The session cap is keyed on a conversation id the browser generates, so it is
 * a guard against a runaway conversation rather than against a determined
 * person. The daily cap is the one that actually bounds the spend.
 */

type Counts = { today: string; session: string };

export type CapResult = { ok: true } | { ok: false; message: string };

export async function checkAndRecordUsage(conversationId: string): Promise<CapResult> {
  const rows = await query<Counts>(
    `select
       count(*) filter (
         where created_at >= date_trunc('day', now() at time zone 'Europe/London') at time zone 'Europe/London'
       ) as today,
       count(*) filter (where conversation_id = $1) as session
     from assistant_usage
     where created_at > now() - interval '30 days'`,
    [conversationId],
  );

  const today = Number(rows[0]?.today ?? 0);
  const session = Number(rows[0]?.session ?? 0);

  if (today >= env.dailyMessageCap) {
    return {
      ok: false,
      message:
        `The assistant has used its ${env.dailyMessageCap} messages for today. It resets at midnight. ` +
        `Until then, work from the authority envelope document and Asana, and ask Kev for anything ` +
        `commercial or urgent.`,
    };
  }

  if (session >= env.sessionMessageCap) {
    return {
      ok: false,
      message:
        `This conversation has reached ${env.sessionMessageCap} messages. Start a new question to ` +
        `carry on — the limit is per conversation, not per day.`,
    };
  }

  await query('insert into assistant_usage (conversation_id) values ($1)', [conversationId]);
  return { ok: true };
}
