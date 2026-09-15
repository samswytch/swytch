<?php

declare(strict_types=1);

/**
 * Front controller. The only PHP file inside the web root.
 *
 * Everything else — the application code, the brand packs, the database and the
 * API key — lives above it and is not servable.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$app = cover_boot();
$app->enforceHttps();

$auth = $app->auth();
$auth->start();

$path = App::path();
$method = App::method();

// ---- Open to anyone -------------------------------------------------------

if ($path === '/login') {
    if ($method === 'POST') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $result = $auth->signIn($password, App::clientIp());
        if ($result['ok']) {
            App::redirect('/');
        }
        $app->render('login', ['title' => 'Marketing cover', 'error' => $result['message']]);
    }

    if ($auth->isSignedIn()) {
        App::redirect('/');
    }
    $app->render('login', ['title' => 'Marketing cover', 'error' => null]);
}

// ---- Everything below needs the password ----------------------------------

if (!$auth->isSignedIn()) {
    if (str_starts_with($path, '/api/')) {
        App::jsonError('Your session has expired. Reload the page and enter the password again.', 401);
    }
    App::redirect('/login');
}

if ($path === '/logout' && $method === 'POST') {
    $auth->signOut();
    App::redirect('/login');
}

if ($path === '/' && $method === 'GET') {
    $app->render('assistant', ['title' => 'Assistant — Marketing cover']);
}

if ($path === '/log' && $method === 'GET') {
    $app->render('log', [
        'title' => 'Log — Marketing cover',
        'entries' => $app->logBook()->all(),
    ]);
}

if ($path === '/health' && $method === 'GET') {
    require APP_ROOT . '/src/handlers/health.php';
    cover_health($app);
}

if ($path === '/api/log.csv' && $method === 'GET') {
    $csv = $app->logBook()->toCsv();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . Csv::filename('marketing-cover-log') . '"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

if ($path === '/api/assistant' && $method === 'POST') {
    require APP_ROOT . '/src/handlers/assistant.php';
    cover_assistant($app);
}

if ($path === '/api/note' && $method === 'POST') {
    require APP_ROOT . '/src/handlers/note.php';
    cover_note($app);
}

// ---- Nothing matched ------------------------------------------------------

http_response_code(404);
$app->render('notfound', ['title' => 'Not found — Marketing cover']);
