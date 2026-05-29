<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/publishing.php';

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

test('local input round-trips through UTC storage', function () {
    // Guards the timezone trap: store UTC, display local, get the same wall time.
    $local = '2026-07-01 09:30:00';
    assert_true(
        utc_to_local(local_to_utc($local)) === $local,
        'round-trip changed the wall-clock time: ' . utc_to_local(local_to_utc($local))
    );
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
