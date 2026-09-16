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
- They must remain editable as plain markdown. On this host that still means a deploy,
  because the files ship with the app — but it is a content edit, not a code change.
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
  calls go through PHP, which injects it.
- **Eleven working days, one user.** Do not build for scale, multi-tenancy, or future
  extension.

## Build order

Follow `BRIEF.md` §13. Phases 1 to 3 are built. Phase 4 — the Tuesday week view — is
genuinely optional and is not built.

## The stack

Plain **PHP 8.1** on LiteSpeed, **SQLite** through PDO, no framework, no Composer, no
build step. See `BRIEF.md` §11 for why, and `README.md` for how to run and deploy it.

Things that follow from it, and that are easy to break by habit:

- **Target PHP 8.1.** Local PHP may be newer. Nothing in `src/` uses anything above
  8.0 — no readonly classes, no enums, no property hooks. Keep it that way; the host's
  PHP version is account-wide and cannot be changed for this domain alone.
- **Take no hard dependency on `mbstring`, `intl`, `zip` or `fileinfo`.** `mbstring` is
  expected to be enabled but the code degrades without it. The other three are absent.
- **Attachments are base64 inside a JSON body, never uploads.** Nothing reaches disk.
  There is no MIME sniffing available and none is needed: the declared type is checked
  against a whitelist and then against the file's own leading bytes.
- **`config.php` lives outside the web root** and is never committed. Copy
  `config.example.php` and fill it in.
- **Timestamps are stored in UTC; working days are London.** The server runs UTC and
  the clocks change on 25 October, inside the cover period. Convert through
  `src/Clock.php` rather than assuming an offset.
- **One markdown renderer**, `src/Markdown.php`. It escapes every piece of model text
  before adding a tag. The log screen uses it and the assistant endpoint returns its
  output, so the browser never parses markdown. Do not add a second one.
- **Run it locally** with `php -S 127.0.0.1:8080 -t public dev-router.php` and
  `COVER_CONFIG` pointing at a config file. The built-in server has no `.htaccess`, so
  `dev-router.php` hands static files back to it.
