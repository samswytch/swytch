# Project instructions

## Before writing any code

Read `BRIEF.md` in full. It is the specification for this project and it is short
enough to read completely. Do not start from the file tree or from assumptions about
what a task app usually contains.

## Scope is binding

`BRIEF.md` §1 contains a list headed **"Explicitly not built"**. That list is a
decision, not an oversight. Do not build those things, do not scaffold them, and do not
leave hooks, stub routes, or commented-out placeholders for them.

If a feature seems obviously missing, it is almost certainly on that list. Check before
suggesting it.

## The `content/` directory is data, not code

`content/authority-envelope.md` and `content/brand-packs/*.md` are loaded from disk at
runtime and assembled into the assistant's system prompt.

- Read them at request time. Do not paste their contents into source files, do not
  duplicate them into a database, and do not restructure them into JSON.
- They must remain editable as plain markdown without a redeploy.
- Do not rewrite their wording. They were written and signed off by the person this app
  is built for, and specific phrasings in them are deliberate.

The envelope is loaded on every assistant request. The brand pack for the selected
context is loaded alongside it.

## Ask, don't guess

Items marked **[decide]** in `BRIEF.md` are open questions. Ask about them rather than
picking an answer.

## Constraints that shape implementation choices

- **Deploy once.** Whatever is live on 6 October runs untouched until 26 October. There
  is nobody on call. Prefer boring, obvious implementations over clever ones, and
  prefer failing loudly and legibly over failing silently.
- **Two users, one shared password.** No accounts, no roles, no permissions.
- **The Anthropic API key is server-side only.** It must never reach the browser. All
  calls go through a server route that injects it.
- **Eleven working days, one user.** Do not build for scale, multi-tenancy, or future
  extension.

## Build order

Follow `BRIEF.md` §13. Phase 1 is the assistant — it ships first because it is the part
that has no substitute for the user, and it is deployable on its own.

## Environment

Copy `.env.example` to `.env.local` and fill it in. Confirm the current Anthropic model
identifier against https://docs.claude.com/en/api/overview rather than hardcoding one
from memory.
