<?php
/**
 * The day view (BRIEF.md §5). The screen the app opens on.
 *
 * She does not build this list. It is generated on load, capped at six, and
 * every item says why it is there — that line is what teaches her how the
 * ordering works over twelve days.
 *
 * @var DateTimeImmutable $today
 * @var string $isoDate
 * @var bool $isMonday
 * @var bool $isFriday
 * @var array<int,array{card:array<string,mixed>,reason:string,rule:int}> $items
 * @var array<int,array<string,mixed>> $completed
 * @var array<int,array<string,mixed>> $notMoved
 * @var array<string,mixed>|null $closeout
 * @var bool $quietDay
 * @var int $totalCards
 */
$back = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8');
?>
<main class="main">
  <div class="page-head">
    <h1 class="page-title"><?= htmlspecialchars($today->format('l j F'), ENT_QUOTES, 'UTF-8') ?></h1>
    <a class="button" href="/cards">All work</a>
  </div>

  <?php if ($isMonday): ?>
    <p class="notice">
      Mondays are not Swytch marketing days, so nothing is scheduled for today. Anything below is
      overdue from last week and worth a look if you are in.
    </p>
  <?php endif; ?>

  <?php if (isset($_GET['closed'])): ?>
    <p class="notice">Day closed out. It is in the log for Sam.</p>
  <?php endif; ?>

  <?php if ($items === []): ?>
    <p class="instruction">
      <?php if ($totalCards === 0): ?>
        Nothing is loaded yet. Sam loads the three weeks of dated cards before he goes —
        <a href="/cards/new">or add one now</a>.
      <?php else: ?>
        Nothing is due and nothing is open. Everything loaded is done.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p class="lede">
      <?php if ($quietDay): ?>
        Nothing publishes today and nothing is due. Pick up the project work below.
      <?php else: ?>
        <?= count($items) ?> thing<?= count($items) === 1 ? '' : 's' ?> for today, in the order to do them.
      <?php endif; ?>
      Everything else is in <a href="/cards">the list</a>.
    </p>

    <ol class="plan">
      <?php foreach ($items as $index => $item):
        $card = $item['card'];
        $key = (string) $card['context_key'];
      ?>
        <li class="plan-item" style="--context-colour:<?= htmlspecialchars(Contexts::colour($key), ENT_QUOTES, 'UTF-8') ?>">
          <span class="plan-rule" aria-hidden="true"></span>
          <div class="plan-body">
            <p class="plan-title">
              <a href="/cards/<?= (int) $card['id'] ?>"><?= htmlspecialchars((string) $card['title'], ENT_QUOTES, 'UTF-8') ?></a>
            </p>
            <p class="plan-why">
              <?= htmlspecialchars($item['reason'], ENT_QUOTES, 'UTF-8') ?>
              <span class="meta">· <?= htmlspecialchars(Contexts::name($key), ENT_QUOTES, 'UTF-8') ?></span>
            </p>
          </div>
          <form method="post" action="/cards/<?= (int) $card['id'] ?>/status" class="status-form plan-status">
            <input type="hidden" name="back" value="<?= $back ?>">
            <label class="visually-hidden" for="plan-status-<?= (int) $card['id'] ?>">Status</label>
            <select id="plan-status-<?= (int) $card['id'] ?>" name="status">
              <?php foreach (Cards::STATUSES as $status): ?>
                <option value="<?= $status ?>"<?= $card['status'] === $status ? ' selected' : '' ?>>
                  <?= Cards::STATUS_LABELS[$status] ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-small">Set</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <!-- End of day (§5). Prompted, never forced, never blocking. -->
  <section class="closeout">
    <h2 class="section-title">End of day</h2>

    <?php if ($closeout !== null): ?>
      <p class="lede">Closed out at <?= htmlspecialchars(Clock::forHumans((string) $closeout['created_at']), ENT_QUOTES, 'UTF-8') ?>. It is in <a href="/log">the log</a>.</p>
      <div class="log-field">
        <p class="log-field-label">What moved</p>
        <p><?= nl2br(htmlspecialchars((string) $closeout['moved'], ENT_QUOTES, 'UTF-8')) ?></p>
      </div>
      <div class="log-field">
        <p class="log-field-label">What did not</p>
        <p><?= nl2br(htmlspecialchars((string) $closeout['not_moved'], ENT_QUOTES, 'UTF-8')) ?></p>
      </div>
      <?php if ($closeout['note'] !== null && $closeout['note'] !== ''): ?>
        <div class="log-field">
          <p class="log-field-label">Your note</p>
          <p><?= nl2br(htmlspecialchars((string) $closeout['note'], ENT_QUOTES, 'UTF-8')) ?></p>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="closeout-columns">
        <div>
          <p class="log-field-label">What moved</p>
          <?php if ($completed === []): ?>
            <p class="cell-quiet">Nothing marked done yet.</p>
          <?php else: ?>
            <ul class="plain-list">
              <?php foreach ($completed as $card): ?>
                <li><?= htmlspecialchars((string) $card['title'], ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div>
          <p class="log-field-label">What did not</p>
          <?php if ($notMoved === []): ?>
            <p class="cell-quiet">Nothing left open from today's plan.</p>
          <?php else: ?>
            <ul class="plain-list">
              <?php foreach ($notMoved as $card): ?>
                <li><?= htmlspecialchars((string) $card['title'], ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" action="/closeout" class="closeout-form">
        <label class="field-label" for="closeout-note">Anything worth saying. Optional.</label>
        <textarea id="closeout-note" name="note" rows="2" placeholder="Factory was busy — premises photos moved to tomorrow."></textarea>
        <div class="composer-row" style="margin-top:0.75rem">
          <button type="submit" class="button button-primary">Close out the day</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($isFriday): ?>
      <p class="closeout-friday">
        It is Friday. <a href="/api/cards.csv" download>Download the cards as a CSV</a> — that file is
        the backup if anything happens to the app.
      </p>
    <?php endif; ?>
  </section>
</main>
<script src="/assets/cards.js" defer></script>
