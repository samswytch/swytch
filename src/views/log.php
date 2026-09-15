<?php
/**
 * The log (BRIEF.md §9). One screen, readable start to finish, newest last.
 * Nothing on this page edits or deletes anything.
 *
 * Four kinds in one stream, told apart by their heading and by which fields
 * they carry, not by colour — context colour is the only colour that means
 * anything (§12).
 *
 * @var array<int,array<string,mixed>> $entries
 */
?>
<main class="main main-wide">
  <h1 class="page-title">Log</h1>
  <p class="lede">
    Every logged decision, parked item, question for Kev and end-of-day note, oldest first.
    Nothing here can be edited or removed. This is what Sam reads when he is back on
    Monday 26 October.
  </p>

  <?php if ($entries === []): ?>
    <p class="instruction">
      Nothing logged yet. Decisions the assistant marks for Sam, anything parked until he is
      back, anything routed to Kev, and each day's close-out all land here as you go.
    </p>
  <?php else: ?>
    <div style="margin-top:1.5rem;border-top:1px solid var(--rule)">
      <?php foreach ($entries as $entry):
        $kind = (string) $entry['kind'];
        $key = $entry['context_key'] === null ? null : (string) $entry['context_key'];
      ?>
        <article class="log-entry">
          <div class="log-head">
            <span class="log-kind"><?= htmlspecialchars(LogBook::label($kind), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($key !== null): ?>
              <span class="log-context" style="--context-colour:<?= htmlspecialchars(Contexts::colour($key), ENT_QUOTES, 'UTF-8') ?>">
                <span class="log-dot" aria-hidden="true"></span>
                <?= htmlspecialchars(Contexts::name($key), ENT_QUOTES, 'UTF-8') ?>
              </span>
            <?php endif; ?>
            <span class="meta"><?= htmlspecialchars(Clock::forHumans((string) $entry['created_at']), ENT_QUOTES, 'UTF-8') ?></span>
          </div>

          <?php if ($kind === 'closeout'): ?>
            <div class="log-field">
              <p class="log-field-label">What moved</p>
              <p><?= nl2br(htmlspecialchars((string) ($entry['moved'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
            <div class="log-field">
              <p class="log-field-label">What did not</p>
              <p><?= nl2br(htmlspecialchars((string) ($entry['not_moved'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
          <?php else: ?>
            <div class="log-field">
              <p class="log-field-label">She asked</p>
              <p><?= nl2br(htmlspecialchars((string) ($entry['question'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
            <div class="log-field log-answer">
              <p class="log-field-label">The assistant said</p>
              <div class="prose"><?= Markdown::toHtml((string) ($entry['answer'] ?? '')) ?></div>
            </div>
          <?php endif; ?>

          <?php if ($entry['note'] !== null && $entry['note'] !== ''): ?>
            <div class="log-field">
              <p class="log-field-label"><?= htmlspecialchars(LogBook::noteLabel($kind), ENT_QUOTES, 'UTF-8') ?></p>
              <p><?= nl2br(htmlspecialchars((string) $entry['note'], ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
