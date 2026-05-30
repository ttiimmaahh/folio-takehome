<?php

// Recipient-facing document access, reached via the pretty URL /d/{slug}
// (routed by router.php). Ways in, in order:
//   1. /d/{slug}?token=…  — a direct per-recipient share link: straight in.
//   2. /d/{slug} on a PUBLIC document — no credential needed.
//   3. /d/{slug} on a private document — prove access by entering the email it
//      was shared with; we redirect to that share's token URL.
// A disabled or non-existent slug looks identical (obscure not-found), so a
// guessed slug leaks nothing.

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/publishing.php';

function document_unavailable(): void {
    http_response_code(404);
    render_header('Not available');
    ?>
    <div class="centered-message">
        <h1>Not available</h1>
        <p>This document could not be found, or is no longer available.</p>
    </div>
    <?php
    render_footer();
    exit;
}

function show_not_yet(array $doc): void {
    render_header($doc['title']);
    ?>
    <div class="centered-message">
        <h1>Not yet available</h1>
        <p>This document is scheduled to become available on
            <strong><?= h(format_datetime($doc['publish_at'])) ?></strong>.</p>
        <p>Please check back then.</p>
    </div>
    <?php
    render_footer();
    exit;
}

// Render the document. $sharedWith is the recipient's email for a private share,
// or null for public access.
function show_document(array $doc, ?string $sharedWith): void {
    render_header($doc['title']);
    ?>
    <h1 class="page-title"><?= h($doc['title']) ?></h1>
    <p class="meta"><?= $sharedWith !== null ? 'Shared with ' . h($sharedWith) : 'Public document' ?></p>
    <pre class="doc-body"><?= h($doc['body']) ?></pre>
    <?php
    render_footer();
    exit;
}

$slug  = (string) ($_GET['slug'] ?? '');
$token = (string) ($_GET['token'] ?? '');

$stmt = db()->prepare('SELECT * FROM documents WHERE slug = ?');
$stmt->execute([$slug]);
$doc = $stmt->fetch();

// Not found and taken-down are indistinguishable on purpose.
if (!$doc || document_view_state($doc) === 'unavailable') {
    document_unavailable();
}

// --- Path 1: a token was supplied — it must belong to THIS document. --------
if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM shares WHERE token = ? AND document_id = ?');
    $stmt->execute([$token, $doc['id']]);
    $share = $stmt->fetch();
    if (!$share) {
        document_unavailable();
    }
    if (document_view_state($doc) === 'not_yet') {
        show_not_yet($doc);
    }
    show_document($doc, $share['recipient_email']);
}

// --- Path 2: no token, but the document is public — anyone may view. --------
if (is_public_doc($doc)) {
    if (document_view_state($doc) === 'not_yet') {
        show_not_yet($doc);
    }
    show_document($doc, null);
}

// --- Path 3: private, no token — prove access by email, then redirect. ------
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email !== '') {
        $stmt = db()->prepare('
            SELECT token FROM shares
            WHERE document_id = ? AND lower(recipient_email) = lower(?)
            ORDER BY id LIMIT 1
        ');
        $stmt->execute([$doc['id'], $email]);
        $shareToken = $stmt->fetchColumn();
        if ($shareToken !== false) {
            header('Location: /d/' . rawurlencode($slug) . '?token=' . urlencode($shareToken));
            exit;
        }
    }
    // Same message whether the email isn't on the list or the slug is bogus.
    $error = "We couldn't find access for that email address.";
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<p class="page-subtitle">Enter the email address this document was shared with to continue.</p>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <form method="post">
        <div class="form-field">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required autofocus>
        </div>
        <button type="submit" class="btn">Access document</button>
    </form>
</section>

<?php render_footer(); ?>
