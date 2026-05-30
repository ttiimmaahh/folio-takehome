<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/publishing.php';
require __DIR__ . '/../lib/slug.php';
require __DIR__ . '/../lib/search.php';

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

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
