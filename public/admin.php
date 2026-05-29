<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/publishing.php';
require __DIR__ . '/../lib/slug.php';
require __DIR__ . '/../lib/search.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'schedule') {
        // Reschedule (or clear the schedule for) an existing document.
        $docId = (int) ($_POST['doc_id'] ?? 0);
        $publishLocal = trim($_POST['publish_at'] ?? '');
        $publishUtc = $publishLocal === '' ? null : local_to_utc($publishLocal);

        $stmt = db()->prepare('UPDATE documents SET publish_at = ? WHERE id = ?');
        $stmt->execute([$publishUtc, $docId]);

        audit_log('schedule', 'document', $docId, ['publish_at' => $publishUtc]);

        header('Location: /admin.php?scheduled=' . $docId);
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publishLocal = trim($_POST['publish_at'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $publishUtc = $publishLocal === '' ? null : local_to_utc($publishLocal);
        $slug = unique_slug(db(), $title);

        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at, slug)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publishUtc, $slug]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title' => $title,
            'slug' => $slug,
            'publish_at' => $publishUtc,
        ]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$query = trim($_GET['q'] ?? '');
if ($query !== '') {
    $docs = search_documents(db(), $query);
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if (!empty($_GET['scheduled'])): ?>
    <div class="banner banner-success">Schedule updated for document #<?= (int) $_GET['scheduled'] ?>.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (optional — leave blank to publish immediately; <?= h(date_default_timezone_get()) ?>)</label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form">
        <input type="search" name="q" value="<?= h($query) ?>" placeholder="Search by title…">
        <button type="submit" class="btn">Search</button>
        <?php if ($query !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </form>
    <?php if (empty($docs)): ?>
        <p class="empty"><?= $query !== '' ? 'No documents match “' . h($query) . '”.' : 'No documents yet.' ?></p>
    <?php else: ?>
        <ul class="doc-list">
            <?php foreach ($docs as $d): ?>
                <?php
                    $live = is_available($d['publish_at']);
                    // datetime-local wants 'Y-m-d\TH:i'; convert the stored UTC value to local.
                    $pubInput = $d['publish_at']
                        ? str_replace(' ', 'T', substr(utc_to_local($d['publish_at']), 0, 16))
                        : '';
                ?>
                <li class="doc-item">
                    <div class="doc-main">
                        <div class="doc-headline">
                            <span class="doc-title"><?= h($d['title']) ?></span>
                            <?php if ($live): ?>
                                <span class="pill pill-live">Live</span>
                            <?php else: ?>
                                <span class="pill pill-scheduled">Scheduled</span>
                            <?php endif ?>
                        </div>
                        <div class="doc-meta">
                            <code class="doc-slug"><?= h($d['slug'] ?? '') ?></code>
                            <span class="dot">·</span>
                            <span><?= h($d['creator_name']) ?></span>
                            <span class="dot">·</span>
                            <span>Created <?= h($d['created_at']) ?></span>
                        </div>
                        <?php if (!$live): ?>
                            <p class="doc-schedule-note">Goes live <?= h(utc_to_local($d['publish_at'])) ?></p>
                        <?php endif ?>
                        <details class="doc-schedule">
                            <summary><?= $d['publish_at'] ? 'Reschedule' : 'Schedule publishing' ?></summary>
                            <form method="post" class="schedule-form">
                                <input type="hidden" name="action" value="schedule">
                                <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                                <input type="datetime-local" name="publish_at" value="<?= h($pubInput) ?>">
                                <button type="submit" class="btn btn-small">Update</button>
                            </form>
                        </details>
                    </div>
                    <div class="doc-actions">
                        <a href="/share.php?doc=<?= h($d['slug'] ?? (string) $d['id']) ?>" class="icon-link" aria-label="Create share link" title="Create share link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 2v13"></path>
                                <path d="m16 6-4-4-4 4"></path>
                                <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                            </svg>
                        </a>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>

<?php render_footer(); ?>
