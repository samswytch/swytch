import type { ReactNode } from 'react';

/**
 * A small markdown renderer for the assistant's replies.
 *
 * It exists instead of a dependency because it only ever produces React
 * elements — there is no HTML string anywhere in it, so there is nothing to
 * sanitise and no way for model output to become markup. It covers what Claude
 * actually writes in a chat reply: paragraphs, headings, lists, quotes, fenced
 * code, and inline emphasis, code and links.
 */

type Block =
  | { kind: 'p'; lines: string[] }
  | { kind: 'h'; level: 2 | 3; text: string }
  | { kind: 'list'; ordered: boolean; items: string[] }
  | { kind: 'quote'; lines: string[] }
  | { kind: 'code'; lines: string[] };

const BULLET = /^\s{0,3}[-*+]\s+(.*)$/;
const NUMBERED = /^\s{0,3}\d{1,3}[.)]\s+(.*)$/;
const HEADING = /^\s{0,3}(#{1,6})\s+(.*)$/;
const QUOTE = /^\s{0,3}>\s?(.*)$/;
const FENCE = /^\s{0,3}```/;

function parseBlocks(source: string): Block[] {
  const blocks: Block[] = [];
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  let fenced: string[] | null = null;

  for (const line of lines) {
    if (FENCE.test(line)) {
      if (fenced) {
        blocks.push({ kind: 'code', lines: fenced });
        fenced = null;
      } else {
        fenced = [];
      }
      continue;
    }
    if (fenced) {
      fenced.push(line);
      continue;
    }

    if (line.trim() === '') {
      blocks.push({ kind: 'p', lines: [] });
      continue;
    }

    const heading = HEADING.exec(line);
    if (heading) {
      blocks.push({ kind: 'h', level: heading[1].length <= 2 ? 2 : 3, text: heading[2] });
      continue;
    }

    const quote = QUOTE.exec(line);
    if (quote) {
      const previous = blocks.at(-1);
      if (previous?.kind === 'quote') previous.lines.push(quote[1]);
      else blocks.push({ kind: 'quote', lines: [quote[1]] });
      continue;
    }

    const bullet = BULLET.exec(line);
    const numbered = NUMBERED.exec(line);
    if (bullet || numbered) {
      const ordered = Boolean(numbered);
      const text = (bullet ?? numbered)![1];
      const previous = blocks.at(-1);
      if (previous?.kind === 'list' && previous.ordered === ordered) previous.items.push(text);
      else blocks.push({ kind: 'list', ordered, items: [text] });
      continue;
    }

    const previous = blocks.at(-1);
    if (previous?.kind === 'p' && previous.lines.length > 0) previous.lines.push(line);
    else blocks.push({ kind: 'p', lines: [line] });
  }

  if (fenced) blocks.push({ kind: 'code', lines: fenced });
  return blocks.filter((block) => block.kind !== 'p' || block.lines.length > 0);
}

const INLINE = /(`[^`]+`)|(\*\*[\s\S]+?\*\*)|(\*[^*\n]+\*)|(_[^_\n]+_)|(\[[^\]\n]+\]\([^()\s]+\))/;

/** Anything that is not plainly a web or mail address is rendered as text, not a link. */
function safeHref(url: string): string | null {
  return /^(https?:\/\/|mailto:)/i.test(url) ? url : null;
}

function inline(text: string, keyPrefix: string): ReactNode[] {
  const nodes: ReactNode[] = [];
  let rest = text;
  let index = 0;

  while (rest.length > 0) {
    const match = INLINE.exec(rest);
    if (!match || match.index === undefined) {
      nodes.push(rest);
      break;
    }

    if (match.index > 0) nodes.push(rest.slice(0, match.index));
    const token = match[0];
    const key = `${keyPrefix}-${index++}`;

    if (token.startsWith('`')) {
      nodes.push(<code key={key}>{token.slice(1, -1)}</code>);
    } else if (token.startsWith('**')) {
      nodes.push(<strong key={key}>{inline(token.slice(2, -2), key)}</strong>);
    } else if (token.startsWith('[')) {
      const split = token.indexOf('](');
      const label = token.slice(1, split);
      const href = safeHref(token.slice(split + 2, -1));
      nodes.push(
        href ? (
          <a key={key} href={href} target="_blank" rel="noopener noreferrer">
            {label}
          </a>
        ) : (
          label
        ),
      );
    } else {
      nodes.push(<em key={key}>{inline(token.slice(1, -1), key)}</em>);
    }

    rest = rest.slice(match.index + token.length);
  }

  return nodes;
}

export function Markdown({ source }: { source: string }) {
  const blocks = parseBlocks(source);

  return (
    <div className="prose">
      {blocks.map((block, i) => {
        const key = `b${i}`;
        switch (block.kind) {
          case 'h':
            return block.level === 2 ? (
              <h2 key={key}>{inline(block.text, key)}</h2>
            ) : (
              <h3 key={key}>{inline(block.text, key)}</h3>
            );
          case 'list':
            return block.ordered ? (
              <ol key={key}>
                {block.items.map((item, j) => (
                  <li key={`${key}-${j}`}>{inline(item, `${key}-${j}`)}</li>
                ))}
              </ol>
            ) : (
              <ul key={key}>
                {block.items.map((item, j) => (
                  <li key={`${key}-${j}`}>{inline(item, `${key}-${j}`)}</li>
                ))}
              </ul>
            );
          case 'quote':
            return <blockquote key={key}>{inline(block.lines.join(' '), key)}</blockquote>;
          case 'code':
            return <pre key={key}>{block.lines.join('\n')}</pre>;
          default:
            return <p key={key}>{inline(block.lines.join(' '), key)}</p>;
        }
      })}
    </div>
  );
}
