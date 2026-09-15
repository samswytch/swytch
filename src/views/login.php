<?php
/** @var string|null $error */
?>
<main class="login">
  <h1 class="page-title">Marketing cover</h1>
  <p class="lede">Sadie and Sam. One password between you.</p>

  <form action="/login" method="post">
    <div class="login-field">
      <label class="login-label" for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>
    </div>
    <?php if ($error !== null): ?>
      <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <button type="submit" class="button button-primary login-button">Sign in</button>
  </form>
</main>
