<?php

// Scheduled-publishing helpers.
//
// Timezone discipline (the existing code has a latent trap here): SQLite's
// datetime('now') is UTC, but bootstrap.php sets PHP's default zone to
// America/Chicago. We resolve it deterministically -- publish_at is always
// STORED in UTC and only converted to the staff's local zone for display/input.
// The app's zone is read from date_default_timezone_get() so it stays defined
// in exactly one place (bootstrap.php).

// Convert a local datetime (as typed into a datetime-local input, in the app's
// zone) to a UTC string for storage.
function local_to_utc(string $local): string {
    $dt = new DateTime($local, new DateTimeZone(date_default_timezone_get()));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

// Convert a stored UTC datetime back to the app's local zone for display.
// Machine format ('Y-m-d H:i:s') -- used to seed the datetime-local input.
function utc_to_local(string $utc): string {
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $dt->format('Y-m-d H:i:s');
}

// Friendly local rendering of a stored UTC datetime, e.g. "May 29, 2026 at
// 4:13 PM CDT". The timezone abbreviation removes ambiguity about when a
// document actually goes live. Used wherever a datetime is shown to a human.
function format_datetime(string $utc): string {
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $dt->format('M j, Y \a\t g:i A T');
}

// Is a document visible to recipients yet? NULL publish_at means "live now".
// Both arguments are UTC 'Y-m-d H:i:s' strings, which sort lexicographically in
// chronological order, so a string compare is correct and cheap.
function is_available(?string $publish_at_utc, ?string $now_utc = null): bool {
    if ($publish_at_utc === null || $publish_at_utc === '') {
        return true;
    }
    $now_utc = $now_utc ?? gmdate('Y-m-d H:i:s');
    return $publish_at_utc <= $now_utc;
}

// Recipient-facing visibility of a found document, as a single decision:
//   'unavailable' -> disabled/taken down (shown as an obscure not-found)
//   'not_yet'     -> live but its publish time is in the future
//   'ok'          -> viewable now
function document_view_state(array $doc, ?string $now_utc = null): string {
    if (($doc['status'] ?? 'live') === 'disabled') {
        return 'unavailable';
    }
    if (!is_available($doc['publish_at'] ?? null, $now_utc)) {
        return 'not_yet';
    }
    return 'ok';
}

// Public documents are viewable at /d/{slug} with no token and no email gate.
// Private documents require a credential (a token, or an email on the share list).
function is_public_doc(array $doc): bool {
    return (int) ($doc['is_public'] ?? 0) === 1;
}
