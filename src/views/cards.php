<?php
/**
 * The list view (BRIEF.md §8). Where she looks things up. Not where she lands,
 * and not a board.
 *
 * A flat table: title, context colour, stream, due date, status. Filter chips
 * for the six contexts, a status filter, sort by due date, click a row to open
 * the card, inline status change. That is the whole feature — no columns, no
 * drag, no WIP cap, no auto-archive.
 *
 * @var array<int,array<string,mixed>> $cards
 * @var array<int,string> $activeContexts
 * @var string $activeStatus
 * @var string $sort
 * @var int $totalCards
 */
$query = static function (array $overrides) use ($activeContexts, $activeStatus, $sort): string {
    $params = [];
    $contexts = $overrides['contexts'] ?? $activeContexts;
    if ($contexts !== []) {
        $params['context'] = implode(',', $contexts);
    }
    $status = $overrides['status'] ?? $activeStatus;
    if ($status !== '') {
        $params['status'] = $status;
    }
    $sortValue = $overrides['sort'] ?? $sort;
    if ($sortValue !== 'due') {
        $params['sort'] = $sortValue;
    }

    return $params === [] ? '/cards' : '/cards?' . http_build_query($params);
};

$today = (new DateTimeImmutable('now', new DateTimeZone(Clock::ZONE)))->format('Y-m-d');
?>
<main class="main main-wide">
  <div class="page-head">
    <h1 class="page-title">All work</h1>
    <a class="button" href="/cards/new">New card</a>
  </div>
  <p class="lede">
    <?= $totalCards === 0 ? 'No cards loaded yet.' : $totalCards . ' card' . ($totalCards === 1 ? '' : 's') . ' in total.' ?>
    Click a row to open it.
  </p>

  <div class="filters">
    <div class="chip-row">
      <a class="chip<?= $activeContexts === [] ? ' chip-on' : '' ?>" href="<?= $query(['contexts' => []]) ?>">All brands</a>
      <?php foreach (Contexts::ALL as $context):
        $on = in_array($context['key'], $activeContexts, true);
        // Chips toggle, so several brands can be on at once.
        $next = $on
            ? array_values(array_diff($activeContexts, [$context['key']]))
            : array_merge($activeContexts, [$context['key']]);
      ?>
        <a class="chip<?= $on ? ' chip-on' : '' ?>"
           style="--context-colour:<?= htmlspecialchars($context['colour'], ENT_QUOTES, 'UTF-8') ?>"
           href="<?= $query(['contexts' => $next]) ?>">
          <span class="chip-dot" aria-hidden="true"></span><?= htmlspecialchars($context['name'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="chip-row">
      <a class="chip<?= $activeStatus === '' ? ' chip-on' : '' ?>" href="<?= $query(['status' => '']) ?>">Any status</a>
      <a class="chip<?= $activeStatus === 'open' ? ' chip-on' : '' ?>" href="<?= $query(['status' => 'open']) ?>">Still open</a>
      <?php foreach (Cards::STATUSES as $status): ?>
        <a class="chip<?= $activeStatus === $status ? ' chip-on' : '' ?>" href="<?= $query(['status' => $status]) ?>">
          <?= Cards::STATUS_LABELS[$status] ?>
        </a>
      <?php endforeach; ?>
      <span class="chip-spacer"></span>
      <a class="chip<?= $sort === 'due' ? ' chip-on' : '' ?>" href="<?= $query(['sort' => 'due']) ?>">By due date</a>
      <a class="chip<?= $sort === 'title' ? ' chip-on' : '' ?>" href="<?= $query(['sort' => 'title']) ?>">By title</a>
    </div>
  </div>

  <?php if ($cards === []): ?>
    <p class="instruction">
      <?= $totalCards === 0
        ? 'Nothing loaded yet. Sam loads the three weeks of dated cards before he goes — or add one now.'
        : 'Nothing matches those filters. Clear them to see everything.' ?>
    </p>
  <?php else: ?>
    <div class="table-scroll">
      <table class="cards">
        <thead>
          <tr>
            <th scope="col">Title</th>
            <th scope="col">Stream</th>
            <th scope="col">Due</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cards as $card):
            $key = (string) $card['context_key'];
            $overdue = $card['status'] !== 'done' && (string) $card['due_date'] < $today;
          ?>
            <tr style="--context-colour:<?= htmlspecialchars(Contexts::colour($key), ENT_QUOTES, 'UTF-8') ?>">
              <td>
                <a class="card-link" href="/cards/<?= (int) $card['id'] ?>">
                  <span class="card-rule" aria-hidden="true"></span>
                  <span>
                    <span class="card-title"><?= htmlspecialchars((string) $card['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="card-context"><?= htmlspecialchars(Contexts::name($key), ENT_QUOTES, 'UTF-8') ?></span>
                  </span>
                </a>
              </td>
              <td class="cell-quiet">
                <?= Cards::STREAM_LABELS[(string) $card['stream']] ?? htmlspecialchars((string) $card['stream'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($card['publish_time'] !== null): ?>
                  <span class="meta"><?= htmlspecialchars((string) $card['publish_time'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
              </td>
              <td class="cell-quiet<?= $overdue ? ' cell-overdue' : '' ?>">
                <?= htmlspecialchars(Cards::shortDate((string) $card['due_date']), ENT_QUOTES, 'UTF-8') ?>
                <?= $overdue ? '<span class="meta">overdue</span>' : '' ?>
              </td>
              <td>
                <form method="post" action="/cards/<?= (int) $card['id'] ?>/status" class="status-form">
                  <input type="hidden" name="back" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/cards', ENT_QUOTES, 'UTF-8') ?>">
                  <label class="visually-hidden" for="status-<?= (int) $card['id'] ?>">Status</label>
                  <select id="status-<?= (int) $card['id'] ?>" name="status">
                    <?php foreach (Cards::STATUSES as $status): ?>
                      <option value="<?= $status ?>"<?= $card['status'] === $status ? ' selected' : '' ?>>
                        <?= Cards::STATUS_LABELS[$status] ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="button button-small">Set</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>
<script src="/assets/cards.js" defer></script>
