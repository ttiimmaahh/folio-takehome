<?php

// Title search for the admin document list.
//
// Why fuzzy (typo-tolerant), and why in PHP: staff search by half-remembered
// titles ("wlecome packet"), so we want forgiveness, not exactness. The corpus
// is small (a staff tool's document list), so an O(n) scan with in-memory
// ranking is simpler and more capable than wiring an FTS/trigram extension into
// SQLite -- and it stays fully deterministic (no LLM needed for this).

const SEARCH_MIN_SCORE = 0.5;

// Score one title against a query in [0, 1]: exact > substring > closest-word
// edit distance. Token-level Levenshtein means a typo in one word still matches.
function title_match_score(string $q, string $title): float {
    $q = mb_strtolower(trim($q));
    $t = mb_strtolower(trim($title));
    if ($q === '' || $t === '') {
        return 0.0;
    }
    if ($t === $q) {
        return 1.0;
    }
    if (strpos($t, $q) !== false) {
        return 0.9;
    }

    $best = 0.0;
    foreach (preg_split('/\s+/', $t) as $word) {
        $maxLen = max(strlen($q), strlen($word));
        if ($maxLen === 0) {
            continue;
        }
        $sim = 1.0 - (levenshtein($q, $word) / $maxLen);
        $best = max($best, $sim);
    }
    return $best;
}

// Return documents whose title matches the query, best first. Same row shape as
// the admin list query so the caller can render it unchanged.
function search_documents(PDO $pdo, string $q): array {
    $rows = $pdo->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
    ')->fetchAll();

    $scored = [];
    foreach ($rows as $row) {
        $score = title_match_score($q, $row['title']);
        if ($score >= SEARCH_MIN_SCORE) {
            $row['_score'] = $score;
            $scored[] = $row;
        }
    }
    usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return $scored;
}
