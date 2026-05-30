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

// --- Staff (documents can be authored by any of them) -----------------------
// Staff #1 is the "logged-in" user (see current_staff()).
$staffStmt = $pdo->prepare('INSERT INTO staff (email, name) VALUES (?, ?)');
foreach ([
    ['freddy@folio.example', 'Freddy Folio'],
    ['dana@folio.example',   'Dana Reyes'],
    ['marcus@folio.example', 'Marcus Lee'],
] as $member) {
    $staffStmt->execute($member);
}

// Relative timestamps so the sample data stays sensible whenever it's seeded:
// disabled/old docs read as old, scheduled docs are always genuinely in the future.
$daysAgo   = fn(int $n) => gmdate('Y-m-d H:i:s', strtotime("-{$n} days"));
$daysAhead = fn(int $n) => gmdate('Y-m-d H:i:s', strtotime("+{$n} days"));

// --- Documents --------------------------------------------------------------
// Welcome Packet must stay document #1 with a share to recipient@example.com
// (the test suite relies on it). The rest give breadth: live / scheduled /
// disabled, several authors, a spread of dates, and a share or two each.
$documents = [
    [
        'title'      => 'Welcome Packet',
        'body'       => "Welcome to the City of Example!\n\nThis packet covers your first week: building access, key contacts, and where to find HR resources. Questions? Email people-ops@cityofexample.gov.",
        'created_by' => 1,
        'created_at' => $daysAgo(5),
        'publish_at' => null,
        'status'     => 'live',
        'recipients' => ['recipient@example.com', 'alex.morgan@example.com'],
    ],
    [
        'title'      => 'New Hire Onboarding Guide',
        'body'       => "A step-by-step guide for your first 30 days: accounts to set up, required trainings, and your onboarding buddy. Check off each item as you go.",
        'created_by' => 2,
        'created_at' => $daysAgo(12),
        'publish_at' => null,
        'status'     => 'live',
        'recipients' => ['alex.morgan@example.com'],
    ],
    [
        'title'      => 'Employee Handbook 2026',
        'body'       => "The 2026 edition of the employee handbook: code of conduct, time-off policy, benefits overview, and the remote-work guidelines effective this year.",
        'created_by' => 1,
        'created_at' => $daysAgo(40),
        'publish_at' => null,
        'status'     => 'live',
        'recipients' => ['jordan.kim@example.com'],
    ],
    [
        'title'      => '2026 Benefits Enrollment',
        'body'       => "Open enrollment runs through the end of the month. Review your medical, dental, and vision options and submit your elections in the benefits portal.",
        'created_by' => 2,
        'created_at' => $daysAgo(18),
        'publish_at' => null,
        'status'     => 'live',
        'recipients' => ['jordan.kim@example.com', 'taylor.brooks@example.com'],
    ],
    [
        'title'      => 'Q3 Board Meeting Minutes',
        'body'       => "Draft minutes from the Q3 board meeting, pending approval. Includes the budget subcommittee report and the vote on the facilities contract.",
        'created_by' => 3,
        'created_at' => $daysAgo(3),
        'publish_at' => $daysAhead(21),
        'status'     => 'live',
        'recipients' => ['board@cityofexample.gov'],
    ],
    [
        'title'      => 'FY2027 Budget Proposal',
        'body'       => "The proposed FY2027 operating and capital budget for council review. Highlights: infrastructure investment, staffing changes, and the parks initiative.",
        'created_by' => 3,
        'created_at' => $daysAgo(8),
        'publish_at' => $daysAhead(35),
        'status'     => 'live',
        'recipients' => ['council@cityofexample.gov'],
    ],
    [
        'title'      => 'Parking Permit Application',
        'body'       => "Application for a residential parking permit. Complete all fields, attach proof of residency, and submit to the clerk's office or upload here.",
        'created_by' => 1,
        'created_at' => $daysAgo(60),
        'publish_at' => null,
        'status'     => 'live',
        'recipients' => ['resident@example.com'],
    ],
    [
        'title'      => 'Public Records Request Form',
        'body'       => "Use this form to request public records under the state open-records law. We respond within the statutory timeframe.",
        'created_by' => 2,
        'created_at' => $daysAgo(75),
        'publish_at' => null,
        'status'     => 'live',
        'is_public'  => true,
        'recipients' => ['requester@example.com'],
    ],
    [
        'title'      => 'City Council Agenda — April 2026',
        'body'       => "Agenda for the April regular council meeting: public comment, consent items, and the rezoning hearing for the riverfront district.",
        'created_by' => 3,
        'created_at' => $daysAgo(30),
        'publish_at' => null,
        'status'     => 'live',
        'is_public'  => true,
        'recipients' => ['council@cityofexample.gov'],
    ],
    [
        'title'      => 'Snow Removal Policy',
        'body'       => "Public works snow and ice removal priorities, route maps, and the parking-ban procedure during declared snow emergencies.",
        'created_by' => 2,
        'created_at' => $daysAgo(150),
        'publish_at' => null,
        'status'     => 'live',
        'recipients' => ['publicworks@cityofexample.gov'],
    ],
    [
        'title'      => 'Vendor Contract Template',
        'body'       => "Standard vendor services agreement template. Superseded by the 2026 procurement standards — retained for reference only.",
        'created_by' => 3,
        'created_at' => $daysAgo(110),
        'publish_at' => null,
        'status'     => 'disabled',
        'recipients' => ['vendor@acme-supplies.com'],
    ],
    [
        'title'      => 'Records Retention Policy (2019)',
        'body'       => "The 2019 records retention schedule. Archived: replaced by the current policy. Do not use for new retention decisions.",
        'created_by' => 1,
        'created_at' => $daysAgo(200),
        'publish_at' => null,
        'status'     => 'disabled',
        'recipients' => ['records@cityofexample.gov'],
    ],
];

$insertDoc = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, created_at, publish_at, slug, status, is_public)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
');
$insertShare = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
// Seeding uses raw INSERTs, so it bypasses audit_log(). Write a matching audit
// trail here so the viewer opens already populated. A management action is
// recorded a little after the document was created, for a realistic timeline.
$insertAudit = $pdo->prepare('
    INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
');
$after = fn(string $ts, string $mod) => gmdate('Y-m-d H:i:s', strtotime("{$ts} {$mod}"));

$welcomeSlug = null;
$welcomeToken = null;
foreach ($documents as $doc) {
    $slug = unique_slug($pdo, $doc['title']);
    $insertDoc->execute([
        $doc['title'], $doc['body'], $doc['created_by'],
        $doc['created_at'], $doc['publish_at'], $slug, $doc['status'],
        !empty($doc['is_public']) ? 1 : 0,
    ]);
    $docId = (int) $pdo->lastInsertId();
    $by = $doc['created_by'];

    $insertAudit->execute([$by, 'create', 'document', $docId,
        json_encode(['title' => $doc['title'], 'slug' => $slug, 'publish_at' => $doc['publish_at']]),
        $doc['created_at']]);
    if ($doc['publish_at'] !== null) {
        $insertAudit->execute([$by, 'schedule', 'document', $docId,
            json_encode(['publish_at' => $doc['publish_at']]), $after($doc['created_at'], '+2 hours')]);
    }
    if (!empty($doc['is_public'])) {
        $insertAudit->execute([$by, 'visibility', 'document', $docId,
            json_encode(['is_public' => true]), $after($doc['created_at'], '+1 day')]);
    }
    if ($doc['status'] === 'disabled') {
        $insertAudit->execute([$by, 'disable', 'document', $docId,
            json_encode(['status' => 'disabled']), $after($doc['created_at'], '+3 days')]);
    }

    foreach ($doc['recipients'] as $email) {
        $token = random_token();
        $insertShare->execute([$docId, $token, $email]);
        $shareId = (int) $pdo->lastInsertId();
        $insertAudit->execute([$by, 'create', 'share', $shareId,
            json_encode(['document_id' => $docId, 'recipient_email' => $email]), $doc['created_at']]);

        if ($doc['title'] === 'Welcome Packet' && $welcomeToken === null) {
            $welcomeSlug = $slug;
            $welcomeToken = $token;
        }
    }
}

$docCount = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
echo "Seeded db.sqlite with {$docCount} documents (live, scheduled, and disabled).\n";
echo "Admin:         http://localhost:8000/admin.php\n";
echo "Readable URL:  http://localhost:8000/d/{$welcomeSlug}  (prompts for recipient@example.com)\n";
echo "Direct share:  http://localhost:8000/d/{$welcomeSlug}?token={$welcomeToken}\n";
