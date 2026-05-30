<?php

// Helpers for the audit-log viewer (public/audit.php). Read-only — the writing
// side is audit_log() in bootstrap.php.

// Recent audit events, newest first, with the actor's name resolved.
function recent_audit_events(PDO $pdo, int $limit = 100): array {
    $limit = max(1, $limit);
    return $pdo->query('
        SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.created_at,
               s.name AS staff_name
        FROM audit_log a
        LEFT JOIN staff s ON s.id = a.staff_id
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT ' . (int) $limit . '
    ')->fetchAll();
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

// Compact, human rendering of the JSON details blob: "Welcome Packet ·
// welcome-packet". Skips empty values; shows booleans as key: yes/no.
function audit_details_summary(?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return '';
    }
    $parts = [];
    foreach ($data as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $parts[] = is_bool($value) ? $key . ': ' . ($value ? 'yes' : 'no') : (string) $value;
    }
    return implode(' · ', $parts);
}
