<?php

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrate.php';
require __DIR__ . '/lib/slug.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
run_migrations($pdo);

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$title = 'Welcome Packet';
$slug = unique_slug($pdo, $title);
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, slug)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    $title,
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    $slug,
]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:         http://localhost:8000/admin.php\n";
echo "Readable URL:  http://localhost:8000/d/{$slug}  (prompts for the recipient email)\n";
echo "Direct share:  http://localhost:8000/d/{$slug}?token={$token}\n";
