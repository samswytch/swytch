<?php
/**
 * The log (BRIEF.md §9). One screen, readable start to finish, newest last.
 * Nothing on this page edits or deletes anything.
 *
 * Close-out notes are the third kind of entry and arrive with the day view in
 * phase 3.
 *
 * @var array<int,array<string,mixed>> $entries
 */
?>
<main class="main main-wide">
  <h1 class="page-title">Log</h1>
  <p class="lede">
    Every logged decision and parked item, oldest first. Nothing here can be edited or removed.
    This is what Sam reads when he is back on Monday 26 October.
  </p>

  <?php if ($entries === []): ?>
    <p class="instruction">
      Nothing logged yet. Decisions the assistant marks for Sam, and anything parked until he is
      back, land here as you go.
    </p>
  <?php else: ?>
    <div style="margin-top:1.5rem;border-top:1px solid var(--rule)">
      <?php foreach ($entries as $entry):
        $key = (string) $entry['context_key'];
        $isDecision = $entry['kind'] === 'decision';
      ?>
        <article class="log-entry">
          <div class="log-head">
            <span class="log-kind"><?= $isDecision ? 'Logged decision' : 'Parked item' ?></span>
            <span class="log-context" style="--context-colour:<?= htmlspecialchars(Contexts::colour($key), ENT_QUOTES, 'UTF-8') ?>">
              <span class="log-dot" aria-hidden="true"></span>
              <?= htmlspecialchars(Contexts::name($key), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span class="meta"><?= htmlspecialchars(Clock::forHumans((string) $entry['created_at']), ENT_QUOTES, 'UTF-8') ?></span>
          </div>

          <div class="log-field">
            <p class="log-field-label">She asked</p>
            <p><?= nl2br(htmlspecialchars((string) $entry['question'], ENT_QUOTES, 'UTF-8')) ?></p>
          </div>

          <div class="log-field log-answer">
            <p class="log-field-label">The assistant said</p>
            <div class="prose"><?= Markdown::toHtml((string) $entry['answer']) ?></div>
          </div>

          <?php if ($entry['note'] !== null && $entry['note'] !== ''): ?>
            <div class="log-field">
              <p class="log-field-label"><?= $isDecision ? 'What she decided' : 'Her note' ?></p>
              <p><?= nl2br(htmlspecialchars((string) $entry['note'], ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
