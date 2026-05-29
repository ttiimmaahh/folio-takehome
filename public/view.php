<?php

// Back-compat: the original share links were /view.php?token=…. Recipient access
// now lives at the readable /d/{slug}?token=… (see document.php / router.php), so
// resolve the token to its document's slug and redirect there. Unknown tokens get
// the same obscure not-found as everything else.

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = (string) ($_GET['token'] ?? '');

$stmt = db()->prepare('
    SELECT d.slug
    FROM shares s
    JOIN documents d ON d.id = s.document_id
    WHERE s.token = ?
');
$stmt->execute([$token]);
$slug = $stmt->fetchColumn();

if ($slug !== false) {
    header('Location: /d/' . rawurlencode($slug) . '?token=' . urlencode($token));
    exit;
}

http_response_code(404);
render_header('Not available');
?>
<div class="centered-message">
    <h1>Not available</h1>
    <p>This document could not be found, or is no longer available.</p>
</div>
<?php render_footer(); ?>
