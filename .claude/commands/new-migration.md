---
description: Scaffold the next numbered migration file
argument-hint: <short_snake_case_description>
---

Create a new migration for: **$ARGUMENTS**

1. Look in `migrations/` for the highest-numbered `NNN_*.sql` file.
2. Create `migrations/<NNN+1, zero-padded to 3>_$ARGUMENTS.sql`.
3. Start it with a one-line comment explaining the change and a `--` note on intent.
4. Write the DDL. Remember SQLite limits: you cannot `ALTER TABLE ... ADD` a `UNIQUE`
   column — add the column, then `CREATE UNIQUE INDEX` separately.

**Do not edit `schema.sql`** — it is the frozen v0 baseline. All schema changes are migrations.

Then apply it with the `/migrate` command and confirm the test suite still passes.
