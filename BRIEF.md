# Build brief: marketing cover app (v2)

A small web app that lets one marketing assistant run five brands' marketing on her
own for three weeks, with no manager reachable. It does two things: it tells her
what today looks like, and it answers her questions inside a defined authority
envelope so she does not have to wait for a decision that will not come.

**Dates.** Sam is away Wednesday 7 October to Sunday 25 October, back Monday 26.
Sadie covers Wednesday 7 to Friday 23. Excluding Mondays that is **eleven working
days**, and the period starts on a Wednesday — see §5. A trial week with Sam present
starts Tuesday 29 September, so the app must be usable by then, not merely deployed.

Read this whole brief before writing code. Ask about anything marked **[decide]**
rather than guessing.

**The critical path is not code.** The authority envelope (§6) and the brand packs
(§7) are what actually replace the absent manager. They are written by Sam, not
generated. If they are not finished, nothing else in this document is worth
building. §13 lists them.

---

## 1. Scope, stated once

This is deliberately smaller than it could be. It is a judgement layer and a daily
sequence, not a project management product.

**In scope:** a brand-aware assistant with a hard authority envelope; an ordered
daily plan; a flat list of the three weeks' work; an append-only log; CSV export.

**Explicitly not built.** Do not add these, do not scaffold them, do not leave
hooks for them:

- no kanban board, no drag and drop, no columns
- no calendar view
- no file storage, blob storage, brand library, or card attachments
- no Asana API integration of any kind — no import, no push-back, no PAT
- no user accounts, roles, permissions, or approval queues
- no notifications, email, or supervisor alerts
- no analytics, reporting, or charts
- no recurrence engine
- no subtasks, custom fields, or per-brand variations

**Sadie has no Claude login of her own.** This app is her only route to the
assistant. That is what makes §6 the point of the project rather than a convenience,
and it is why the assistant is built first. Do not trim it, defer it, or reduce it to
a help widget.

Every one of these was considered and cut. The app is used for twelve working days
by one person. Complexity that would pay for itself over a year does not pay for
itself here, and every extra surface is another thing that can break while the only
person who can fix it is unreachable.

---

## 2. Who uses it

**Primary user: Sadie.** Marketing assistant, entry level, about a year posting
content for the group. She writes well and knows the brands' channels. What she has
not had to do before is decide priority when several things compete, and judge how
far her authority extends — her manager has always done that.

Design accordingly. She does not need hand-holding on how to write a LinkedIn post.
She needs the day sequenced, and she needs to know where her authority ends without
having to ask a person.

**Secondary user: Sam.** Senior marketing and brand lead. He sets everything up
before he leaves and reads the log when he returns. During the period he is
reachable only if something is genuinely desperate, and he will not have his laptop
or any files — so he can give an opinion and nothing else. Anything that needs a
document, an asset, or a system is not a question for him. The envelope should say
this in those words.

**Escalation: Kev**, Managing Director, in the same office every day and Sadie's
father. This is a low-friction path, not a formal one — she can simply ask him.

That changes the shape of the envelope. Kev can settle anything **commercial or
urgent** on the spot: pricing, lead times, spend, a complaint, anything legal or HR.
He is not the person for **marketing judgement** — brand direction, a new channel,
whether a campaign is right — which still waits for Sam. Keep that line clean, and
keep it out of the app's mechanics: Kev is never an approver, work is never routed
to him, and there is no notification or queue pointing at him.

**Constraint that shapes everything:** Sadie does not work on Swytch marketing on
Mondays. With the dates above that is **eleven working days**. Monday is handled
explicitly in §5.

**[decide]** Sadie may drop to four days before October. If she does, confirm which
day goes before loading the three weeks of content — if it is Monday, nothing about
the app changes; if it is any other day, the content load and the six-item cap both
need to come down.

---

## 3. Data model

One card type. Keep fields portable so everything exports cleanly back into Asana.

```
Card
  id
  title              required, short
  context            required, one of the six in §4
  stream             required, social | email | physical | premises
  due_date           required
  publish_time       optional, time of day — social and email only
  channel            optional — LinkedIn / Instagram / Facebook / Email
  status             not_started | in_progress | done
  priority           low | normal | high
  asana_url          optional — pasted by Sam at setup, rendered as "Open in Asana"
  notes              free text, markdown
  created_at, completed_at
```

Nothing beyond this. No owner field — every card is Sadie's. If a card needs
breaking down, it becomes two cards.

`asana_url` is the entire Asana integration. Sam pastes the task link when he loads
the work; the app renders it as a link. No API, no token, no sync. She clicks
through for history and original files.

Recurring content is **pre-generated as individual cards at setup**. There is no
recurrence field and no generator. Sam loads three weeks of dated cards before he
leaves; that is the point at which recurrence is resolved.

---

## 4. Contexts

Six colour-coded contexts. Five brands, one internal.

| Context | Colour role |
|---|---|
| Swytch Graphics | blue |
| Anchorprint | coral |
| Image Pro Systems | teal |
| Sterling POS | pink |
| Eden Building Works | green |
| Premises (internal) | neutral grey |

**[decide]** Confirm premises work is internal, not Eden Building Works client work.
This brief assumes internal.

---

## 5. The day view

The screen the app opens on. Get this right before anything else in the UI.

**The plan is generated, not assembled.** She does not build a priority list. On
load, the app produces an ordered list for today:

1. Anything with a `publish_time` today, in time order.
2. Anything overdue, or due within two days and not started.
3. The next open item on project work (physical materials, premises).
4. Stop. Cap at **six items**. Everything else stays in the list view.

Each item carries one short line saying why it is there — "publishes at 11:30",
"due Thursday, not started", "oldest open item". That line is not decoration. Over
twelve days it teaches her how the ordering works, which is the transferable part.

**Monday.** The card form rejects a Monday `due_date` or `publish_time` and suggests
the Friday before or the Tuesday after, with the reason stated inline. Friday
therefore carries more load than other days — that is intended and Sam accounts for
it when loading the content.

**Tuesday is a week view.** The full week ahead, ordered, with the ability to change
dates before committing. Wednesday to Friday are short check-ins against what
Tuesday set. Sadie's existing working pattern is already a Tuesday reset plus short
daily check-ins; this matches it rather than replacing it.

**Week one has no Tuesday.** Cover starts Wednesday 7 October. Sam sets that first
week's plan himself on Tuesday 6 October, before he leaves, so she opens the app on
day one to a week that is already ordered rather than to an empty reset. The app
needs no special case for this — it is a setup task, listed in §14.

**End of day close-out.** Two taps: what moved, what did not, one optional note. It
feeds the next morning's ordering and builds the log. Prompt for it, never force it,
never block on it.

**Friday close-out additionally prompts a CSV download.** One line, one button. This
is the backup — see §10.

---

## 6. The assistant

The most important part of the app, and the first thing built.

A chat panel, always reachable, and openable in the context of a specific card. She
picks a context, or it is inherited from the card. The system prompt is assembled
from: the brand pack for that context, the authority envelope, and the card if
there is one.

**Its main job is a pack check, not an authority oracle.** Sadie writes and publishes
on her own authority during the period, including new content she originates. What she
does not have is a second pair of eyes on whether a piece sits inside its brand. The
assistant is that second pair of eyes — positioning, voice, search intent boundary,
standing rules, and the verification list — and the authority tiers below are the
smaller part of what it does.

**It answers within an envelope and says which outcome applies, plainly.** Three
outcomes:

- **Go ahead.** Answer the question, and be explicit that it is her call.
- **Go ahead, logged.** Same, and the decision is written to the log for Sam.
- **Park it.** Explain why it sits outside the next three weeks, write it to the
  parked list with her note attached.

**Two rules about how it handles the edges**, because there is no manager to appeal
to and a cautious classifier becomes a hard stop:

1. **Uncertainty resolves to "go ahead, logged", not "park it."** If it cannot
   cleanly place a question, it says so, gives its reasoning, makes a recommendation,
   and logs the decision. Parking is for things clearly on the parked list, not for
   things it finds difficult.
2. **Parking is never a dead end.** Every park response ends with what to do instead.
   For anything commercial or urgent that is "ask Kev" — he is in the office and can
   answer today. For marketing judgement it is "this waits until Monday 26 October",
   with the item written to the parked list. Nothing is ever left with no route.

It must also never tell her to chase or route a sales enquiry. Enquiries reach
salespeople and account managers before they reach her.

### Authority envelope — draft

**[decide]** Sam finalises this before launch. It is the single thing that
determines whether the assistant is useful or hedges on everything. It ships as a
plain text file in the repo so it can be edited without a deploy.

```
Her call, no logging:
  wording and tone of planned content
  timing within the day
  choice of image from approved assets
  minor layout and design tweaks
  responding to comments in brand voice, without commercial specifics

Her call, logged:
  changing the topic of a planned post
  adding an unplanned post
  moving an email send date within the week
  reordering the week's priorities
  proceeding when an asset is missing or a proof is late
  anything the assistant cannot cleanly place

Ask Kev, in the office, same day — not parked:
  pricing, lead times, or any commercial commitment
  new spend
  anything legal or HR
  a customer complaint
  anything she judges urgent and outside the above

Parked until Sam is back on Mon 26 Oct:
  a new brand direction
  posting on a channel a brand has not used before
  anything that is a marketing judgement rather than a commercial one

Sam, only if genuinely desperate:
  he has no laptop and no files, so he can give an opinion and nothing more
  if the answer needs a document, an asset or a system, it is not a question
  for him — it is almost certainly sourceable in the office
```

### Images and PDFs

She can paste or drop an image or PDF **into the chat panel only**. It is passed to
the Anthropic API as base64 in the message content and is **not stored anywhere** —
no blob storage, no database record, no thumbnail pipeline. It exists for the length
of the conversation.

This is worth the small effort: dropping a proof and asking whether it reads right
for that brand is one of the more useful things the app does, and it is easy to
leave out by accident. Resize images client-side before sending. Mobile camera
capture must work — she photographs printed work and premises progress on her phone.

---

## 7. Brand packs

One plain text or markdown file per context, in the repo, loaded into the system
prompt. Sam writes these. Leave clearly marked placeholder files with this
structure:

```
Positioning — one paragraph
Audience — who it talks to
Voice — three or four traits, each with a do and a don't
Search intent boundary — what this brand owns, what belongs to a sister brand
Topics it posts about / topics it does not
Recurring formats
Standing rules — claims not to make, things never said
Assets — links to logos, templates, image library wherever they already live
```

Assets are **links out**, not uploads. Whatever file storage the group already uses
is where they stay.

The search intent boundary matters more than it looks. The brands are close enough
that content drifts between them, and keeping them distinct is a standing rule.

---

## 8. The list view

Where she looks things up. Not where she lands, and not a board.

A flat table of all cards: title, context colour, stream, due date, status. Filter
chips for the six contexts, a status filter, sort by due date. Click a row to open
the card. Inline status change. That is the whole feature.

No columns, no drag, no WIP cap, no auto-archive. Those rules exist to stop a board
degrading over months; over twelve days they are friction without a payoff.

---

## 9. The log

Append-only. One screen, readable start to finish, newest last.

Three kinds of entry, visually distinguished but in one stream:

- **Logged decisions** — timestamp, context, her question, the assistant's answer,
  what she decided.
- **Parked items** — timestamp, context, the question, her note.
- **Close-out notes** — date, what moved, what did not, her note.

This is what Sam reads on his return, and it is the record of how twelve days
actually went. Nothing edits or deletes from it.

---

## 10. Export, and what happens when it breaks

It is deployed once and not touched again. Nobody maintains it during the period, and
that is a deliberate choice rather than a gap — **the failsafe is that Sadie goes
back to Asana.** The work still exists there; the app is the better way to run it,
not the only record of it.

**CSV export ships in phase 1**, not last. All cards, all fields from §3, one
button, available on every screen. Thirty lines of code and it is the insurance
policy for the entire project. A second export covers the log.

Two things must exist outside the app before departure, because losing the app must
not lose them:

- the authority envelope, as a document Sadie has open independently — without Claude
  access of her own she cannot reconstruct it
- a CSV of all cards, downloaded the day Sam leaves

**One-line fallback, given to her at handover:** if the app is down, work from Asana
and the envelope document, note decisions in a document for Sam, ask Kev for
anything commercial or urgent, and carry on. Do not spend the day trying to fix it.

---

## 11. Technical

**Stack:** Next.js (App Router) on Vercel. Server-side API routes are required — the
Anthropic API key must never reach the browser. Postgres (Vercel Postgres or Neon).

**Auth:** single shared password via middleware, stored as an env var. Two users,
three weeks. Do not build accounts.

**Anthropic API:** called only from a server route that proxies the request and
injects the key. Send full conversation history each turn; the API is stateless.
Confirm the current model identifier against https://docs.claude.com/en/api/overview
rather than hardcoding one from memory.

**Rate limit the assistant route.** A shared password sitting in front of an API key
deserves a per-session cap and a daily cap, with a clear message when hit rather
than a silent failure. State the caps in the README so Sam can raise them.

**Deploy once.** No changes during the period, no hotfixes, nobody on call. Whatever
is live on 6 October is what runs until 26 October. This is the reason the scope in
§1 is as small as it is — build only what will still be working, untouched, in three
weeks.

**Errors are legible.** Every failure mode gets a plain-English message telling her
what to do — not a stack trace, not a spinner that never resolves. If the Anthropic
call fails, say so and tell her to try again or fall back to §10.

**Seed data.** Ship a seed script so Sam can load the three weeks quickly, and so
the ordering logic can be tested against real dates before departure.

---

## 12. Design direction

The client is a print and visual communications group. The interface should feel
like production tooling — precise, legible, unfussy — not a consumer productivity
app.

- One sans-serif family, real type scale, generous line height.
- Context colour is the only colour that carries meaning. Everything else neutral.
  Status is conveyed by position and typography, not a second palette.
- Flat. No shadows, no gradients, no rounded-card kit where every block gets the
  same treatment regardless of importance.
- Sentence case throughout. No all-caps labels, no eyebrow text above headings.
- Motion only in response to an action.
- Empty states are an instruction, not an apology. "Nothing publishes today. Pick up
  the sample pack reprint."
- Works on a phone. She will use it standing in the building photographing progress.

Avoid the current generated-design defaults: cream background with serif display and
a terracotta accent; identical rounded cards with soft grey shadows; monospace for
small data labels. Make a deliberate choice and be able to justify it.

---

## 13. Build order

**Phase 0 — Sam, before any code.** Envelope and brand packs drafted, reviewed and
signed off. Nothing below is worth building without these.

1. **Assistant, envelope, brand pack loading, log.** Deployable on its own and
   valuable on its own. If only this ships, Sadie still has the thing that replaces
   the absent manager.
2. **Data model, card form with enforced required fields, list view, CSV export.**
3. **Day view, ordering logic, the "why it's here" line, close-out.** Load the real
   three weeks and check the ordering feels right against actual dates.
4. **Tuesday week view.**

Phases 1 to 3 are the product. Phase 4 is genuinely optional — without it, Tuesday
is just a day that shows more items.

**Against the calendar**, working back from a trial starting Tuesday 29 September:

| By | What |
|---|---|
| Fri 18 Sep | Phase 0 done — envelope and brand packs signed off. API key live. |
| Tue 22 Sep | Phase 1 deployed. Assistant answering inside the envelope. |
| Fri 25 Sep | Phases 2 and 3. Real content loaded, ordering checked against real dates. |
| Tue 29 Sep – Fri 2 Oct | Trial week with Sam present. Sadie uses it for real; he watches and fixes. |
| Mon 5 – Tue 6 Oct | Final content load, week-one plan set, CSV downloaded, handover. |
| Wed 7 Oct | Cover starts. Nothing is touched after this. |

Phase 1 landing by the 22nd is what matters. If it slips, cut phase 4 and then the
Tuesday week view entirely before cutting anything from phase 1 — a trial week
spent exercising the assistant is worth more than one spent admiring a card list.

---

## 14. Open items for Sam

In order of how much they matter:

- **Review the authority envelope and the six brand packs.** Drafted — they need your
  read, not your drafting. The packs are the boundary the assistant checks new content
  against, so a vague pack produces a vague check.
- **Decide the Anchor email list question** — fresh PrintIQ export before you go, or no
  Anchor sends during the period. Do not leave her deciding whether to send to a list
  she cannot verify.
- **Load the three weeks of planned content**, so most days read "check and publish"
  rather than "create from scratch". Paste the Asana links as you go.
- **Set up an Anthropic API key** at console.anthropic.com — add billing, then set a
  hard monthly spend cap before departure. Expected usage for one person over eleven
  days is small, but the cap is what stops a loop or a leaked password becoming a
  bill nobody is watching. Set it low enough to be safe and high enough not to cut
  her off mid-October.
- Confirm the premises assumption in §4.
- Confirm whether Sadie drops to four days, and which day (§2).
- Agree with Kev that he is the escalation for anything commercial or urgent, and
  that Sadie's marketing time is protected from Production pull for the three weeks.
  The day plan assumes she has the day; if she is pulled into Production the plan
  quietly becomes fiction and there is nobody to notice.
- Run the trial week from Tue 29 Sep with Sadie actually using it, not demoing it.
  The point is to find the questions the envelope does not answer while you are still
  there to fix it.
- Set week one's plan on Tue 6 Oct before you go (§5).
- Run a handover session and give her the fallback line from §10.
- Download a CSV the day you leave.
