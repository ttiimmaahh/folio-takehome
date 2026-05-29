<?php

// Front controller for PHP's built-in server (see the docker-compose command).
// Its only job is pretty document URLs:
//
//   /d/{slug}            -> public/document.php  (recipient access by token or email)
//
// Everything else — the admin pages, assets, etc. — is a real file under public/
// and is served unchanged (returning false hands the request back to the server).

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/d/([A-Za-z0-9][A-Za-z0-9-]*)/?$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/public/document.php';
    return true;
}

return false;
