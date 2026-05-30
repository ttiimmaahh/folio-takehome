<?php

// Title search for the admin document list.
//
// Why fuzzy (typo-tolerant), and why in PHP: staff search by half-remembered
// titles ("wlecome packet"), so we want forgiveness, not exactness. The corpus
// is small (a staff tool's document list), so an O(n) scan with in-memory
// ranking is simpler and more capable than wiring an FTS/trigram extension into
// SQLite -- and it stays fully deterministic (no LLM needed for this).

// A fuzzy score below this means more than ~a typo's worth of the query had to
// change to reach the word, i.e. it's a different word, not a misspelling. 0.6
// keeps 1-2 character slips ("wlecome" -> "welcome" = 0.71) but drops vaguely
// similar words ("report" vs "records" = 0.50). Substring/exact matches (>= 0.9)
// are unaffected. Tune this knob; a real engine (FTS / trigram / semantic) is the
// longer-term answer for precision.
const SEARCH_MIN_SCORE = 0.6;

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

    // Typo tolerance: closeness of the query to its nearest title word, measured
    // relative to the QUERY length. Using the query length (not the longer word)
    // keeps a long title word from looking similar just because a few shared
    // letters are a small fraction of it -- e.g. "parking" vs "onboarding" is
    // 5 edits, which is 0.29 against the 7-char query, not 0.50 against the word.
    $qlen = strlen($q);
    $best = 0.0;
    foreach (preg_split('/\s+/', $t) as $word) {
        $sim = 1.0 - (levenshtein($q, $word) / $qlen);
        if ($sim > $best) {
            $best = $sim;
        }
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
