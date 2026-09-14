/**
 * The six contexts from BRIEF.md §4. Five brands and one internal.
 *
 * `colour` is the only colour in the interface that carries meaning (§12), so
 * these values are used for the context rule and nothing else. Each has at
 * least 4.5:1 contrast on white so it can also be used as label text.
 *
 * `pack` names a file in content/brand-packs/. The file's contents are never
 * copied into this repository's source — see lib/content.ts.
 *
 * `internal` marks Premises, which §4 records as internal Swytch Group work
 * rather than a brand. Its pack says the same thing at length.
 */
export const CONTEXTS = [
  { key: 'swytch-graphics', name: 'Swytch Graphics', colour: '#1c5fd6', pack: 'swytch-graphics.md', internal: false },
  { key: 'anchorprint', name: 'Anchorprint', colour: '#c4412a', pack: 'anchorprint.md', internal: false },
  { key: 'image-pro-systems', name: 'Image Pro Systems', colour: '#0e7c78', pack: 'image-pro-systems.md', internal: false },
  { key: 'sterling-pos', name: 'Sterling POS', colour: '#c2317a', pack: 'sterling-pos.md', internal: false },
  { key: 'eden-building-works', name: 'Eden Building Works', colour: '#2e7031', pack: 'eden-building-works.md', internal: false },
  { key: 'premises', name: 'Premises', colour: '#6b6b6b', pack: 'premises.md', internal: true },
] as const;

export type Context = (typeof CONTEXTS)[number];
export type ContextKey = Context['key'];

export function findContext(key: string | null | undefined): Context | undefined {
  return CONTEXTS.find((c) => c.key === key);
}

/** For the log, where a context key is stored as plain text. */
export function contextName(key: string): string {
  return findContext(key)?.name ?? key;
}

export function contextColour(key: string): string {
  return findContext(key)?.colour ?? '#6b6b6b';
}
