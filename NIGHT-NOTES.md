# Night notes — 15/16 September 2026

> **Follow-up session, 16 September.** Rule 2 changed to the reading you
> proposed; the test suite is now real and is itself tested; the database
> creates itself on the server; the assistant can be opened on a card; and
> `DEPLOY.md` plus `dist/` are the no-shell deployment. Details at the bottom,
> under "Follow-up session".

# Original notes

Overnight run. Phases 2 and 3 of `BRIEF.md` §13, the fourth log entry kind, and the
stack rewrite of `CLAUDE.md` and §11. Everything is local and committed; nothing was
deployed and nothing touched the server.

Four commits, each with the app working:

| | |
|---|---|
| `c24805c` | ask_kev and close-out as log entry kinds |
| `1a5e3d8` | Phase 2 — cards, the card form, the list view |
| `eb28b7b` | Phase 3 — the day view, the ordering, the close-out |
| this one | docs, health check, phone layout |

---

## What to review first

These are the judgement calls where I picked an answer rather than stopping. In rough
order of how much they matter.

### 1. Rule 2 is read literally, and that has a consequence

§5 says: "Anything overdue, or due within two days and not started."

I read that as **(overdue, any status but done) OR (due within two days AND
not_started)**. So a card that is `in_progress` and due tomorrow does **not** appear on
the day plan. The reasoning: she has already picked it up, so it is not something the
plan needs to tell her about.

The other reading — that "not started" qualifies both halves — would drop overdue
work she had started and never finished, which seemed worse.

**If you disagree, it is one clause in `src/Plan.php`.** Worth deciding during the
trial week with real content, which is exactly what §13 says that week is for.

"Within two days" means today, tomorrow and the day after.

### 2. Rule 3 contributes exactly one item

§5 says "the next open item on project work", singular, so the plan takes one — the
oldest open `physical` or `premises` card that is not already on the plan from rules 1
or 2.

### 3. The day view is now `/`, and the assistant moved to `/assistant`

§5 calls the day view "the screen the app opens on", so it took that slot. The
navigation has pointed at `/assistant` since the phase 2 commit, so no link moved when
the day view landed. This does touch phase 1 routing — see the list at the bottom.

### 4. Mondays

The card form refuses a Monday due date, as §5 requires. §5 does not say what the day
view does *on* a Monday. It shows a line saying Mondays are not Swytch marketing days,
and then still shows the plan — which on a Monday will be whatever is overdue from the
week before. Blocking the screen entirely seemed wrong: if she is in and something is
overdue, she should be able to see it.

### 5. "What moved" counts anything finished today

Not just the six things on the plan. If she picks something up herself, it should
still show in her own close-out. What did *not* move is the plan items still open.

Both lists are worked out from the database when she presses the button, not taken
from the form.

### 6. One close-out per day

Nothing in the log is ever edited, so rather than letting a second press append a
duplicate, the day view shows the recorded close-out instead of the form once a day
has one. Pressing again does nothing.

### 7. ask_kev is logged; "go ahead" still is not

You asked for the fourth kind. The assistant now writes an entry when it routes
something to Kev, and the field for her own words is headed **"What Kev said"** — the
useful thing to capture once she has actually asked him.

Tier 1 "go ahead" deliberately stays unlogged. The envelope is explicit that a log of
every post is noise and will not get read.

### 8. The §12 empty state was unreachable, so it moved

§12 gives "Nothing publishes today. Pick up the sample pack reprint." as the model
empty state. Rule 3 means the plan is never empty while open project work exists — so
the branch that would have printed that could never run. It is gone. A day whose plan
contains nothing but the rule 3 item now reads:

> Nothing publishes today and nothing is due. Pick up the project work below.

with the item named directly underneath. Same sentence, delivered by the plan rather
than by an empty state.

### 9. Close-out columns rather than reused fields

`log_entries` gained `moved`, `not_moved` and `entry_date` instead of bending a
close-out into the question/answer fields. Both the log screen and the CSV read
correctly as a result. `context_key` is now nullable, since a close-out has no brand.

### 10. Seed data is examples, not your plan

`bin/seed.php` exists because §11 asks for it and because the ordering needs real
dates to judge. What it creates is plausible-shaped filler with "Seed data. Replace
with the real card before the trial week." in the notes. **Replace it.**

---

## Two real bugs I found and fixed

Both were caught by driving the app in a browser rather than by reading the code, which
is worth knowing when deciding how much to trust the rest.

**Inline status change silently did nothing.** The script disabled the `<select>` before
submitting, and a disabled control is left out of the form data — so the POST carried no
status, the server rejected it, and the page reloaded looking unchanged. Fixed, and both
the JavaScript and no-JavaScript paths are now tested.

**Rule 3 could contribute nothing on a quiet day.** It took the oldest open project item
with `LIMIT 1` in SQL. If rule 2 had already taken that same card, rule 3 had nothing
left to offer and the plan came up a card short. It now scans the ordered list and takes
the first one the plan has not already used.

---

## What I could not do

- **Nothing is deployed.** No SSH from this container, as before. The subdomain and the
  Let's Encrypt certificate are still the three panel tasks in `README.md`, untouched.
- **I could not test on PHP 8.1.** Local PHP is 8.4. Instead I kept everything to 8.0
  syntax and scanned for 8.2+ constructs — there are none. Worth one smoke test on the
  real host before the trial week.
- **I could not test with `mbstring` genuinely absent** — only with the `mb_*` functions
  disabled, which exercises the same fallback. The health check reports which state the
  host is in.
- **The assistant is exercised against a stand-in API**, not the real one. There is no
  key in this environment. Every code path around it is tested; the model's actual
  judgement is not.

---

## For the next session

1. **Deploy, and do the three panel tasks** — subdomain, certificate, then flip
   `cookie_secure` and `force_https`. `README.md` has the order and the checks.
2. **The `[decide]` items are still open**: whether Sadie drops to four days and which
   day (§2), the premises assumption (§4), and the Anchorprint email list question.
   None of them changed anything I built, but the four-day answer affects the six-item
   cap and the content load.
3. **The assistant cannot see a card yet.** §6 says its system prompt is assembled from
   "the brand pack for that context, the authority envelope, and the card if there is
   one". The card part is not built — there were no cards when phase 1 shipped, and
   adding it now would have changed tested phase 1 behaviour, which was out of bounds
   for this run. It is a small, additive change: a fourth system block and a `?card=`
   parameter. A card screen currently links to the assistant with the *brand*
   preselected, which is the useful half of it.
4. **Phase 4, the Tuesday week view**, is not built and was not asked for. §13 calls it
   genuinely optional.
5. **§10 still says the cards CSV ships in phase 1.** It could not — there were no
   cards. It ships in phase 2 and is in the header on every screen. I have left §10's
   wording alone; only §11 was mine to rewrite.

---

## Phase 1 files I touched, and why

You asked me not to disturb working phase 1 code beyond the log change. These were
unavoidable to integrate the new phases. All of phase 1 was re-run afterwards — auth,
all four outcomes, marker stripping, script-tag escaping, refusal, truncation, 401, 429,
500, attachment validation, both caps — and passes.

| File | Change |
|---|---|
| `db/schema.sql` | cards table; four log kinds; close-out columns |
| `src/Outcomes.php` | `ask_kev` now returns a log kind |
| `src/LogBook.php` | four kinds, close-out writes, wider CSV |
| `src/views/log.php` | renders four kinds; log export button |
| `src/handlers/assistant.php` | sends the note wording for the kind |
| `public/assets/app.js` | uses that wording; `?context=` preselect |
| `public/index.php` | routes for cards, the day view, the close-out |
| `src/views/layout.php` | navigation for the new screens |
| `src/handlers/health.php` | checks the cards table and the migration |
| `public/assets/app.css` | styles for the new screens; phone layout |

`content/` is byte-identical to the original upload. In `BRIEF.md`, only §11 changed.


---

# Follow-up session — 16 September 2026

Four things asked for, plus a noindex header and the deployment package.

## 1. Rule 2

Changed to `(overdue OR due within two days) AND status != done`. You were
right: my reading hid a card she had marked in progress yesterday and was due
today with no publish time — it would only have surfaced once it went late.

The SQL simplifies to `due_date <= $soon AND status != 'done'`, because overdue
is any date before today and today is never after `$soon`. The reason line now
names the status, since rule 2 admits work already under way and "due Friday"
alone would not say whether she had started it: **"due today, in progress"**,
**"overdue, was due Tuesday, in progress"**.

Re-ran the fixture. The previously hidden card now appears; done work, work due
beyond the window, and duplicates across rules are all still excluded.

## 2. Testing the tests

There is now a real suite — `tests/run.php`, 125 assertions, no server needed —
and `tests/mutate.sh`, which breaks the source 43 different ways and checks the
suite notices each one.

**First run: four mutations survived and two assertions were testing the wrong
thing.** All six are fixed. What was wrong:

| Problem | Why it passed when it should not have |
|---|---|
| The cap test compared against `Plan::CAP` | It followed the constant anywhere it went. Now asserts the literal six from §5. |
| The rule 3 test filled every slot with overdue cards | Rule 3 never ran. Now has room and three project cards, and asserts exactly one. |
| The close-out boundary test had no card on the neighbouring day | A range off by a day still passed. Now has one, checked in summer *and* winter. |
| `completed_at` was compared across two calls in the same second | A rewrite was invisible. Now backdated first. |
| The markdown tests asserted `"javascript:"` never appears anywhere | Not the property that matters — a rejected scheme rendered as escaped text is harmless. Now assert no anchor is produced, and that a quote in an href cannot start a new attribute. |
| The config test did not install the app's error handler | A missing key degraded quietly in the test but is fatal in the app. The test now runs under the same rule. |

**All 43 mutations are caught now.** If you change behaviour, add a mutation for
it — otherwise the test you just wrote may be asserting nothing.

## 3. How the database gets created

**None of your three was true.** There was only a CLI script, and there is no
shell.

Now: **the app applies its own schema on the first request**, tracked in
SQLite's `user_version`, so every later request costs one pragma. It also
creates `appdata/` rather than demanding it. Verified from a completely empty
directory over HTTP alone — `/health` reports what it did.

`bin/setup-db.php` still works if you ever do have a command line. The cron
answer, if you ever want it, would be
`/usr/bin/php /home/om44wfu4/coverapp/bin/setup-db.php` — but you should not
need it.

**That test found a real bug.** A `config.php` missing an optional key threw
"Undefined array key" through the error handler and took the whole app down. A
hand-edited config on a host with no shell will sometimes be missing a line, so
both transport flags now default to off.

## 4. Card context

`/assistant?card=N` loads the card into a fourth system block. Tested three
ways, as asked:

- **With a card** — five system blocks, the card among them, and the brand
  inherited *from the card* rather than from the browser.
- **Without a card** — four blocks, unchanged from before.
- **With an id that names nothing** — the page says so and offers the brand
  picker; the endpoint refuses it rather than quietly answering without the
  context it was asked for.

The card block sits **after** the cache breakpoint, so one cached prefix still
serves every card of a brand instead of a fresh one per card. The Asana link is
left out — the model cannot follow it and would only be invited to pretend it
had.

A card screen now links straight to the assistant with the card attached.

## noindex

Sent from PHP on every response, again in `.htaccess`, plus a `robots.txt`. One
person's working plan behind a shared password should never be in a search
result.

## Deployment

`bash bin/build-release.sh` builds `dist/`:

| File | Where |
|---|---|
| `plan.swytch.graphics.zip` | the document root |
| `coverapp.zip` | `/home/om44wfu4/coverapp/` |
| `password-hash.php` | upload only if needed, then delete |

**`DEPLOY.md` is the checklist** — thirteen steps for WePanel's file manager,
with a table of what each `/health` failure means. The cron line is:

```
17 2 * * * /usr/bin/php /home/om44wfu4/coverapp/bin/backup.php
```

`dist/` is committed on purpose. The person deploying has no way to build it.

**Building the zips found a real breakage.** `index.php` resolved the app as
`../src`, which is true in the repository and false on the server, where the app
is `coverapp/src`. It now tries both and, if neither is there, says which paths
it looked in instead of serving a blank page. I then unpacked the zips into the
real three-directory layout and drove every screen in a browser from those files
alone — that is what the "every screen clean from the deployed zips" check was.

Two things I could not solve for you, and `DEPLOY.md` handles both:

- **Generating the password hash needs a command line.** So there is a one-time
  browser page that does it. It is not in either zip, and the checklist says to
  delete it straight after.
- **File managers hide dotfiles.** If `.htaccess` does not get extracted, the
  front page works and everything else 404s. It is called out as the single most
  likely thing to go wrong.

## Still open

Unchanged from the original notes: the `[decide]` items, phase 4, and replacing
the seed data with the real three weeks. The subdomain and the certificate are
now steps 1 and 9 of `DEPLOY.md`.
