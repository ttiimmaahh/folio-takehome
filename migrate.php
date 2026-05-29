<?php

// CLI entrypoint: apply any pending migrations against db.sqlite.
//   php migrate.php
// seed.php calls run_migrations() directly; this wrapper is for running
// migrations standalone (e.g. the /migrate command, or a persistent DB).

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrate.php';

run_migrations(db());
echo "Migrations up to date.\n";
