<?php
/**
 * @var array<int,array{name:string,ok:bool,detail:string}> $checks
 * @var bool $ok
 */
?>
<main class="main main-wide">
  <h1 class="page-title"><?= $ok ? 'Everything is wired up' : 'Something is not wired up' ?></h1>
  <p class="lede">
    <?= $ok
      ? 'Check this once before 6 October, then leave it alone. It does not call the Claude API — confirm the key by asking the assistant a question.'
      : 'Fix the rows marked below before Sadie uses this. Nothing here calls the Claude API.' ?>
  </p>

  <div style="margin-top:1.5rem;border-top:1px solid var(--rule)">
    <?php foreach ($checks as $check): ?>
      <div class="log-entry">
        <div class="log-head">
          <span class="log-kind"><?= htmlspecialchars($check['name'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="meta"><?= $check['ok'] ? 'ok' : 'needs attention' ?></span>
        </div>
        <p class="<?= $check['ok'] ? 'log-answer' : '' ?>" style="margin-top:.5rem">
          <?= htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>
    <?php endforeach; ?>
  </div>
</main>
