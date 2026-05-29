---
description: Run the Folio test suite
---

Run the test suite in the app container:

```bash
docker compose exec app php tests/test.php
```

It re-seeds a fresh `db.sqlite` (applying migrations) before running, so it is safe to
run repeatedly. Report the pass/fail summary; if anything fails, show the failing assertion.
