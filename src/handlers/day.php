<?php

declare(strict_types=1);

/**
 * The day view (BRIEF.md §5) and the end-of-day close-out.
 *
 * The plan is generated on load, so there is nothing stored to go stale and no
 * scheduled job that can silently fail to run overnight.
 */

function cover_day(App $app): void
{
    $today = (new DateTimeImmutable('now', new DateTimeZone(Clock::ZONE)));
    $isoDate = $today->format('Y-m-d');

    $plan = new Plan($app->db());
    $items = $plan->forDay($isoDate);

    $planIds = [];
    foreach ($items as $item) {
        $planIds[(int) $item['card']['id']] = true;
    }

    $completed = $plan->completedOn($isoDate);
    $notMoved = [];
    foreach ($items as $item) {
        if ($item['card']['status'] !== 'done') {
            $notMoved[] = $item['card'];
        }
    }

    $app->render('day', [
        'title' => 'Today — Marketing cover',
        'today' => $today,
        'isoDate' => $isoDate,
        'isMonday' => (int) $today->format('N') === 1,
        'isFriday' => (int) $today->format('N') === 5,
        'items' => $items,
        'completed' => $completed,
        'notMoved' => $notMoved,
        'closeout' => $app->logBook()->closeoutFor($isoDate),
        // §12 wants the quiet day to read as an instruction. Rule 3 always
        // supplies the next project item, so a plan that contains nothing but
        // that item is the quiet day — and it is labelled as one.
        'quietDay' => $items !== [] && !array_filter($items, static fn(array $i): bool => $i['rule'] < 3),
        'totalCards' => $app->cards()->countAll(),
    ]);
}

/**
 * "Two taps: what moved, what did not, one optional note."
 *
 * What moved and what did not are recomputed here rather than taken from the
 * form, so the entry records what the database actually says at the moment she
 * closes the day.
 */
function cover_closeout(App $app): void
{
    $today = (new DateTimeImmutable('now', new DateTimeZone(Clock::ZONE)));
    $isoDate = $today->format('Y-m-d');

    if ($app->logBook()->closeoutFor($isoDate) !== null) {
        // Already closed out. Nothing in the log is ever edited, so a second
        // press does nothing rather than appending a duplicate.
        App::redirect('/');
    }

    $plan = new Plan($app->db());
    $items = $plan->forDay($isoDate);

    $moved = [];
    foreach ($plan->completedOn($isoDate) as $card) {
        $moved[] = cover_card_line($card);
    }

    $notMoved = [];
    foreach ($items as $item) {
        if ($item['card']['status'] !== 'done') {
            $notMoved[] = cover_card_line($item['card']);
        }
    }

    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
    if (mb_strlen_safe($note) > 2000) {
        $note = '';
    }

    $app->logBook()->addCloseout(
        $isoDate,
        $moved === [] ? 'Nothing was marked done.' : implode("\n", $moved),
        $notMoved === [] ? 'Nothing was left open.' : implode("\n", $notMoved),
        $note === '' ? null : $note
    );

    App::redirect('/?closed=1');
}

/** @param array<string,mixed> $card */
function cover_card_line(array $card): string
{
    return Contexts::name((string) $card['context_key']) . ' — ' . (string) $card['title'];
}
