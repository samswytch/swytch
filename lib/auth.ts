/**
 * Single shared password for two people for three weeks (BRIEF.md §11). No
 * accounts, no roles, no reset flow.
 *
 * This module is used by proxy.ts as well as by route handlers, so it sticks to
 * the Web Crypto globals rather than node:crypto — they are available in every
 * runtime Next.js will run this in.
 *
 * The cookie holds an expiry and an HMAC of that expiry keyed on the password.
 * Nothing derived from the password is reversible from the cookie, and changing
 * APP_PASSWORD invalidates every session already issued.
 */

export const COOKIE_NAME = 'mca_session';

/** Long enough to cover setup through Sam's return on 26 October without a re-login. */
const MAX_AGE_SECONDS = 60 * 60 * 24 * 90;

function toHex(buffer: ArrayBuffer): string {
  return Array.from(new Uint8Array(buffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

async function hmacHex(key: string, message: string): Promise<string> {
  const encoder = new TextEncoder();
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    encoder.encode(key),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  return toHex(await crypto.subtle.sign('HMAC', cryptoKey, encoder.encode(message)));
}

async function sha256Hex(value: string): Promise<string> {
  return toHex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value)));
}

/** Both arguments are fixed-length hex, so length never leaks anything here. */
function constantTimeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let difference = 0;
  for (let i = 0; i < a.length; i++) difference |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return difference === 0;
}

/** Compares digests rather than the passwords themselves, so the check is length-blind. */
export async function passwordMatches(submitted: string, actual: string): Promise<boolean> {
  const [a, b] = await Promise.all([sha256Hex(submitted), sha256Hex(actual)]);
  return constantTimeEqual(a, b);
}

export async function createToken(password: string): Promise<string> {
  const expiresAt = String(Math.floor(Date.now() / 1000) + MAX_AGE_SECONDS);
  return `${expiresAt}.${await hmacHex(password, expiresAt)}`;
}

export async function verifyToken(token: string | undefined, password: string): Promise<boolean> {
  if (!token) return false;
  const separator = token.indexOf('.');
  if (separator < 0) return false;

  const expiresAt = token.slice(0, separator);
  const signature = token.slice(separator + 1);
  if (!/^\d{1,15}$/.test(expiresAt)) return false;
  if (Number(expiresAt) * 1000 <= Date.now()) return false;

  return constantTimeEqual(signature, await hmacHex(password, expiresAt));
}

export const cookieOptions = {
  name: COOKIE_NAME,
  httpOnly: true,
  sameSite: 'lax',
  path: '/',
  maxAge: MAX_AGE_SECONDS,
  secure: process.env.NODE_ENV === 'production',
} as const;
