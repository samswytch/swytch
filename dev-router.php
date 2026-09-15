<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server, for running this on a laptop. Not deployed,
 * and outside the web root so it could not be served anyway.
 *
 *   COVER_CONFIG=/path/to/config.php php -S 127.0.0.1:8080 -t public dev-router.php
 *
 * On the real host LiteSpeed does this with the rewrite rules in
 * public/.htaccess; the built-in server has no .htaccess, so static files need
 * handing back to it explicitly.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve it
}

require __DIR__ . '/public/index.php';
