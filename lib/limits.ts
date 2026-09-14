/**
 * Shared between the browser and the server so they agree on what will be
 * rejected. The browser checks so she gets told before a long upload; the
 * server checks because the browser is not trustworthy.
 *
 * The ceiling is a platform one: a Vercel serverless function accepts a request
 * body of about 4.5 MB, and the full conversation — attachments included — is
 * re-sent on every turn because the Anthropic API is stateless. So the budget
 * below is for the whole conversation, not for one message.
 */

/** Base64 characters, not bytes. Roughly 2 MB of file. */
export const MAX_ATTACHMENT_BASE64 = 2_700_000;

/** Base64 characters across every attachment still in the conversation. Roughly 2.7 MB of file. */
export const MAX_CONVERSATION_BASE64 = 3_600_000;

export const MAX_TEXT_CHARS = 20_000;

export const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as const;
export const PDF_TYPE = 'application/pdf';

/** Anthropic resizes anything larger than this anyway, so the browser does it first and sends less. */
export const IMAGE_MAX_EDGE = 1568;

export function approximateBytes(base64Length: number): number {
  return Math.floor((base64Length * 3) / 4);
}

export function describeSize(base64Length: number): string {
  const mb = approximateBytes(base64Length) / (1024 * 1024);
  return mb >= 1 ? `${mb.toFixed(1)} MB` : `${Math.round(approximateBytes(base64Length) / 1024)} KB`;
}
