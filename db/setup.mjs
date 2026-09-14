/**
 * Applies db/schema.sql. Run once per database:
 *
 *   npm run db:setup
 *
 * Reads .env.local if it is there, otherwise uses whatever is already in the
 * environment (which is how you would run it against the production database:
 * DATABASE_URL=... npm run db:setup).
 */
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

import pg from 'pg';

const envFile = path.join(process.cwd(), '.env.local');
try {
  process.loadEnvFile(envFile);
  console.log(`Read ${envFile}`);
} catch {
  console.log('No .env.local found — using the environment as it stands.');
}

const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  console.error(
    'DATABASE_URL is not set. Put it in .env.local, or pass it inline:\n' +
      '  DATABASE_URL=postgres://... npm run db:setup',
  );
  process.exit(1);
}

const schema = await readFile(path.join(process.cwd(), 'db', 'schema.sql'), 'utf8');
const client = new pg.Client({ connectionString });

try {
  await client.connect();
  await client.query(schema);
  const { rows } = await client.query(
    `select table_name from information_schema.tables
     where table_schema = 'public' and table_name in ('log_entries', 'assistant_usage')
     order by table_name`,
  );
  console.log(`Schema applied. Tables present: ${rows.map((r) => r.table_name).join(', ')}`);
} catch (error) {
  console.error('Could not apply the schema:');
  console.error(error.message);
  process.exit(1);
} finally {
  await client.end();
}
