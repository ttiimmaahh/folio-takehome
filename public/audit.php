<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/publishing.php';
require __DIR__ . '/../lib/audit.php';

$staff = current_staff();

$limit = 100;
$events = recent_audit_events(db(), $limit);
$total = (int) db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

render_header('Audit log', $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Audit log</h1>
<p class="page-subtitle">Every document creation, scheduling change, share, and visibility change is recorded here.</p>

<section class="card">
    <h2 class="card-title">
        Activity
        <?php if ($total > count($events)): ?>
            <span class="card-count">showing the <?= count($events) ?> most recent of <?= $total ?></span>
        <?php endif ?>
    </h2>
    <?php if (empty($events)): ?>
        <p class="empty">No activity recorded yet.</p>
    <?php else: ?>
        <ul class="doc-list">
            <?php foreach ($events as $e): ?>
                <?php $summary = audit_details_summary($e['details']); ?>
                <li class="doc-item">
                    <div class="doc-main">
                        <div class="doc-headline">
                            <span class="doc-title"><?= h(audit_action_label($e['action'], (string) $e['entity_type'])) ?></span>
                            <?php if ($e['entity_type'] !== null): ?>
                                <span class="audit-entity"><?= h($e['entity_type']) ?> #<?= (int) $e['entity_id'] ?></span>
                            <?php endif ?>
                        </div>
                        <div class="doc-meta">
                            <span><?= h($e['staff_name'] ?? 'system') ?></span>
                            <span class="dot">·</span>
                            <span><?= h(format_datetime($e['created_at'])) ?></span>
                            <?php if ($summary !== ''): ?>
                                <span class="dot">·</span>
                                <span><?= h($summary) ?></span>
                            <?php endif ?>
                        </div>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>

<?php render_footer(); ?>
