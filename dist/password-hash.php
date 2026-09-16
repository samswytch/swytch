<?php

declare(strict_types=1);

/**
 * A one-time helper for a host with no shell.
 *
 * config.php stores a hash of the shared password, never the password itself.
 * Normally you would make one with `php -r 'echo password_hash(...)'`, which
 * needs a command line. This does the same job through a browser.
 *
 * Upload it into the document root, open it, type the password, copy the hash
 * into config.php — then DELETE THIS FILE. It is not part of the app and
 * should not be left on a live site.
 *
 * It stores nothing, logs nothing, and sends nothing anywhere.
 */

$hash = null;
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) ? (string) $_POST['password'] : '';
if ($submitted !== '') {
    $hash = password_hash($submitted, PASSWORD_DEFAULT);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Password hash</title>
<style>
  body { font: 16px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
         max-width: 40rem; margin: 0 auto; padding: 2rem 1.25rem; color: #16181a; }
  h1 { font-size: 1.5rem; letter-spacing: -0.015em; }
  input, button { font: inherit; }
  input { width: 100%; padding: .5rem; border: 1px solid #b3b8bc; border-radius: 2px; }
  button { margin-top: .75rem; padding: .5rem 1rem; background: #16181a; color: #fff;
           border: 1px solid #16181a; border-radius: 2px; cursor: pointer; }
  .hash { margin-top: 1.25rem; padding: .75rem; border: 1px solid #16181a; border-radius: 2px;
          word-break: break-all; font-weight: 600; }
  .warn { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #dcdfe1; color: #454b51; }
</style>
</head>
<body>
<h1>Password hash</h1>
<p>Type the shared password. This gives you the line to paste into
<code>appdata/config.php</code>. Nothing is stored or sent anywhere.</p>

<form method="post">
  <label for="password">Password</label>
  <input id="password" name="password" type="text" autocomplete="off" autofocus required>
  <button type="submit">Make the hash</button>
</form>

<?php if ($hash !== null): ?>
  <p style="margin-top:1.5rem">Paste this into <code>config.php</code>:</p>
  <div class="hash">'password_hash' =&gt; '<?= htmlspecialchars($hash, ENT_QUOTES, 'UTF-8') ?>',</div>
<?php endif; ?>

<p class="warn"><strong>Delete this file when you are done.</strong> It is not part of the app.
Leaving it on a live site is untidy and serves no purpose.</p>
</body>
</html>
