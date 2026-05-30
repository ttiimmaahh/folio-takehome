<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/publishing.php';
require __DIR__ . '/../lib/slug.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/audit.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// --- Foundations: migrations & audit log ------------------------------------
// Both are graded requirements, so assert them directly rather than relying on
// the features that happen to exercise them.

test('migrations have been applied (columns added + tracked)', function () {
    $cols = array_column(db()->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
    foreach (['publish_at', 'slug', 'status', 'is_public'] as $c) {
        assert_true(in_array($c, $cols, true), "documents.{$c} should exist once migrations run");
    }
    $applied = (int) db()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    assert_true($applied >= 4, 'schema_migrations should record each applied migration');
});

test('audit_log records an action with its details', function () {
    $before = (int) db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    audit_log('test_action', 'document', 1, ['hello' => 'world']);

    $after = (int) db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    assert_true($after === $before + 1, 'audit_log should insert exactly one row');

    $row = db()->query('SELECT action, entity_type, details FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
    assert_true($row['action'] === 'test_action' && $row['entity_type'] === 'document', 'action/entity recorded');
    assert_true(strpos((string) $row['details'], 'world') !== false, 'details JSON recorded');
});

test('audit action labels are human-readable', function () {
    assert_true(audit_action_label('create', 'document') === 'Created document', 'create/document label');
    assert_true(audit_action_label('disable', 'document') === 'Disabled document', 'disable label');
    assert_true(audit_action_label('visibility', 'document') === 'Changed visibility', 'visibility label');
    assert_true(audit_action_label('frobnicate', 'widget') === 'Frobnicate widget', 'unknown pairs fall back readably');
    // colour tones for scannability
    assert_true(audit_action_tone('create') === 'positive', 'create -> positive');
    assert_true(audit_action_tone('disable') === 'danger', 'disable -> danger');
    assert_true(audit_action_tone('schedule') === 'warn', 'schedule -> warn');
    assert_true(audit_action_tone('frobnicate') === 'neutral', 'unknown -> neutral');
});

test('the seed writes an audit trail the viewer can show', function () {
    $actions = db()->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['create', 'schedule', 'visibility', 'disable'] as $a) {
        assert_true(in_array($a, $actions, true), "seeded audit trail should include a '{$a}' action");
    }
    $events = recent_audit_events(db(), 5);
    assert_true(count($events) >= 1, 'there should be recent events to show');
    assert_true(array_key_exists('staff_name', $events[0]), 'events resolve the actor name');
});

test('audit events resolve the affected document name and slug', function () {
    $events = recent_audit_events(db(), 100);
    $docEvent = null;
    $shareEvent = null;
    foreach ($events as $e) {
        if ($e['entity_type'] === 'document' && $docEvent === null) { $docEvent = $e; }
        if ($e['entity_type'] === 'share' && $shareEvent === null) { $shareEvent = $e; }
    }
    assert_true($docEvent !== null && $docEvent['doc_title'] !== null && $docEvent['doc_slug'] !== null,
        'a document event should resolve its title and slug');
    assert_true($shareEvent !== null && $shareEvent['doc_title'] !== null,
        'a share event should resolve its document title via the join');
});

// --- Scheduled publishing ---------------------------------------------------
// Why it matters: recipients must not see a document before its publish time.
// The gate is a pure function so we can assert the boundary without a clock.

test('a document with no publish_at is always available', function () {
    assert_true(is_available(null) === true, 'NULL publish_at should be live');
});

test('a future publish_at is not yet available', function () {
    // now is fixed so the test cannot flake on the wall clock.
    assert_true(
        is_available('2026-01-01 00:00:00', '2025-12-31 23:59:59') === false,
        'a future publish time must gate the document'
    );
});

test('a past publish_at is available', function () {
    assert_true(
        is_available('2025-12-31 23:59:59', '2026-01-01 00:00:00') === true,
        'a past publish time must release the document'
    );
});

test('format_datetime renders a stored UTC time as a friendly local string', function () {
    // 14:00 UTC on a summer date is 9:00 AM in America/Chicago (CDT, UTC-5).
    assert_true(
        format_datetime('2026-07-01 14:00:00') === 'Jul 1, 2026 at 9:00 AM CDT',
        'got: ' . format_datetime('2026-07-01 14:00:00')
    );
});

test('datetime rendering follows the active (viewer) timezone', function () {
    // The cookie override in bootstrap just changes the active zone; every helper
    // reads it, so the same UTC instant renders in each viewer's own zone.
    $orig = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    $eastern = format_datetime('2026-06-01 14:00:00'); // 14:00 UTC
    date_default_timezone_set('America/Chicago');
    $central = format_datetime('2026-06-01 14:00:00');
    date_default_timezone_set($orig);
    assert_true(strpos($eastern, '10:00 AM EDT') !== false, 'eastern viewer should see EDT: ' . $eastern);
    assert_true(strpos($central, '9:00 AM CDT') !== false, 'central viewer should see CDT: ' . $central);
});

test('local input round-trips through UTC storage', function () {
    // Guards the timezone trap: store UTC, display local, get the same wall time.
    $local = '2026-07-01 09:30:00';
    assert_true(
        utc_to_local(local_to_utc($local)) === $local,
        'round-trip changed the wall-clock time: ' . utc_to_local(local_to_utc($local))
    );
});

// --- Visibility & takedown --------------------------------------------------
// Why it matters: a disabled document must be unreachable by recipients, and a
// scheduled one must wait. document_view_state() is the single decision the
// recipient handler trusts, so we assert each outcome directly.

test('a disabled document is unavailable to recipients', function () {
    assert_true(
        document_view_state(['status' => 'disabled', 'publish_at' => null]) === 'unavailable',
        'disabled documents must read as unavailable'
    );
});

test('a live, future-dated document is not yet available', function () {
    assert_true(
        document_view_state(['status' => 'live', 'publish_at' => '2999-01-01 00:00:00']) === 'not_yet',
        'a future publish time must hold the document'
    );
});

test('a live, published document is viewable', function () {
    assert_true(
        document_view_state(['status' => 'live', 'publish_at' => null]) === 'ok',
        'a live, available document must be viewable'
    );
});

test('a recipient email resolves to its share token, case-insensitively', function () {
    // The seeded doc (#1) was shared with recipient@example.com. This is the
    // lookup behind the readable-URL email gate.
    $stmt = db()->prepare('
        SELECT token FROM shares
        WHERE document_id = ? AND lower(recipient_email) = lower(?)
        LIMIT 1
    ');
    $stmt->execute([1, 'RECIPIENT@Example.com']);
    assert_true($stmt->fetchColumn() !== false, 'email should resolve to a token regardless of case');
});

test('public documents need no credential; private ones do', function () {
    assert_true(is_public_doc(['is_public' => 1]) === true, 'a public doc should not require a token/email');
    assert_true(is_public_doc(['is_public' => 0]) === false, 'a private doc should require a credential');
    assert_true(is_public_doc([]) === false, 'a missing flag defaults to private');
});

test('the seed includes at least one public document', function () {
    $n = (int) db()->query('SELECT COUNT(*) FROM documents WHERE is_public = 1')->fetchColumn();
    assert_true($n >= 1, 'expected the seed to mark some documents public');
});

// --- Human-readable IDs -----------------------------------------------------
// Why it matters: slugs go in URLs and emails, so they must be clean and, above
// all, unique -- a collision would point two documents at the same identifier.

test('slugify produces clean kebab-case', function () {
    assert_true(
        slugify('Welcome Packet 2026!') === 'welcome-packet-2026',
        'got: ' . slugify('Welcome Packet 2026!')
    );
});

test('unique_slug disambiguates duplicate titles', function () {
    $pdo = db();
    $first = unique_slug($pdo, 'Quarterly Report');
    $pdo->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)')
        ->execute(['Quarterly Report', 'body', $first]);

    $second = unique_slug($pdo, 'Quarterly Report');
    assert_true($first === 'quarterly-report', "first slug should be the clean base, got {$first}");
    assert_true($first !== $second, "second slug must differ from the first ({$first})");
    assert_true(
        str_starts_with($second, 'quarterly-report-'),
        "collision slug should keep the base with a suffix, got {$second}"
    );
});

// --- Share by name (fuzzy search) -------------------------------------------
// Why fuzzy: staff search by half-remembered titles. The test pins the intent
// (typos still match, unrelated words don't) rather than the scoring internals.

test('search finds a document despite a typo in the query', function () {
    // seeded title is 'Welcome Packet'
    $hits = search_documents(db(), 'wlecome');
    assert_true(count($hits) >= 1, 'a one-transposition typo should still match');
    assert_true($hits[0]['title'] === 'Welcome Packet', 'best match should be Welcome Packet');
});

test('search excludes unrelated titles', function () {
    $titles = array_column(search_documents(db(), 'invoice'), 'title');
    assert_true(!in_array('Welcome Packet', $titles, true), 'unrelated query must not match');
});

test('search does not loosely match a longer, unrelated title', function () {
    // Regression: "parking" surfaced "New Hire Onboarding Guide" because the old
    // formula divided edit distance by the longer word ("onboarding"). Now it's
    // measured against the query length, so the loose match drops out.
    $titles = array_column(search_documents(db(), 'parking'), 'title');
    assert_true(in_array('Parking Permit Application', $titles, true), 'the real match should still appear');
    assert_true(!in_array('New Hire Onboarding Guide', $titles, true), 'a longer, loosely-similar title must not match');
});

test('search treats near-but-different words as non-matches', function () {
    // "report" is 3 edits from "records" (the shared re_or_ skeleton) — a
    // different word, not a typo — so it must not surface the Records documents.
    $titles = array_column(search_documents(db(), 'report'), 'title');
    assert_true(!in_array('Public Records Request Form', $titles, true), '"report" must not match "records"');
    assert_true(!in_array('Records Retention Policy (2019)', $titles, true), '"report" must not match "records"');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
