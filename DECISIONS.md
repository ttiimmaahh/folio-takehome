# Design decisions

Short record of the calls the brief left open, why I made them, and what I rejected.

## 1. Migrations: numbered SQL files + a tracker, run from `seed.php`

`schema.sql` is treated as the **frozen v0 baseline** (the brief forbids editing it). Each change
is a numbered file in `migrations/` (`001_add_publish_at.sql`, `002_add_document_slug.sql`),
applied in order by `lib/migrate.php` and recorded in a `schema_migrations` table so each runs at
most once. `seed.php` applies pending migrations right after loading the baseline.

The non-obvious wrinkle: `seed.php` **drops and rebuilds `db.sqlite` on every boot**, so in this
app migrations re-run against a clean DB each time. The tracker table still earns its place — it
keeps a run idempotent and means the exact same runner works unchanged the day the DB becomes
persistent.

- **Rejected — editing `schema.sql` directly:** forbidden, and it loses the change history that a
  migration log gives you. A PreToolUse hook now blocks edits to it.
- **Rejected — a full migration framework (Phinx, Doctrine):** far too heavy for a tool this size.
  The changes here are pure DDL; SQL files are the most transparent, reviewable form.

## 2. Readable IDs: the slug is the public URL; the token (or email) is the credential

**Table grain** frames it: a token lives on `shares` (one per *recipient*, secret, revocable); a
slug lives on `documents` (one per *document*). They answer different questions, so the slug never
*replaces* the token — it's an address, not a capability.

This decision **evolved during review**. I first made the slug staff-facing only and kept recipient
access token-only — which under-delivered the brief's own words ("type into a URL, paste into an
email"): there was no URL a person could actually use. So the slug became the **public, readable
URL** (`/d/welcome-packet`, routed by `router.php`), with access proven two ways:

- a **direct token link** (`/d/{slug}?token=…`) — the per-recipient share, now with a readable path; or
- the **bare slug** — prompt for the email the document was shared with; if it matches a share,
  redirect to that share's token URL. Email is the lightweight credential.

So a *guessed* slug only ever yields a login prompt, never the document — readable **and** private.
A missing or disabled slug returns one **obscure not-found**, so existence never leaks. Slugs derive
from the title with a short base36 suffix on collision (`welcome-packet-3k`), not a counter, so they
don't reveal how many titles clash. Email matching is case-insensitive; brute-force rate-limiting is
noted as future hardening.

- **Rejected — replace the token with the slug:** makes a guessable name the capability; breaks
  per-recipient sharing and revocation.
- **Reconsidered — the hybrid readable URL:** I initially rejected `/{slug}?token=` as leaking the
  slug for no gain. "Paste into an email" flipped that — a link that's both readable *and* secure is
  exactly the ask — and the email gate adds a way in even without the token in hand.

**Public documents** are a per-document opt-in, toggled on the share page and audit-logged: a public
doc is viewable at `/d/{slug}` with no credential at all — for genuinely public records (the seed
marks a records-request form and a council agenda public). Status and scheduling still gate it. So
there are three access modes: public (no credential), a direct token link, or email-against-the-
share-list.

## 3. Timezone: store UTC, display local

A latent bug in the starting code: PHP defaults to `America/Chicago` (`bootstrap.php`) but SQLite's
`datetime('now')` is UTC, so stored timestamps and displayed ones disagreed. Scheduled publishing
would have been wrong by the offset. Fix: `publish_at` is always stored UTC and converted to the
staff zone only for input/display (`lib/publishing.php`), and the availability gate compares UTC to
UTC. This correctly handles DST — a 09:00 Chicago time in June stores as 14:00 UTC (CDT), in January
as 06:00 UTC (CST).

## 4. Search: typo-tolerant, in PHP

Staff search by half-remembered titles, so matching is fuzzy (exact > substring > closest-word
Levenshtein) rather than exact. Implemented as an in-memory O(n) scan because the document list is
small; this is simpler and more capable than wiring an FTS/trigram extension into SQLite, and stays
fully deterministic.

Word closeness is measured **relative to the query length**, not the longer word. An early version
divided edit distance by `max(len(query), len(word))`, which let a long title word look similar just
because a handful of shared letters were a small fraction of it — searching `parking` surfaced
`New Hire Onboarding Guide` (5 edits / 10 = 0.50, right on the threshold; they only share `-ing`).
Dividing by the query length instead makes that 5/7 = 0.29 (dropped) while a real typo like
`wlecome → welcome` stays 0.71.

The accept threshold is **0.6** — about a typo's worth of similarity. Below it the query is a
*different* word, not a misspelling: `report` vs `records` is 3 edits = 0.50, so it no longer pulls
in the Records documents, while `wlecome → welcome` (0.71) and plurals (`reports → report`, 0.86)
stay. It's a deliberately simple, tunable knob; a real engine (FTS / trigram / semantic) is the
longer-term answer for precision. Regression tests pin both the `parking` and `report` cases.

- **Rejected — `LIKE '%q%'` only:** no typo tolerance.
- **Rejected — SQLite FTS5 / trigram:** more moving parts than a small staff list warrants.
- **Rejected — an LLM semantic ranker:** see §5.

## 5. No runtime LLM — and where one *would* fit

This is a Senior **AI Product Engineer** exercise, so the deliberate choice to keep the product
deterministic is itself a judgment call. Reviewers clone and run `docker compose up` **without an
API key**: an embedded LLM call would either silently fall back (so they'd never see it) or demand
a key and break the "runs cleanly from a fresh clone" requirement. Bolting AI onto slug generation
or typo-matching — both deterministic problems — would be AI-for-show. The AI competency here lives
in the **agentic build workflow** (see `CLAUDE.md` and the commits) instead.

Where an LLM genuinely *would* earn its place as this product grows — i.e. what I'd build next:

- **Semantic document search** alongside the deterministic fuzzy layer: embed titles/bodies, match
  on *intent* ("new-hire paperwork" → "Welcome Packet"). Served with **structured outputs** so the
  ranking is typed and testable, and gated behind a key with graceful fallback to today's fuzzy
  search.
- **RAG over the document corpus** once it's large — "find the clause about refunds across all
  shared docs."
- **Eval harness for the non-deterministic parts:** a fixed set of (query → expected doc) cases run
  in CI, scored for precision/recall, separate from the deterministic unit tests — so a prompt or
  model change can't silently regress relevance.
- **Observability:** route every model call through `audit_log` (latency, tokens, outcome) so the
  audit trail doubles as telemetry.

## 6. UI: a stacked list, not a table — the features reshaped the layout

Each new feature added information to a document row — a slug, a created date, a Live/Scheduled
status, a reschedule control, a share action. The original `<table>` couldn't absorb that on a
~650px card: columns crammed and the row action wrapped, then clipped. Rather than fight it, the
documents list became a **stacked list** — title + status pill, one muted metadata line
(`slug · creator · created`), the reschedule tucked into a native `<details>` disclosure (no JS),
and the share as an icon. Datetimes render friendly and local (`May 29, 2026 at 4:18 PM CDT`).

The point: the *functional* requirements drove the design, not the reverse — and the progression
is legible in the commit history (table → tidy → list redesign). Verified each step by rendering
the page headless and screenshotting, which also caught a dead-end (a clipped action) before it
shipped.

## 7. Document takedown: a reversible status, not a delete

Staff can disable a document (and re-enable it) from the admin list via an eye/eye-off toggle,
audit-logged as `disable`/`enable`. A disabled doc shows a "Disabled" pill to staff and reads as the
same **obscure not-found** to recipients — taking it down doesn't confirm it ever existed. I chose a
reversible `status` over a hard delete: for a records/civic tool you want to pull a document without
destroying it or its audit trail. Scheduling (`publish_at`) and status are independent axes, combined
in one tested decision, `document_view_state()`.

- **Rejected — hard delete:** irreversible, loses history, and trips foreign keys against existing
  shares. Disable is reversible and auditable.

## 8. Comparing against the original: a git-worktree harness

To make the before/after obvious — and easy for a reviewer — `scripts/compare.{sh,ps1}` run both
versions at once: this branch on `:8000` and the original `main` on `:8001`. The wrinkle is that two
branches can't share one working tree, so the script checks `origin/main` out into a throwaway **git
worktree** (`.worktrees/baseline`, gitignored) and runs it as a second Docker Compose project
(`docker-compose.baseline.yml`, mapped to `:8001`). No second clone, no port collision — one command
(`compare.sh up`) brings up a true side-by-side, and `compare.sh down` tears it all down, worktree
included. Validated from a fresh clone: clone → checkout the branch → run the script → both apps up,
18 tests green.

## 9. Audit log viewer — surfacing the telemetry

Every state change already calls `audit_log()`. A read-only viewer (`public/audit.php`, linked from
admin) surfaces it newest-first: the actor, a human action label ("Created document", "Changed
schedule", "Made public"…), the entity, and the decoded details. Logging you can't see is half a
feature; for a records/civic tool an accountability trail is a real requirement, not decoration — and
it's the observability story the JD asks for ("implement logging… diagnose using telemetry"). One
wrinkle: the seed inserts rows with raw SQL, *bypassing* `audit_log()`, so it writes a matching trail
itself — otherwise the viewer would open empty on a fresh boot. Read-only, one query, no new write
paths; action labels and details formatting are small tested helpers in `lib/audit.php`.

## Progressive enhancement: JavaScript only where the platform needs it

The app is server-rendered PHP. JavaScript appears only as progressive enhancement — each use has no
clean CSS-only equivalent, and each degrades gracefully:

- **Copy-to-clipboard** on the generated share link — "copy" is the expected affordance for a long,
  opaque token URL. Uses the Clipboard API with a `document.execCommand('copy')` fallback; the link
  stays a real, selectable field without JS.
- **Light/dark theme** — defaults to **light**; dark is an explicit, persisted (`localStorage`)
  opt-in via the nav toggle. A tiny `<head>` script applies the saved choice before paint (no flash);
  the whole palette is CSS custom properties, so a single `[data-theme="dark"]` block covers it.
  Without JS it stays light. Honors `prefers-reduced-motion`. (An earlier version auto-detected the
  OS `prefers-color-scheme`; defaulting to light keeps parity with the original app and makes dark a
  deliberate choice — and a cleaner before/after in the comparison.)
- **Click-outside-to-close** for the schedule-picker dropdown — the picker itself is a native
  `<details>`; this one listener just dismisses it on an outside click. Without JS it still toggles
  from its own icon.

## Things in the existing code worth flagging

- **`audit_log()` is hardwired to staff #1** via `current_staff()`. Fine for staff actions, but it
  can't attribute recipient-side events (e.g. a recipient *viewing* a doc) even though the schema
  allows `staff_id NULL`. I left recipient views un-audited rather than mis-attribute them; the real
  fix is a nullable-actor variant of the helper.
- **No authentication or tenancy** — a single hardcoded staff user. For a multi-tenant SaaS this is
  the first thing I'd add (org scoping on every table + query, real sessions); I scoped it out to
  spend the time on the three features.

## What I'd do with more time

Recipient-view audit events; share-link revocation/expiry (the schema is ready for it); the semantic
search + eval harness above; auth + tenant scoping; and pagination/index on the search path once the
document count is large enough to outgrow the O(n) scan.

## Time spent

The brief budgets ~3 hours. The commit timestamps (`git log --date=format:'%m-%d %H:%M' main..HEAD`)
tell the real story:

- Planning and context-gathering came first (reading the code, a written plan, a few clarifying
  questions about the open calls). First commit landed at **05-29 16:36**.
- **All three features** were committed by **16:43**, and the **full graded deliverable** — migration
  system, a test per feature, audit logging, and the agent setup + decision docs — by **16:47**.
  That's roughly **~10–15 minutes of implementation** after planning.
- The rest is iterative refinement and review-driven follow-ups across two short sessions (an
  overnight break between): on 05-29 to ~18:17, the UX polish (list redesign, friendly datetimes,
  copy-to-clipboard, dark mode) plus the readable-`/d/{slug}`-URL realization and document takedown;
  then a brief 05-30 session (~11:42–12:06) adding the calendar-icon scheduling UX, **public
  documents**, the side-by-side **comparison scripts**, and direct tests for the migration runner and
  audit log.

Total active wall-clock was roughly **~2 hours** (excluding the break) against the 3-hour budget —
and the clear majority was optional polish and review-driven follow-ups, not the required scope (done
in the first ~11 minutes of implementation). That speed is the point of the exercise for an AI-product
role: the agentic workflow (plan → implement → render/screenshot → verify → commit) let the required
scope land fast and left room to iterate on both experience and judgment.
