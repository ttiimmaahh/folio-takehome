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

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Generate a one-time link for a recipient.</p>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($created_token): ?>
    <?php $shareUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/view.php?token=' . $created_token; ?>
    <div class="banner banner-success share-ready">
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
    <script>
    (function () {
        var btn = document.querySelector('.copy-btn');
        var input = document.querySelector('.copy-input');
        var status = document.querySelector('.copy-status');
        if (!btn || !input) { return; }
        btn.addEventListener('click', function () {
            var confirm = function () {
                btn.classList.add('is-copied');
                if (status) {
                    status.textContent = 'Copied!';
                    status.classList.add('is-visible');
                }
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    if (status) {
                        status.classList.remove('is-visible');
                        setTimeout(function () { status.textContent = ''; }, 220);
                    }
                }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(confirm, fallback);
            } else {
                fallback();
            }
            function fallback() {
                input.focus();
                input.select();
                try { document.execCommand('copy'); } catch (e) {}
                confirm();
            }
        });
    })();
    </script>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <form method="post">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
