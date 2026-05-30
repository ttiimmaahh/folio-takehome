<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();

// Staff can reference a document by its readable slug or its numeric id.
$docParam = trim((string) ($_GET['doc'] ?? ''));
if ($docParam !== '' && ctype_digit($docParam)) {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([(int) $docParam]);
} else {
    $stmt = db()->prepare('SELECT * FROM documents WHERE slug = ?');
    $stmt->execute([$docParam]);
}
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$error = null;
$created_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_share';

    if ($action === 'set_public') {
        // Toggle public visibility: public docs are viewable at /d/{slug} by
        // anyone, no token or email required.
        $isPublic = ($_POST['is_public'] ?? '') === '1' ? 1 : 0;
        $stmt = db()->prepare('UPDATE documents SET is_public = ? WHERE id = ?');
        $stmt->execute([$isPublic, $doc['id']]);
        audit_log('visibility', 'document', (int) $doc['id'], ['is_public' => (bool) $isPublic]);

        header('Location: /share.php?doc=' . rawurlencode($doc['slug']) . '&vis=1');
        exit;
    }

    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $error = 'Recipient email is required.';
    } else {
        $token = random_token();
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$doc['id'], $token, $email]);
        $shareId = (int) db()->lastInsertId();
        audit_log('create', 'share', $shareId, [
            'document_id' => $doc['id'],
            'recipient_email' => $email,
        ]);
        $created_token = $token;
    }
}

$isPublic = (int) ($doc['is_public'] ?? 0) === 1;
$base = 'http://' . $_SERVER['HTTP_HOST'];

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Share by email, or make the document public.</p>

<?php if (!empty($_GET['vis'])): ?>
    <div class="banner banner-success">Visibility updated.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($created_token): ?>
    <?php $shareUrl = $base . '/d/' . rawurlencode($doc['slug']) . '?token=' . $created_token; ?>
    <div class="banner banner-success share-ready copy-widget">
        <p class="share-ready-label">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 6 9 17l-5-5"></path>
            </svg>
            Share link ready
            <span class="copy-status" role="status" aria-live="polite"></span>
        </p>
        <div class="copy-field">
            <input type="text" class="copy-input" value="<?= h($shareUrl) ?>" readonly aria-label="Share link">
            <button type="button" class="copy-btn" aria-label="Copy link" title="Copy link">
                <svg class="icon-copy" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect>
                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>
                </svg>
                <svg class="icon-check" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5"></path>
                </svg>
            </button>
        </div>
    </div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Visibility</h2>
    <?php if ($isPublic): ?>
        <p class="card-text">This document is <strong>public</strong> — anyone with the link below can view it, no email required.</p>
        <?php $publicUrl = $base . '/d/' . rawurlencode($doc['slug']); ?>
        <div class="banner banner-success share-ready copy-widget">
            <p class="share-ready-label">
                Public link
                <span class="copy-status" role="status" aria-live="polite"></span>
            </p>
            <div class="copy-field">
                <input type="text" class="copy-input" value="<?= h($publicUrl) ?>" readonly aria-label="Public link">
                <button type="button" class="copy-btn" aria-label="Copy link" title="Copy link">
                    <svg class="icon-copy" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect>
                        <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>
                    </svg>
                    <svg class="icon-check" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                </button>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="set_public">
            <input type="hidden" name="is_public" value="0">
            <button type="submit" class="btn-link">Make private</button>
        </form>
    <?php else: ?>
        <p class="card-text">This document is <strong>private</strong> — only people you share with by email can open it.</p>
        <form method="post">
            <input type="hidden" name="action" value="set_public">
            <input type="hidden" name="is_public" value="1">
            <button type="submit" class="btn">Make public</button>
        </form>
    <?php endif ?>
</section>

<section class="card">
    <h2 class="card-title">Share with a recipient</h2>
    <p class="card-text">Generates a private link for one person; they can open it directly or by entering this email at <code>/d/<?= h($doc['slug']) ?></code>.</p>
    <form method="post">
        <input type="hidden" name="action" value="create_share">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
