<?php

function render_header(string $title, ?array $staff = null): void {
    ?>
<!doctype html>
<html lang="en">
<head>
    <script>
    // Apply the theme before paint to avoid a flash. Default to light; dark is an
    // explicit, persisted opt-in via the nav toggle.
    (function () {
        try {
            var saved = localStorage.getItem('folio-theme');
            document.documentElement.setAttribute('data-theme', saved || 'light');
        } catch (e) {}
    })();
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Folio</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="nav">
    <div class="nav-inner">
        <a href="/admin.php" class="brand">
            <span class="brand-mark">F</span>
            Folio
        </a>
        <div class="nav-right">
            <?php if ($staff): ?>
                <span class="nav-user"><strong><?= h($staff['name']) ?></strong> · <?= h($staff['email']) ?></span>
            <?php endif ?>
            <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
                <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path>
                </svg>
                <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2v2"></path><path d="M12 20v2"></path>
                    <path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path>
                    <path d="M2 12h2"></path><path d="M20 12h2"></path>
                    <path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path>
                </svg>
            </button>
        </div>
    </div>
</nav>
<main class="container">
    <?php
}

function render_footer(): void {
    ?>
</main>
<script>
// Theme toggle: flip data-theme and persist the choice.
(function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var root = document.documentElement;
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('folio-theme', next); } catch (e) {}
    });
})();

// Close any open schedule dropdown when clicking outside it (native <details>
// only closes via its own summary). Without JS the picker still toggles fine.
(function () {
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.doc-schedule[open]').forEach(function (d) {
            if (!d.contains(e.target)) {
                d.removeAttribute('open');
            }
        });
    });
})();

// Copy-to-clipboard for any .copy-widget (a readonly link + a copy button, and
// optionally a "Copied!" status). Clipboard API with an execCommand fallback;
// without JS the link is still a selectable field.
(function () {
    document.querySelectorAll('.copy-widget').forEach(function (w) {
        var btn = w.querySelector('.copy-btn');
        var input = w.querySelector('.copy-input');
        var status = w.querySelector('.copy-status');
        if (!btn || !input) { return; }
        btn.addEventListener('click', function () {
            var confirm = function () {
                btn.classList.add('is-copied');
                if (status) { status.textContent = 'Copied!'; status.classList.add('is-visible'); }
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    if (status) {
                        status.classList.remove('is-visible');
                        setTimeout(function () { status.textContent = ''; }, 220);
                    }
                }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(confirm, fallback);
            } else {
                fallback();
            }
            function fallback() {
                input.focus();
                input.select();
                try { document.execCommand('copy'); } catch (e) {}
                confirm();
            }
        });
    });
})();
</script>
</body>
</html>
    <?php
}
