<?php

// Helpers for the audit-log viewer (public/audit.php). Read-only — the writing
// side is audit_log() in bootstrap.php.

// Recent audit events, newest first, with the actor's name and the affected
// document's title/slug resolved — for a document event directly, for a share
// event via its document — so each row says which document it was about.
function recent_audit_events(PDO $pdo, int $limit = 100): array {
    $limit = max(1, $limit);
    return $pdo->query(
        "SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.created_at,
                s.name AS staff_name,
                COALESCE(doc.title, sdoc.title) AS doc_title,
                COALESCE(doc.slug,  sdoc.slug)  AS doc_slug
         FROM audit_log a
         LEFT JOIN staff s        ON s.id = a.staff_id
         LEFT JOIN documents doc  ON a.entity_type = 'document' AND doc.id = a.entity_id
         LEFT JOIN shares sh      ON a.entity_type = 'share'    AND sh.id  = a.entity_id
         LEFT JOIN documents sdoc ON sdoc.id = sh.document_id
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT " . (int) $limit
    )->fetchAll();
}

// A human label for an (action, entity_type) pair, e.g. ('create','document')
// -> "Created document". Unknown pairs fall back to a readable default.
function audit_action_label(string $action, string $entityType): string {
    $labels = [
        'create:document'     => 'Created document',
        'create:share'        => 'Created share',
        'schedule:document'   => 'Changed schedule',
        'disable:document'    => 'Disabled document',
        'enable:document'     => 'Enabled document',
        'visibility:document' => 'Changed visibility',
    ];
    return $labels[$action . ':' . $entityType] ?? ucfirst($action) . ' ' . $entityType;
}

// A colour "tone" for an action, so the viewer can colour-code at a glance:
// positive (created/enabled), danger (disabled), warn (rescheduled), info
// (visibility), neutral (anything else).
function audit_action_tone(string $action): string {
    switch ($action) {
        case 'create':
        case 'enable':
            return 'positive';
        case 'disable':
            return 'danger';
        case 'schedule':
            return 'warn';
        case 'visibility':
            return 'info';
        default:
            return 'neutral';
    }
}

// Compact, human rendering of the JSON details blob, e.g. "publish_at: …" or a
// recipient email. Skips empty values and any keys in $skip (the viewer skips
// title/slug, which it shows separately as the document reference). Booleans
// render as key: yes/no.
function audit_details_summary(?string $json, array $skip = []): string {
    if ($json === null || $json === '') {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return '';
    }
    $parts = [];
    foreach ($data as $key => $value) {
        if ($value === null || $value === '' || in_array($key, $skip, true)) {
            continue;
        }
        $parts[] = is_bool($value) ? $key . ': ' . ($value ? 'yes' : 'no') : (string) $value;
    }
    return implode(' · ', $parts);
}
