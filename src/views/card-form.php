<?php
/**
 * The card form (BRIEF.md §3, §13 phase 2). Required fields are enforced and
 * the Monday rejection states its reason next to the date, not in a banner.
 *
 * @var array<string,mixed>|null $card      the saved card, when editing
 * @var array<string,mixed> $values         what to put in the boxes
 * @var array<string,string> $errors        field name => reason
 */
$isNew = $card === null;
$action = $isNew ? '/cards' : '/cards/' . (int) $card['id'];
$saved = isset($_GET['saved']);

$field = static function (string $name) use ($values): string {
    return htmlspecialchars((string) ($values[$name] ?? ''), ENT_QUOTES, 'UTF-8');
};
$problem = static function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? '<p class="field-error">' . htmlspecialchars($errors[$name], ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
};
?>
<main class="main">
  <h1 class="page-title"><?= $isNew ? 'New card' : 'Card' ?></h1>

  <?php if ($saved): ?>
    <p class="notice">Saved.</p>
  <?php endif; ?>
  <?php if ($errors !== []): ?>
    <p class="notice">Nothing has been saved yet. There <?= count($errors) === 1 ? 'is one thing' : 'are ' . count($errors) . ' things' ?> to fix below.</p>
  <?php endif; ?>

  <form method="post" action="<?= $action ?>" class="card-form">

    <div class="field">
      <label class="field-label" for="title">Title <span class="field-required">required</span></label>
      <input id="title" name="title" type="text" value="<?= $field('title') ?>" maxlength="200" autofocus>
      <?= $problem('title') ?>
    </div>

    <div class="field">
      <label class="field-label" for="context_key">Context <span class="field-required">required</span></label>
      <select id="context_key" name="context_key">
        <option value="">Pick one</option>
        <?php foreach (Contexts::ALL as $context): ?>
          <option value="<?= htmlspecialchars($context['key'], ENT_QUOTES, 'UTF-8') ?>"
            <?= ($values['context_key'] ?? '') === $context['key'] ? ' selected' : '' ?>>
            <?= htmlspecialchars($context['name'], ENT_QUOTES, 'UTF-8') ?><?= $context['internal'] ? ' (internal)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?= $problem('context_key') ?>
    </div>

    <div class="field">
      <label class="field-label" for="stream">Stream <span class="field-required">required</span></label>
      <select id="stream" name="stream">
        <option value="">Pick one</option>
        <?php foreach (Cards::STREAMS as $stream): ?>
          <option value="<?= $stream ?>"<?= ($values['stream'] ?? '') === $stream ? ' selected' : '' ?>>
            <?= Cards::STREAM_LABELS[$stream] ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?= $problem('stream') ?>
    </div>

    <div class="field-row">
      <div class="field">
        <label class="field-label" for="due_date">Due date <span class="field-required">required</span></label>
        <input id="due_date" name="due_date" type="date" value="<?= $field('due_date') ?>">
        <?= $problem('due_date') ?>
      </div>

      <div class="field">
        <label class="field-label" for="publish_time">Publish time</label>
        <input id="publish_time" name="publish_time" type="time" value="<?= $field('publish_time') ?>">
        <p class="field-hint">Social and email only. Leave blank for anything else.</p>
        <?= $problem('publish_time') ?>
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label class="field-label" for="channel">Channel</label>
        <select id="channel" name="channel">
          <option value="">None</option>
          <?php foreach (Cards::CHANNELS as $channel): ?>
            <option value="<?= $channel ?>"<?= ($values['channel'] ?? '') === $channel ? ' selected' : '' ?>><?= $channel ?></option>
          <?php endforeach; ?>
        </select>
        <?= $problem('channel') ?>
      </div>

      <div class="field">
        <label class="field-label" for="priority">Priority</label>
        <select id="priority" name="priority">
          <?php foreach (Cards::PRIORITIES as $priority): ?>
            <option value="<?= $priority ?>"<?= ($values['priority'] ?? 'normal') === $priority ? ' selected' : '' ?>>
              <?= Cards::PRIORITY_LABELS[$priority] ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label class="field-label" for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (Cards::STATUSES as $status): ?>
            <option value="<?= $status ?>"<?= ($values['status'] ?? 'not_started') === $status ? ' selected' : '' ?>>
              <?= Cards::STATUS_LABELS[$status] ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label class="field-label" for="asana_url">Asana link</label>
      <input id="asana_url" name="asana_url" type="url" value="<?= $field('asana_url') ?>" placeholder="https://app.asana.com/...">
      <p class="field-hint">Pasted at setup. The app renders it as a link and does nothing else with it.</p>
      <?= $problem('asana_url') ?>
    </div>

    <div class="field">
      <label class="field-label" for="notes">Notes</label>
      <textarea id="notes" name="notes" rows="5"><?= $field('notes') ?></textarea>
      <p class="field-hint">Markdown. Shown on the card and nowhere else.</p>
    </div>

    <div class="composer-row" style="margin-top:1.25rem">
      <button type="submit" class="button button-primary"><?= $isNew ? 'Create card' : 'Save changes' ?></button>
      <a class="button" href="/cards">Back to the list</a>
    </div>
  </form>

  <?php if (!$isNew): ?>
    <div class="card-meta">
      <?php if ($card['asana_url'] !== null && $card['asana_url'] !== ''): ?>
        <p><a href="<?= htmlspecialchars((string) $card['asana_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Open in Asana</a></p>
      <?php endif; ?>
      <?php if ($card['notes'] !== null && $card['notes'] !== ''): ?>
        <div class="log-field">
          <p class="log-field-label">Notes</p>
          <div class="prose"><?= Markdown::toHtml((string) $card['notes']) ?></div>
        </div>
      <?php endif; ?>
      <p class="meta">
        Added <?= htmlspecialchars(Clock::forHumans((string) $card['created_at']), ENT_QUOTES, 'UTF-8') ?><?php
        if ($card['completed_at'] !== null): ?> · done <?= htmlspecialchars(Clock::forHumans((string) $card['completed_at']), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
      </p>
      <p class="meta">
        <a href="/assistant?context=<?= htmlspecialchars((string) $card['context_key'], ENT_QUOTES, 'UTF-8') ?>">Ask the assistant about <?= htmlspecialchars(Contexts::name((string) $card['context_key']), ENT_QUOTES, 'UTF-8') ?></a>
      </p>
    </div>
  <?php endif; ?>
</main>
