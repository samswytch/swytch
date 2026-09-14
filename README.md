# Marketing cover app

A small web app so one marketing assistant can run five brands' marketing on her own
for eleven working days, with no manager reachable.

Sam is away **Wed 7 – Sun 25 October 2026**, back Mon 26. Sadie covers **Wed 7 – Fri
23**, excluding Mondays. Trial week with Sam present starts **Tue 29 September**.

## What is built

**Phase 1 of `BRIEF.md` §13** — the assistant, the envelope, brand pack loading and the
log. It deploys on its own and is the part with no substitute: Sadie has no Claude login
of her own, so this app is her only route to it.

- **The assistant** (`§6`). Pick a context, ask, or paste a draft, or drop a proof. The
  system prompt is assembled at request time from the authority envelope plus that
  brand's pack. Every reply lands on one of four outcomes, stated plainly under it:
  your call · your call and logged · ask Kev · parked until 26 October.
- **Images and PDFs** (`§6`). Paste, drop, or photograph on a phone. Passed to the API as
  base64 and **stored nowhere** — no blob storage, no database row, no thumbnail. Images
  are resized in the browser before sending. They last as long as the conversation.
- **The log** (`§9`). Append-only, one screen, newest last. The server writes the entry
  the moment the assistant marks a reply logged or parked, so the record exists whether
  or not she annotates it; she can add what she decided, once. Nothing is ever edited or
  deleted.
- **CSV export** (`§10`). One button, in the header, on every screen.
- **Shared password** (`§11`), rate limits, and legible errors on every failure path.

Phases 2 to 4 — the data model, card form, list view, day view, ordering, close-out and
the Tuesday week view — are not built yet. Nothing has been scaffolded for them.

Two notes on the boundary:

- The **cards CSV** ships with phase 2, when there are cards. The log CSV is here now.
- **Close-out notes** are the third kind of log entry in §9 and arrive with the day view
  in phase 3. The `kind` constraint in `db/schema.sql` widens by one value then.

## Layout

```
BRIEF.md              the specification
CLAUDE.md             instructions for Claude Code
content/              the envelope and the six brand packs — data, not code
db/schema.sql         two tables; db/setup.mjs applies it
lib/                  content loading, prompt assembly, auth, database, caps
app/                  screens and API routes
proxy.ts              the shared password, in front of everything
```

`content/` is read from disk on every request and is never parsed, restructured or
copied into the database. Editing a pack is a markdown edit.

## Running it locally

```bash
cp .env.example .env.local     # then fill it in
npm install
npm run db:setup               # applies db/schema.sql
npm run dev
```

`npm run typecheck`, `npm run lint` and `npm run build` all need to pass before a deploy.

## Deploying

1. Create the Postgres database (Vercel Postgres or Neon). Use the **pooled** connection
   string, and make sure it carries `?sslmode=require`.
2. `DATABASE_URL=postgres://... npm run db:setup` against it, once.
3. Set all the variables from `.env.example` in the Vercel project.
4. Deploy, then open **`/api/health`**. It reports on the environment variables, the
   database tables, the envelope and all six packs. Everything must read `ok: true`
   before 6 October. It does not call the Anthropic API — check that by asking the
   assistant one question.

Editing a brand pack means committing the markdown and letting Vercel redeploy. That is
a content change, not a code change, but it is still a deploy — so do it before the 6th.

## The caps, and how to raise them

Set in the environment, so they change without touching code:

| Variable | Default | What it does |
|---|---|---|
| `ASSISTANT_DAILY_MESSAGE_CAP` | 200 | Messages per day, London time. Resets at midnight. |
| `ASSISTANT_SESSION_MESSAGE_CAP` | 50 | Messages per conversation. "New question" starts a fresh one. |
| `ASSISTANT_EFFORT` | `medium` | How hard the model works per reply. `low`–`max`. Higher is slower. |
| `ANTHROPIC_MODEL` | `claude-opus-5` | Confirmed against the Claude docs on 14 Sept 2026. |

Both caps say plainly what has happened and what to do instead. They exist because a
shared password sitting in front of an API key should not be able to run up a bill
nobody is watching — the hard monthly spend cap on the Anthropic account is the real
backstop, and it still needs setting.

If replies are being cut off mid-sentence, the deployment is on a plan with a
60-second function limit; lower `ASSISTANT_EFFORT` or move to a plan that allows longer.

## Before the trial week

- Read `content/authority-envelope.md` end to end. It is the document that decides
  whether the assistant is useful or hedges on everything.
- Read the six brand packs. Anything vague in a pack produces a vague check.
- Set up an Anthropic API key, add billing, set a hard monthly spend cap.
- Decide the Anchorprint email list question (see that pack).

## During the period

Nothing is deployed, changed, or fixed. If the app fails, Sadie works from Asana and
the envelope document and carries on.
