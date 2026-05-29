<?php

// Minimal forward-only migration runner.
//
// schema.sql is the v0 baseline and is never edited. Each schema change is a
// numbered SQL file in migrations/ (e.g. 001_add_publish_at.sql). We track what
// has been applied in schema_migrations so each file runs at most once, and wrap
// each file in a transaction so a partial failure rolls back cleanly.
//
// Note: seed.php rebuilds db.sqlite from scratch on every boot, so in this app
// the runner re-applies every migration against a fresh DB each run. The tracker
// table still earns its place -- it keeps runs idempotent and means the same
// runner works unchanged if the DB ever becomes persistent.

function run_migrations(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $applied = $pdo
        ->query('SELECT version FROM schema_migrations')
        ->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    $files = glob(__DIR__ . '/../migrations/*.sql');
    sort($files); // filename prefix (001_, 002_, ...) defines order

    foreach ($files as $file) {
        $version = basename($file);
        if (isset($applied[$version])) {
            continue;
        }

        $sql = file_get_contents($file);
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
            $stmt->execute([$version]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration {$version} failed: " . $e->getMessage(), 0, $e);
        }

        echo "Applied migration {$version}\n";
    }
}
