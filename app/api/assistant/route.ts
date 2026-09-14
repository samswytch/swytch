import Anthropic from '@anthropic-ai/sdk';

import { ContentError } from '@/lib/content';
import { DatabaseError } from '@/lib/db';
import { ConfigError, env } from '@/lib/env';
import { findContext } from '@/lib/contexts';
import { insertLogEntry } from '@/lib/log';
import { ValidationError, lastUserText, parseConversation } from '@/lib/messages';
import { extractOutcome, logKindFor, splitPending } from '@/lib/outcomes';
import { buildSystemPrompt } from '@/lib/prompt';
import { checkAndRecordUsage } from '@/lib/usage';

/**
 * The assistant (BRIEF.md §6). The Anthropic API key lives here and nowhere
 * else — it is never sent to the browser.
 *
 * The reply is streamed to the browser as newline-delimited JSON. Once the
 * stream has started, a failure is reported as an event inside it rather than
 * as an HTTP status, because the status line has already gone.
 */

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';
// Vercel clamps this to whatever the plan allows. A reply at medium effort
// normally lands well inside a minute; this is headroom, not an expectation.
export const maxDuration = 300;

const MAX_TOKENS = 16_000;

type Event =
  | { t: 'status'; v: string }
  | { t: 'answering' }
  | { t: 'text'; v: string }
  | { t: 'done'; text: string; outcome: string; logId: number | null; logError?: string }
  | { t: 'error'; v: string };

function fail(message: string, status: number): Response {
  return Response.json({ error: message }, { status });
}

/** Turns an SDK or runtime error into something Sadie can act on. */
function legibleError(error: unknown): string {
  const fallback =
    'If it keeps failing, work from Asana and the authority envelope document and carry on — ' +
    'do not spend the day trying to fix it.';

  if (error instanceof Anthropic.AuthenticationError || error instanceof Anthropic.PermissionDeniedError) {
    return `Claude is refusing this deployment's API key. That needs Sam or Kev to look at the Anthropic account — it is not something you can fix. ${fallback}`;
  }
  if (error instanceof Anthropic.RateLimitError) {
    return `Claude is rate limiting the account, or the monthly spend cap has been reached. Wait a couple of minutes and try again. ${fallback}`;
  }
  if (error instanceof Anthropic.APIConnectionError) {
    return `Could not reach Claude. Check you are online, then try again. ${fallback}`;
  }
  if (error instanceof Anthropic.APIError && typeof error.status === 'number' && error.status >= 500) {
    return `Claude is unavailable at the moment. Try again in a minute. ${fallback}`;
  }
  if (error instanceof Anthropic.APIError) {
    return `Claude rejected the request: ${error.message}. Try rephrasing, or send a smaller attachment. ${fallback}`;
  }
  if (error instanceof ContentError || error instanceof ConfigError || error instanceof DatabaseError) {
    return error.message;
  }
  console.error('Unexpected assistant failure:', error);
  return `Something went wrong answering that. Try again. ${fallback}`;
}

export async function POST(request: Request) {
  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return fail('That request could not be read. Reload the page and try again.', 400);
  }

  const payload = body as { conversationId?: unknown; contextKey?: unknown; messages?: unknown };

  const conversationId = typeof payload.conversationId === 'string' ? payload.conversationId.trim() : '';
  if (conversationId === '' || conversationId.length > 100) {
    return fail('That request could not be read. Reload the page and try again.', 400);
  }

  const context = findContext(typeof payload.contextKey === 'string' ? payload.contextKey : null);
  if (!context) {
    return fail('Pick which brand this is about before sending.', 400);
  }

  let messages;
  try {
    messages = parseConversation(payload.messages);
  } catch (error) {
    if (error instanceof ValidationError) return fail(error.message, error.status);
    throw error;
  }

  // Everything below this point costs money, so the caps are checked first.
  let system;
  try {
    const capped = await checkAndRecordUsage(conversationId);
    if (!capped.ok) return fail(capped.message, 429);
    system = await buildSystemPrompt(context);
  } catch (error) {
    return fail(legibleError(error), 503);
  }

  const client = new Anthropic({ apiKey: env.anthropicApiKey });

  const anthropicStream = client.messages.stream({
    model: env.anthropicModel,
    max_tokens: MAX_TOKENS,
    system,
    // Adaptive thinking, shown as a summary. Without the summary the panel sits
    // silent for the whole of a considered answer, which reads as a hang.
    thinking: { type: 'adaptive', display: 'summarized' },
    output_config: { effort: env.assistantEffort },
    messages,
  });

  const encoder = new TextEncoder();

  const stream = new ReadableStream<Uint8Array>({
    async start(controller) {
      const send = (event: Event) => controller.enqueue(encoder.encode(`${JSON.stringify(event)}\n`));

      let held = '';
      let raw = '';

      try {
        for await (const event of anthropicStream) {
          if (event.type === 'content_block_start' && event.content_block.type === 'text') {
            send({ t: 'answering' });
            continue;
          }
          if (event.type !== 'content_block_delta') continue;

          if (event.delta.type === 'thinking_delta') {
            send({ t: 'status', v: event.delta.thinking });
          } else if (event.delta.type === 'text_delta') {
            raw += event.delta.text;
            held += event.delta.text;
            const { emit, hold } = splitPending(held);
            held = hold;
            if (emit !== '') send({ t: 'text', v: emit });
          }
        }

        const final = await anthropicStream.finalMessage();

        if (final.stop_reason === 'refusal') {
          send({
            t: 'error',
            v:
              'Claude declined to answer that one. Rephrase it, or ask Kev if it is commercial or urgent. ' +
              'This is a safety refusal from Claude, not a problem with the app.',
          });
          return;
        }

        const { text, outcome } = extractOutcome(raw);

        if (text === '') {
          send({
            t: 'error',
            v:
              final.stop_reason === 'max_tokens'
                ? 'That answer was too long to finish. Ask about a smaller piece of it.'
                : 'Claude returned an empty answer. Try again.',
          });
          return;
        }

        let logId: number | null = null;
        let logError: string | undefined;
        const kind = logKindFor(outcome);

        if (kind) {
          try {
            logId = await insertLogEntry({
              kind,
              contextKey: context.key,
              question: lastUserText(messages),
              answer: text,
            });
          } catch (error) {
            // The answer is worth more than the log entry, so it is still
            // delivered — with a plain warning that the record did not stick.
            console.error('Could not write the log entry:', error);
            logError =
              'This could not be written to the log. Note it down for Sam yourself before you move on.';
          }
        }

        send({ t: 'done', text, outcome, logId, ...(logError ? { logError } : {}) });
      } catch (error) {
        send({ t: 'error', v: legibleError(error) });
      } finally {
        controller.close();
      }
    },
    cancel() {
      // She navigated away or hit stop. Stop paying for the rest of the reply.
      anthropicStream.abort();
    },
  });

  return new Response(stream, {
    headers: {
      'Content-Type': 'application/x-ndjson; charset=utf-8',
      'Cache-Control': 'no-store',
      'X-Accel-Buffering': 'no',
    },
  });
}
