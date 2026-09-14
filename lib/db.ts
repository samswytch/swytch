import { Pool } from 'pg';

import { env } from './env';

/**
 * One pool per warm serverless instance. `max` is deliberately small: two users
 * for eleven days do not need connection headroom, and a small pool is one less
 * way to exhaust the database's connection limit if Vercel keeps several
 * instances warm.
 *
 * DATABASE_URL is expected to carry its own sslmode (Neon and Vercel Postgres
 * both include `?sslmode=require`), so TLS is left to the connection string
 * rather than second-guessed here.
 */

declare global {
  var __marketingCoverPool: Pool | undefined;
}

export function getPool(): Pool {
  if (!globalThis.__marketingCoverPool) {
    const pool = new Pool({
      connectionString: env.databaseUrl,
      max: 3,
      idleTimeoutMillis: 10_000,
      connectionTimeoutMillis: 10_000,
    });
    // Without a listener, a dropped idle connection becomes an unhandled error
    // event and takes the whole function down.
    pool.on('error', (err) => {
      console.error('Postgres idle client error:', err);
    });
    globalThis.__marketingCoverPool = pool;
  }
  return globalThis.__marketingCoverPool;
}

export class DatabaseError extends Error {}

/** Wraps a query so callers get one legible message instead of a driver error. */
export async function query<T extends Record<string, unknown>>(
  text: string,
  values: unknown[] = [],
): Promise<T[]> {
  try {
    const result = await getPool().query<T>(text, values);
    return result.rows;
  } catch (cause) {
    console.error('Database query failed:', cause);
    throw new DatabaseError(
      'The database could not be reached. If this keeps happening, work from Asana and the ' +
        'envelope document and carry on — do not spend the day trying to fix it.',
    );
  }
}
