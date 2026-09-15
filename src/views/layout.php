<?php
/**
 * @var App $app
 * @var string $view
 * @var string $title
 */
$path = App::path();
$showHeader = $view !== 'login';
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" href="/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?php if ($showHeader): ?>
<header class="header">
  <span class="header-name">Marketing cover</span>
  <nav class="header-nav">
    <a href="/assistant"<?= $path === '/assistant' || $path === '/' ? ' aria-current="page"' : '' ?>>Assistant</a>
    <a href="/cards"<?= str_starts_with($path, '/cards') ? ' aria-current="page"' : '' ?>>All work</a>
    <a href="/log"<?= $path === '/log' ? ' aria-current="page"' : '' ?>>Log</a>
    <a href="/api/cards.csv" download>Export</a>
    <form action="/logout" method="post"><button type="submit" class="linkbutton">Sign out</button></form>
  </nav>
</header>
<?php endif; ?>
<?php require APP_ROOT . '/src/views/' . $view . '.php'; ?>
</body>
</html>
