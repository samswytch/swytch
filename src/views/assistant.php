<?php
/**
 * The assistant panel (BRIEF.md §6).
 *
 * The markup here is the empty frame; assets/app.js fills it. The conversation
 * lives in the browser and nowhere else, which is what lets attachments be sent
 * without ever being stored (§6) — they are held in memory for as long as the
 * conversation is open and are gone when she starts a new question.
 */
?>
<?php
/**
 * @var array<string,mixed>|null $card   the card she opened this from, if any
 * @var bool $cardMissing                a ?card= that names nothing
 */
?>
<?php if ($cardMissing): ?>
  <main class="main">
    <p class="notice">That card is no longer there. Pick a brand below instead.</p>
  </main>
<?php endif; ?>
<main class="main" id="assistant"
      <?php if ($card !== null): ?>
      data-card-id="<?= (int) $card['id'] ?>"
      data-card-title="<?= htmlspecialchars((string) $card['title'], ENT_QUOTES, 'UTF-8') ?>"
      data-card-context="<?= htmlspecialchars((string) $card['context_key'], ENT_QUOTES, 'UTF-8') ?>"
      <?php endif; ?>
      data-contexts='<?= htmlspecialchars(json_encode(array_map(
          static fn(array $c): array => [
              'key' => $c['key'],
              'name' => $c['name'],
              'colour' => $c['colour'],
              'internal' => $c['internal'],
          ],
          Contexts::ALL
      ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'>

  <noscript>
    <h1 class="page-title">The assistant needs JavaScript</h1>
    <p class="instruction">
      Turn it on in the browser settings and reload. If you cannot, work from Asana and the
      authority envelope document, and ask Kev for anything commercial or urgent.
    </p>
  </noscript>
</main>

<script src="/assets/app.js" defer></script>
