import { CONTEXTS } from '@/lib/contexts';
import { readBrandPack, readEnvelope } from '@/lib/content';
import { query } from '@/lib/db';
import { env } from '@/lib/env';

/**
 * Deploy-once means there is one chance to notice that something is not wired
 * up. This reports whether the four things the assistant needs are actually
 * present: the environment variables, the database tables, the content files,
 * and a usable model identifier.
 *
 * It does not call the Anthropic API — that costs money and Sam can check the
 * key by asking the assistant one question.
 */

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

type Check = { name: string; ok: boolean; detail: string };

async function attempt(name: string, run: () => Promise<string>): Promise<Check> {
  try {
    return { name, ok: true, detail: await run() };
  } catch (error) {
    return { name, ok: false, detail: error instanceof Error ? error.message : String(error) };
  }
}

export async function GET() {
  const checks: Check[] = [];

  checks.push(
    await attempt('Environment variables', async () => {
      env.anthropicApiKey;
      env.appPassword;
      env.databaseUrl;
      return `Set. Model ${env.anthropicModel}, effort ${env.assistantEffort}, caps ${env.dailyMessageCap}/day and ${env.sessionMessageCap}/conversation.`;
    }),
  );

  checks.push(
    await attempt('Database', async () => {
      const rows = await query<{ table_name: string }>(
        `select table_name from information_schema.tables
         where table_schema = 'public' and table_name in ('log_entries', 'assistant_usage')
         order by table_name`,
      );
      const found = rows.map((r) => r.table_name);
      if (found.length < 2) {
        throw new Error(`Only found ${found.join(', ') || 'no tables'}. Run npm run db:setup against this database.`);
      }
      return `Reachable. Tables present: ${found.join(', ')}.`;
    }),
  );

  checks.push(
    await attempt('Authority envelope', async () => `Readable, ${(await readEnvelope()).length} characters.`),
  );

  checks.push(
    await attempt('Brand packs', async () => {
      const sizes = await Promise.all(
        CONTEXTS.map(async (context) => `${context.name} ${(await readBrandPack(context)).length}`),
      );
      return `All six readable. ${sizes.join(', ')} characters.`;
    }),
  );

  const ok = checks.every((check) => check.ok);
  return Response.json({ ok, checks }, { status: ok ? 200 : 503 });
}
