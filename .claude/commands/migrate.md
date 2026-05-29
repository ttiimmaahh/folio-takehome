---
description: Apply pending database migrations in the app container
---

Run the migration runner inside the running app container:

```bash
docker compose exec app php migrate.php
```

Report which migration files were applied (or that the schema was already up to date).
If the container isn't running, start it first with `docker compose up -d`.
