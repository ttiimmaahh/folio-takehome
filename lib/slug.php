<?php

// Human-readable document slugs.
//
// A slug identifies a *document* (one per doc); it complements rather than
// replaces the per-recipient share token. The token still gates access, so
// slugs being guessable is acceptable -- they are identifiers, not secrets.

// Turn a title into lowercase kebab-case ASCII: "Welcome Packet 2026!" -> "welcome-packet-2026".
function slugify(string $title): string {
    $s = trim($title);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s === '' ? 'doc' : $s;
}

// Produce a slug guaranteed unique in the documents table. On collision, append
// a short base36 suffix ("welcome-packet-3k") rather than a counter -- avoids
// leaking how many documents share a title.
function unique_slug(PDO $pdo, string $title): string {
    $base = slugify($title);
    $slug = $base;

    $check = $pdo->prepare('SELECT 1 FROM documents WHERE slug = ?');
    $check->execute([$slug]);
    while ($check->fetchColumn() !== false) {
        $suffix = base_convert((string) random_int(36, 1295), 10, 36); // 2 chars: '10'..'zz'
        $slug = $base . '-' . $suffix;
        $check->execute([$slug]);
    }
    return $slug;
}
