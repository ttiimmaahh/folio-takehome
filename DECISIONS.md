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

## 2. Readable IDs **complement** the share token (they don't replace it)

The decisive factor is **table grain**, not privacy in the abstract:

- A **token lives on `shares`** — one per *recipient*, secret, revocable.
- A **slug lives on `documents`** — one per *document*.

They answer different questions ("is this person allowed in?" vs. "which document is this?"), so a
slug *cannot* replace a token without collapsing per-recipient sharing and revocation. The slug is
therefore a **staff-facing identifier** (admin list, share-by-slug lookup, readable audit log);
recipient access stays **token-only** in `view.php`. Because the slug is an identifier and not a
capability, its guessability is harmless. Slugs derive from the title and get a short base36 suffix
on collision (`welcome-packet-3k`) rather than a counter, so they don't leak how many titles clash.

- **Rejected — replace the token with the slug:** breaks the per-recipient access model above.
- **Rejected — hybrid `/d/{slug}?token=…` recipient URL:** leaks the document slug to recipients
  and widens the URL surface for nothing the token doesn't already provide.

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

## Progressive enhancement: the one place we use JavaScript

The app is otherwise plain server-rendered PHP. JavaScript is used in exactly two places, both
where the platform offers no CSS-only equivalent and both degrading gracefully:

- **Copy-to-clipboard** on the generated share link — "copy" is the expected affordance for a long,
  opaque token URL. Uses the Clipboard API with a `document.execCommand('copy')` fallback; the link
  stays a real, selectable field without JS.
- **Light/dark theme** — defaults to the OS `prefers-color-scheme` and can be toggled (persisted in
  `localStorage`). A tiny `<head>` script resolves the theme before paint to avoid a flash; the
  whole palette is CSS custom properties, so a single `[data-theme="dark"]` block covers it. Without
  JS the app simply stays in the light default. Honors `prefers-reduced-motion`.

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
