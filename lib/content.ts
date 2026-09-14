import { promises as fs } from 'node:fs';
import path from 'node:path';

import type { Context } from './contexts';

/**
 * The authority envelope and the brand packs are data, not code.
 *
 * They are read from disk on every request. They are never parsed, restructured,
 * cached in a module variable, or copied into the database — editing the markdown
 * is the whole editing story. See CLAUDE.md, "The content/ directory is data,
 * not code".
 *
 * Next.js only ships files it can trace statically, so next.config.mjs includes
 * content/ in the function bundle explicitly.
 */

const CONTENT_DIR = path.join(process.cwd(), 'content');

export class ContentError extends Error {}

async function read(relativePath: string): Promise<string> {
  const absolute = path.join(CONTENT_DIR, relativePath);
  let text: string;
  try {
    text = await fs.readFile(absolute, 'utf8');
  } catch {
    throw new ContentError(
      `content/${relativePath} could not be read on this deployment. The assistant cannot answer ` +
        `without it. Check the file is committed and that next.config.mjs still includes content/ ` +
        `in outputFileTracingIncludes.`,
    );
  }
  if (text.trim() === '') {
    throw new ContentError(`content/${relativePath} is empty. The assistant cannot answer without it.`);
  }
  return text;
}

/** Loaded into the system prompt on every assistant request, whatever the context. */
export function readEnvelope(): Promise<string> {
  return read('authority-envelope.md');
}

/** Loaded alongside the envelope, for the context she selected. */
export function readBrandPack(context: Context): Promise<string> {
  return read(path.posix.join('brand-packs', context.pack));
}
