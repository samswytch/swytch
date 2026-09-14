'use client';

import { useCallback, useEffect, useRef, useState } from 'react';

import { AttachmentError, prepareFile, type Attachment } from '@/lib/attachments';
import { CONTEXTS, type Context } from '@/lib/contexts';
import { MAX_CONVERSATION_BASE64, describeSize } from '@/lib/limits';
import { OUTCOME_LABELS, type Outcome } from '@/lib/outcomes';
import { Markdown } from './Markdown';
import { NoteForm } from './NoteForm';

/**
 * The assistant panel (BRIEF.md §6).
 *
 * The conversation lives in this component and nowhere else. Attachments are
 * held in memory for as long as the conversation is open and are gone when she
 * starts a new question — there is no storage for them anywhere, by design.
 */

interface UserTurn {
  id: string;
  role: 'user';
  text: string;
  attachments: Attachment[];
}

interface AssistantTurn {
  id: string;
  role: 'assistant';
  text: string;
  outcome: Outcome;
  logId: number | null;
  logError?: string;
}

type Turn = UserTurn | AssistantTurn;

const ATTACHMENT_ONLY_QUESTION = 'Does this sit inside the pack?';

function toApiMessages(turns: Turn[]) {
  return turns.map((turn) =>
    turn.role === 'user'
      ? {
          role: 'user' as const,
          content: [
            ...turn.attachments.map((attachment) =>
              attachment.kind === 'image'
                ? { type: 'image' as const, media_type: attachment.mediaType, data: attachment.data }
                : { type: 'document' as const, media_type: attachment.mediaType, data: attachment.data },
            ),
            { type: 'text' as const, text: turn.text },
          ],
        }
      : { role: 'assistant' as const, content: [{ type: 'text' as const, text: turn.text }] },
  );
}

function attachedBytes(turns: Turn[], pending: Attachment[]): number {
  const fromTurns = turns
    .filter((turn): turn is UserTurn => turn.role === 'user')
    .flatMap((turn) => turn.attachments);
  return [...fromTurns, ...pending].reduce((total, attachment) => total + attachment.data.length, 0);
}

export function Assistant() {
  const [context, setContext] = useState<Context | null>(null);
  const [turns, setTurns] = useState<Turn[]>([]);
  const [draft, setDraft] = useState('');
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  const [status, setStatus] = useState<'idle' | 'working' | 'answering'>('idle');
  const [summary, setSummary] = useState('');
  const [streamed, setStreamed] = useState('');
  const [error, setError] = useState<string | null>(null);

  const abortRef = useRef<AbortController | null>(null);
  const bottomRef = useRef<HTMLDivElement | null>(null);
  const composerRef = useRef<HTMLTextAreaElement | null>(null);

  // Not rendered, and a random value generated during render would not survive
  // hydration, so it is created on first use in the browser.
  const conversationRef = useRef<string | null>(null);
  const conversationId = () => (conversationRef.current ??= crypto.randomUUID());

  // Keep the newest text in view, but only when she is already reading the
  // bottom — otherwise scrolling back to check something fights her for it.
  useEffect(() => {
    const nearBottom =
      window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 160;
    if (nearBottom) bottomRef.current?.scrollIntoView({ block: 'end' });
  }, [turns, streamed, status]);

  const addFiles = useCallback(async (files: FileList | File[]) => {
    const list = Array.from(files);
    if (list.length === 0) return;
    setError(null);
    for (const file of list) {
      try {
        const attachment = await prepareFile(file);
        setAttachments((current) => [...current, attachment]);
      } catch (failure) {
        setError(
          failure instanceof AttachmentError
            ? failure.message
            : 'That file could not be attached. Try a JPEG, PNG or PDF.',
        );
      }
    }
  }, []);

  function startNewQuestion(nextContext: Context | null = context) {
    abortRef.current?.abort();
    conversationRef.current = crypto.randomUUID();
    setTurns([]);
    setDraft('');
    setAttachments([]);
    setStreamed('');
    setSummary('');
    setStatus('idle');
    setError(null);
    setContext(nextContext);
  }

  async function send() {
    if (!context || status !== 'idle') return;

    const text = draft.trim() || (attachments.length > 0 ? ATTACHMENT_ONLY_QUESTION : '');
    if (text === '') return;

    if (attachedBytes(turns, attachments) > MAX_CONVERSATION_BASE64) {
      setError(
        `There are too many attachments in this conversation to send — the limit across all of them is ` +
          `about ${describeSize(MAX_CONVERSATION_BASE64)}. Start a new question with just the file you ` +
          `want checked.`,
      );
      return;
    }

    const previousTurns = turns;
    const sentDraft = draft;
    const sentAttachments = attachments;

    const userTurn: UserTurn = { id: crypto.randomUUID(), role: 'user', text, attachments };
    const nextTurns = [...turns, userTurn];

    setTurns(nextTurns);
    setDraft('');
    setAttachments([]);
    setStreamed('');
    setSummary('');
    setError(null);
    setStatus('working');

    const restore = (message: string) => {
      setTurns(previousTurns);
      setDraft(sentDraft);
      setAttachments(sentAttachments);
      setError(message);
    };

    const controller = new AbortController();
    abortRef.current = controller;

    try {
      const response = await fetch('/api/assistant', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        signal: controller.signal,
        body: JSON.stringify({
          conversationId: conversationId(),
          contextKey: context.key,
          messages: toApiMessages(nextTurns),
        }),
      });

      if (!response.ok || !response.body) {
        const payload = (await response.json().catch(() => null)) as { error?: string } | null;
        restore(payload?.error ?? 'The assistant could not be reached. Try again.');
        return;
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let running = '';
      let finished = false;

      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        let newline = buffer.indexOf('\n');
        while (newline >= 0) {
          const line = buffer.slice(0, newline).trim();
          buffer = buffer.slice(newline + 1);
          newline = buffer.indexOf('\n');
          if (line === '') continue;

          let event: Record<string, unknown>;
          try {
            event = JSON.parse(line) as Record<string, unknown>;
          } catch {
            continue;
          }

          if (event.t === 'status') {
            setSummary((current) => current + String(event.v ?? ''));
          } else if (event.t === 'answering') {
            setStatus('answering');
          } else if (event.t === 'text') {
            running += String(event.v ?? '');
            setStreamed(running);
          } else if (event.t === 'done') {
            finished = true;
            setTurns((current) => [
              ...current,
              {
                id: crypto.randomUUID(),
                role: 'assistant',
                text: String(event.text ?? ''),
                outcome: (event.outcome as Outcome) ?? 'go_ahead',
                logId: typeof event.logId === 'number' ? event.logId : null,
                ...(typeof event.logError === 'string' ? { logError: event.logError } : {}),
              },
            ]);
          } else if (event.t === 'error') {
            finished = true;
            restore(String(event.v ?? 'Something went wrong. Try again.'));
          }
        }
      }

      if (!finished) {
        restore(
          'The reply stopped before it finished. Try again. If it keeps happening, work from Asana and ' +
            'the authority envelope document and carry on.',
        );
      }
    } catch (failure) {
      if ((failure as Error)?.name === 'AbortError') setTurns(previousTurns);
      else restore('The assistant could not be reached — you may be offline. Try again.');
    } finally {
      abortRef.current = null;
      setStatus('idle');
      setStreamed('');
      setSummary('');
    }
  }

  if (!context) {
    return (
      <main className="main">
        <h1 className="page-title">Which brand is this about?</h1>
        <p className="lede">
          The assistant reads that brand&rsquo;s pack and the authority envelope before it answers.
        </p>
        <div className="context-list">
          {CONTEXTS.map((option) => (
            <button
              key={option.key}
              type="button"
              className="context-choice"
              style={{ ['--context-colour' as string]: option.colour }}
              onClick={() => startNewQuestion(option)}
            >
              <span className="swatch" aria-hidden="true" />
              <span className="context-choice-name">{option.name}</span>
              {option.internal ? <span className="context-choice-note">Internal</span> : null}
            </button>
          ))}
        </div>
      </main>
    );
  }

  const busy = status !== 'idle';

  return (
    <main
      className="main"
      onDragOver={(event) => event.preventDefault()}
      onDrop={(event) => {
        event.preventDefault();
        if (event.dataTransfer.files.length > 0) void addFiles(event.dataTransfer.files);
      }}
    >
      <div className="context-bar" style={{ ['--context-colour' as string]: context.colour }}>
        <span className="context-bar-name">{context.name}</span>
        <span className="context-bar-actions">
          <button type="button" className="linkbutton" onClick={() => startNewQuestion(context)}>
            New question
          </button>
          {' · '}
          <button type="button" className="linkbutton" onClick={() => startNewQuestion(null)}>
            Change brand
          </button>
        </span>
      </div>

      {turns.length === 0 && !busy ? (
        <p className="instruction">
          Paste a draft, drop a proof, or ask what you can decide on your own. Nothing you attach is
          stored.
        </p>
      ) : null}

      {turns.map((turn) =>
        turn.role === 'user' ? (
          <section className="turn" key={turn.id}>
            <p className="turn-label">You</p>
            <div className="turn-you">
              {turn.text.split('\n\n').map((paragraph, index) => (
                <p key={index}>{paragraph}</p>
              ))}
            </div>
            {turn.attachments.length > 0 ? (
              <p className="meta" style={{ marginTop: '0.5rem' }}>
                {turn.attachments.map((a) => a.name).join(', ')} — sent, not stored
              </p>
            ) : null}
          </section>
        ) : (
          <section className="turn" key={turn.id}>
            <p className="turn-label">Assistant</p>
            <Markdown source={turn.text} />
            <div className="outcome">
              <p className="outcome-label">{OUTCOME_LABELS[turn.outcome]}</p>
              {turn.logError ? <p className="outcome-warning">{turn.logError}</p> : null}
              {turn.logId !== null ? (
                <NoteForm logId={turn.logId} kind={turn.outcome === 'park' ? 'parked' : 'decision'} />
              ) : null}
            </div>
          </section>
        ),
      )}

      {busy ? (
        <section className="working">
          <p className="turn-label">Assistant</p>
          {streamed === '' ? (
            <>
              <p className="working-line">
                {status === 'working'
                  ? `Checking against the ${context.name} pack…`
                  : 'Writing the answer…'}
              </p>
              {summary === '' ? null : <p className="working-summary">{summary}</p>}
            </>
          ) : (
            <Markdown source={streamed} />
          )}
        </section>
      ) : null}

      {error ? <p className="notice">{error}</p> : null}

      <div className="composer">
        {attachments.length > 0 ? (
          <div className="attachments">
            {attachments.map((attachment) => (
              <span className="attachment" key={attachment.id}>
                {attachment.preview ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={attachment.preview} alt="" />
                ) : (
                  <span className="attachment-pdf" aria-hidden="true">
                    PDF
                  </span>
                )}
                <span className="attachment-name" title={attachment.name}>
                  {attachment.name}
                </span>
                <button
                  type="button"
                  className="linkbutton"
                  aria-label={`Remove ${attachment.name}`}
                  onClick={() =>
                    setAttachments((current) => current.filter((item) => item.id !== attachment.id))
                  }
                >
                  ×
                </button>
              </span>
            ))}
          </div>
        ) : null}

        <textarea
          ref={composerRef}
          rows={3}
          value={draft}
          disabled={busy}
          placeholder={`Ask about ${context.name}, or paste the draft.`}
          onChange={(event) => setDraft(event.target.value)}
          onPaste={(event) => {
            if (event.clipboardData.files.length > 0) {
              event.preventDefault();
              void addFiles(event.clipboardData.files);
            }
          }}
          onKeyDown={(event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
              event.preventDefault();
              void send();
            }
          }}
        />

        <div className="composer-row">
          <div className="composer-tools">
            <label className="button">
              Attach
              <input
                className="visually-hidden"
                type="file"
                accept="image/*,application/pdf"
                multiple
                disabled={busy}
                onChange={(event) => {
                  if (event.target.files) void addFiles(event.target.files);
                  event.target.value = '';
                }}
              />
            </label>
            <label className="button touch-only">
              Photo
              <input
                className="visually-hidden"
                type="file"
                accept="image/*"
                capture="environment"
                disabled={busy}
                onChange={(event) => {
                  if (event.target.files) void addFiles(event.target.files);
                  event.target.value = '';
                }}
              />
            </label>
          </div>

          {busy ? (
            <button type="button" className="button" onClick={() => abortRef.current?.abort()}>
              Stop
            </button>
          ) : null}

          <button
            type="button"
            className="button button-primary"
            style={{ marginLeft: 'auto' }}
            disabled={busy || (draft.trim() === '' && attachments.length === 0)}
            onClick={() => void send()}
          >
            Send
          </button>
        </div>
      </div>

      <div ref={bottomRef} />
    </main>
  );
}
