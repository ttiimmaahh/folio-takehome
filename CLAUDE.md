# Folio — agent & contributor guide

A small PHP 8.3 + SQLite document-sharing tool. Staff create documents and share
them with recipients via links. No framework: `php -S` serves `public/`, PDO talks
to `db.sqlite`. Runs entirely in Docker.

## Run & test

```bash
docker compose up                          # serve on http://localhost:8000 (re-seeds db.sqlite fresh)
docker compose exec app php tests/test.php # run the test suite
docker compose exec app php migrate.php    # apply pending migrations standalone
```

There is no PHP on the host — run everything through the container.

## Architecture

- `public/` — page scripts: `admin.php` (create/list/search/schedule), `share.php`
  (mint a share link, by slug or id), `view.php` (recipient view, **token-only**).
- `lib/bootstrap.php` — `db()`, `current_staff()`, `audit_log()`, `random_token()`, `h()`.
- `lib/{publishing,slug,search}.php` — pure, unit-tested helpers (one concern each).
- `lib/migrate.php` + `migrations/*.sql` — forward-only migrations.
- `schema.sql` — the v0 baseline; **never edit it** (see below).
- `seed.php` — drops + rebuilds `db.sqlite`, runs migrations, seeds one of everything.

## Conventions (match these)

- **SQL:** always PDO prepared statements with bound params. DDL goes through migrations only.
- **Output:** escape every dynamic value with `h()` in templates.
- **Audit:** every state change (document create, scheduling change, share create) calls
  `audit_log($action, $entity_type, $entity_id, $details)`.
- **Migrations, not schema edits:** add `migrations/NNN_description.sql`; the `/new-migration`
  command scaffolds the next file. `schema.sql` stays frozen (a PreToolUse hook blocks edits to it).
- **Timezone:** store datetimes in **UTC**, convert to the staff zone (`date_default_timezone_get()`)
  only for input/display. Use the helpers in `lib/publishing.php`; never compare a UTC value to a
  local one.
- **New helpers earn a test.** If logic is worth a file, it's worth a test in `tests/test.php`.

## Why decisions were made the way they were

See `DECISIONS.md` — readable-IDs-complement-the-token, the timezone fix, the migration design,
and where an LLM would (and deliberately would not) fit this product.
