# Marketing cover app

A small web app so one marketing assistant can run five brands' marketing on her own
for eleven working days, with no manager reachable.

Sam is away **Wed 7 – Sun 25 October 2026**, back Mon 26. Sadie covers **Wed 7 – Fri
23**, excluding Mondays. Trial week with Sam present starts **Tue 29 September**.

Runs on names.co.uk shared hosting: **PHP 8.1, SQLite, no Node, no framework.**
Deployed at **https://plan.swytch.graphics**.

## What is built

**Phases 1 to 3 of `BRIEF.md` §13.** Phase 4, the Tuesday week view, is genuinely
optional and is not built — without it Tuesday is a day that shows more items.

Four screens: **Today**, **All work**, **Assistant**, **Log**.

- **The assistant** (`§6`). Pick a context, ask, or paste a draft, or drop a proof. The
  system prompt is assembled at request time from the authority envelope plus that
  brand's pack. Every reply lands on one of four outcomes, stated plainly under it:
  your call · your call and logged · ask Kev · parked until 26 October.
- **Images and PDFs** (`§6`). Paste, drop, or photograph on a phone. Base64 inside the
  JSON request — never a file upload, so nothing lands on disk even briefly. Images are
  resized in the browser first. They last as long as the conversation.
- **The log** (`§9`). Append-only, one screen, newest last. The server writes the entry
  the moment the assistant marks a reply logged or parked, so the record exists whether
  or not she annotates it; she can add what she decided, once. Nothing is ever edited.
- **CSV export** (`§10`). One button, in the header, on every screen.
- **Shared password** (`§11`), message caps, a nightly backup, and a plain-English
  message on every failure path.

Phases 2 to 4 — the data model, card form, list view, day view, ordering, close-out and
the Tuesday week view — are not built. Nothing is scaffolded for them.

## Layout

```
BRIEF.md                 the specification
CLAUDE.md                instructions for Claude Code
content/                 the envelope and six brand packs — data, not code
config.example.php       copy to appdata/config.php on the server and fill in
db/schema.sql            four tables
src/                     the application; no autoloader, no Composer, no framework
  Plan.php               the day view's ordering rules (§5)
  Cards.php              the card model and its validation (§3)
  Migrate.php            schema upgrades that keep the log intact
  handlers/              the endpoints that do real work
  views/                 the screens
public/                  the only web-servable directory
bin/setup-db.php         applies the schema, and migrates an older database
bin/seed.php             three weeks of example cards, for checking the ordering
bin/backup.php           nightly SQLite snapshot, 14-day retention
deploy.sh                rsync over SSH
dev-router.php           local only; the built-in server has no .htaccess
```

On the server that becomes three directories, only one of which is web-servable:

```
/home/om44wfu4/
├── appdata/                     # outside the web root
│   ├── config.php               # API key, password hash, flags
│   ├── app.sqlite               # + -wal, -shm
│   ├── backups/                 # nightly, 14 days
│   └── app.log
├── coverapp/                    # src/, content/, bin/, db/ — readable, not servable
└── plan.swytch.graphics/        # docroot: index.php, .htaccess, assets/
```

The brand packs sit in `coverapp/`, not the docroot. They are internal strategy
documents and nothing should be able to fetch them over HTTP. They are read from disk on
every request and never parsed, cached or copied into the database.

## Running it locally

```bash
cp config.example.php /tmp/cover-config.php     # then fill it in
COVER_CONFIG=/tmp/cover-config.php php bin/setup-db.php
COVER_CONFIG=/tmp/cover-config.php php bin/seed.php --today   # optional example cards
COVER_CONFIG=/tmp/cover-config.php php -S 127.0.0.1:8080 -t public dev-router.php
```

`bin/seed.php --cover` loads examples across the real cover period instead, and
`--wipe` clears the cards first. Everything it creates goes through the same validation
as the form, so it cannot produce a card the form would reject. It is example content in
the right shape, not Sam's plan — replace it before the trial week.

Generate the password hash with:

```bash
php -r 'echo password_hash("the password here", PASSWORD_DEFAULT), "\n";'
```

`COVER_CONFIG` exists for this and for the tests. Nothing on the live host sets it; the
path is hard-coded to `/home/om44wfu4/appdata/config.php` so a stray environment
variable cannot repoint the app at someone else's configuration.

## Testing

```bash
COVER_CONFIG=/tmp/cover-config.php php tests/run.php      # 125 assertions
COVER_CONFIG=/tmp/cover-config.php bash tests/mutate.sh /tmp/cover-config.php
```

`tests/run.php` needs no server and builds its own throwaway database.
`tests/mutate.sh` breaks the source 43 ways and checks the suite notices each
one — a mutation that SURVIVES is a gap in the tests, not in the app.

## Deploying

**No shell? Read `DEPLOY.md`.** It is a step-by-step checklist for WePanel's file
manager, and `bash bin/build-release.sh` produces the two zips it asks for.

With SSH:

```bash
./deploy.sh            # dry run, shows what would change
./deploy.sh --live     # copy
```

Either way, the only thing that has to be put there by hand is
`/home/om44wfu4/appdata/config.php`.

**The database creates itself.** There is no shell on the target host, so the app
applies its own schema on the first request and upgrades an older one, tracked in
SQLite's `user_version`. `bin/setup-db.php` does the same thing from a command line
if you have one, and is safe to re-run.

Then open **`/health`** and check every row reads ok. It reports on the configuration,
the data directory, the database and its journal mode, the envelope, all six packs, the
PHP extensions and the timezone. It does not call the Claude API — confirm the key by
asking the assistant one question.

## Three things in the panel that are not code

These cannot be done from the repository and have to be done by hand, once, before
Sadie first signs in.

**1. Create the subdomain.** `plan.swytch.graphics` does not exist yet — unlike `sam.`
and `qr.`, which the hosting report confirmed already resolve to 185.2.6.8. Create it in
WePanel with its document root at `/home/om44wfu4/plan.swytch.graphics`, then check the
DNS record exists and points at **185.2.6.8**:

```bash
dig +short plan.swytch.graphics
```

Wait until that returns 185.2.6.8 from more than one network before doing step 2 —
Let's Encrypt validates over HTTP against the public DNS record, and issuing before it
has propagated just fails.

**2. Issue the certificate.** In SSL/TLS, issue a Let's Encrypt certificate for
`plan.swytch.graphics`. Let's Encrypt certificates last 90 days, which is longer than
the cover period but not by much, so **check the panel lists it as auto-renewing** — if
WePanel's AutoSSL is what issued it, renewal is automatic and there is nothing more to
do. If it was issued manually, set a reminder: an expired certificate mid-October is a
browser warning Sadie cannot get past and nobody is on call to fix it.

Verify with:

```bash
curl -sI https://plan.swytch.graphics/login | head -1
echo | openssl s_client -connect plan.swytch.graphics:443 -servername plan.swytch.graphics 2>/dev/null \
  | openssl x509 -noout -issuer -dates
```

**3. Then, and only then, turn the transport flags on.** In `appdata/config.php`:

```php
'cookie_secure' => true,
'force_https'   => true,
```

Both ship as `false` deliberately. Turning either on before the certificate exists locks
you out of your own app, with no way back in except SSH.

Also from the hosting report, and still outstanding: **enable `mbstring`** in PHP
Parameters → Extensions, and **set the cron notification address** so a failed nightly
backup reports itself. The app runs without mbstring — nothing depends on it — but a
text-heavy assistant handling pasted copy is better off with it on. `/health` says which
state it is in.

## Cron

One job. Times are UTC on this server.

```cron
17 2 * * * /usr/bin/php /home/om44wfu4/coverapp/bin/backup.php
```

That writes a timestamped snapshot into `appdata/backups/` and prunes anything older
than fourteen days. It uses SQLite's `VACUUM INTO` rather than copying the file, because
a plain copy taken mid-write can capture the database without its `-wal` companion and
produce a backup that will not open.

**The clocks go back on 25 October, inside the cover period.** This job runs at 03:17
BST and 02:17 GMT. For a backup that does not matter. It would matter for anything
user-facing, which is why nothing user-facing is on cron.

The hosting report also suggested a cron-driven daily-plan generator. That is **not
built, deliberately**: `BRIEF.md` §5 says the plan is generated on load — "the app
produces an ordered list for today" — which is a query when she opens the page, not a
scheduled job. A cron would add a failure mode the brief's design does not have, where
the plan silently never appears. It is also phase 3.

## The caps, and how to raise them

All in `appdata/config.php`, so they change without touching code:

| Setting | Default | What it does |
|---|---|---|
| `daily_message_cap` | 200 | Messages per day, London time. Resets at midnight. |
| `session_message_cap` | 50 | Messages per conversation. "New question" starts a fresh one. |
| `assistant_effort` | `medium` | How hard the model works per reply. `low`–`max`. Higher is slower. |
| `anthropic_model` | `claude-opus-5` | Confirmed against the Claude docs on 14 Sept 2026. |

Both caps say plainly what has happened and what to do instead. They exist because a
shared password sitting in front of an API key should not be able to run up a bill
nobody is watching — the hard monthly spend cap on the Anthropic account is the real
backstop, and it still needs setting.

There is also a sign-in throttle: ten wrong passwords from one address in fifteen
minutes and that address waits. It is not in the brief; it is there because this is a
single shared password on the open internet for three weeks.

**Replies are blocking, not streamed.** LiteSpeed buffers output, so a stream would be
something to debug from abroad with nobody on call. A reply takes twenty to forty
seconds and the screen says so while it waits. curl gives up at 90 seconds, against a
300-second `max_execution_time` and a measured 115-second request that returned intact.
If replies start timing out, lower `assistant_effort` before anything else.

## Before the trial week

- Read `content/authority-envelope.md` end to end. It is the document that decides
  whether the assistant is useful or hedges on everything.
- Read the six brand packs. Anything vague in a pack produces a vague check.
- Set up an Anthropic API key, add billing, set a hard monthly spend cap.
- Decide the Anchorprint email list question (see that pack).

## During the period

Nothing is deployed, changed, or fixed. If the app fails, Sadie works from Asana and
the envelope document and carries on.
