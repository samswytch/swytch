import type Anthropic from '@anthropic-ai/sdk';

import {
  ALLOWED_IMAGE_TYPES,
  MAX_ATTACHMENT_BASE64,
  MAX_CONVERSATION_BASE64,
  MAX_TEXT_CHARS,
  PDF_TYPE,
  describeSize,
} from './limits';

/**
 * Validation for the conversation the browser posts.
 *
 * Images and PDFs are passed straight through to the Anthropic API as base64 in
 * the message content and stored nowhere — no blob storage, no database row, no
 * thumbnail (BRIEF.md §6). They exist for the length of the conversation and
 * are re-sent each turn because the API is stateless.
 */

export type ClientPart =
  | { type: 'text'; text: string }
  | { type: 'image'; media_type: string; data: string }
  | { type: 'document'; media_type: string; data: string; name?: string };

export interface ClientMessage {
  role: 'user' | 'assistant';
  content: ClientPart[];
}

export class ValidationError extends Error {
  /** 413 where something is genuinely too big, 400 where it is simply wrong. */
  readonly status: number;

  constructor(message: string, status = 400) {
    super(message);
    this.status = status;
  }
}

const BASE64 = /^[A-Za-z0-9+/]+={0,2}$/;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function parsePart(raw: unknown, budget: { used: number }): Anthropic.ContentBlockParam {
  if (!isRecord(raw)) throw new ValidationError('That message could not be read. Start a new question.');

  if (raw.type === 'text') {
    const text = typeof raw.text === 'string' ? raw.text : '';
    if (text.length > MAX_TEXT_CHARS) {
      throw new ValidationError(
        `That message is too long — ${text.length.toLocaleString('en-GB')} characters against a limit of ` +
          `${MAX_TEXT_CHARS.toLocaleString('en-GB')}. Send the part you want checked.`,
        413,
      );
    }
    return { type: 'text', text };
  }

  if (raw.type === 'image' || raw.type === 'document') {
    const mediaType = typeof raw.media_type === 'string' ? raw.media_type : '';
    const data = typeof raw.data === 'string' ? raw.data : '';

    if (raw.type === 'image' && !(ALLOWED_IMAGE_TYPES as readonly string[]).includes(mediaType)) {
      throw new ValidationError(
        `That image is in a format the assistant cannot read${mediaType ? ` (${mediaType})` : ''}. ` +
          `Attach a JPEG, PNG, GIF or WebP.`,
      );
    }
    if (raw.type === 'document' && mediaType !== PDF_TYPE) {
      throw new ValidationError('Only PDFs can be attached as documents. Screenshot it and attach the image instead.');
    }
    if (data === '' || !BASE64.test(data)) {
      throw new ValidationError('That attachment did not arrive intact. Attach it again.');
    }
    if (data.length > MAX_ATTACHMENT_BASE64) {
      throw new ValidationError(
        `That file is ${describeSize(data.length)}, which is over the ${describeSize(MAX_ATTACHMENT_BASE64)} limit ` +
          `for one attachment. Export it smaller, or send the page that matters.`,
        413,
      );
    }

    budget.used += data.length;
    if (budget.used > MAX_CONVERSATION_BASE64) {
      throw new ValidationError(
        `There are too many attachments in this conversation to send — the limit across all of them is about ` +
          `${describeSize(MAX_CONVERSATION_BASE64)}. Start a new question with just the file you want checked.`,
        413,
      );
    }

    if (raw.type === 'image') {
      return { type: 'image', source: { type: 'base64', media_type: mediaType as 'image/jpeg', data } };
    }
    return { type: 'document', source: { type: 'base64', media_type: 'application/pdf', data } };
  }

  throw new ValidationError('That attachment type is not supported. Attach an image or a PDF.');
}

export function parseConversation(raw: unknown): Anthropic.MessageParam[] {
  if (!Array.isArray(raw) || raw.length === 0) {
    throw new ValidationError('There is no question to answer. Type something and send it again.');
  }
  if (raw.length > 200) {
    throw new ValidationError('This conversation has run very long. Start a new question.');
  }

  const budget = { used: 0 };
  const messages = raw.map((message): Anthropic.MessageParam => {
    if (!isRecord(message) || (message.role !== 'user' && message.role !== 'assistant')) {
      throw new ValidationError('That conversation could not be read. Start a new question.');
    }
    if (!Array.isArray(message.content) || message.content.length === 0) {
      throw new ValidationError('That conversation could not be read. Start a new question.');
    }
    return { role: message.role, content: message.content.map((part) => parsePart(part, budget)) };
  });

  if (messages.at(-1)?.role !== 'user') {
    throw new ValidationError('There is no question to answer. Type something and send it again.');
  }
  return messages;
}

/** What goes in the log as "her question" — the words of the last thing she sent. */
export function lastUserText(messages: Anthropic.MessageParam[]): string {
  const last = messages.at(-1);
  if (!last || !Array.isArray(last.content)) return '';

  const text = last.content
    .filter((part): part is Anthropic.TextBlockParam => isRecord(part) && part.type === 'text')
    .map((part) => part.text)
    .join('\n')
    .trim();

  const attachments = last.content.filter(
    (part) => isRecord(part) && (part.type === 'image' || part.type === 'document'),
  ).length;

  if (attachments === 0) return text;
  const note = `[${attachments} attachment${attachments === 1 ? '' : 's'}, not stored]`;
  return text === '' ? note : `${text}\n${note}`;
}
