<?php

declare(strict_types=1);

/**
 * Seed data (BRIEF.md §11): "Ship a seed script so Sam can load the three weeks
 * quickly, and so the ordering logic can be tested against real dates before
 * departure."
 *
 *   php bin/seed.php --cover      three weeks of the real cover period
 *   php bin/seed.php --today      a day's worth around today, for checking the
 *                                 ordering without waiting for October
 *   php bin/seed.php --wipe       remove every card first
 *
 * This is example content in the right shape, not Sam's actual plan — it is
 * here so the ordering can be judged against real dates, and so a demo has
 * something in it. Sam replaces it with the real three weeks.
 *
 * Every card goes through Cards::validate, so the seed cannot create anything
 * the form would reject — including a Monday due date.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
$wipe = in_array('--wipe', $args, true);
$mode = in_array('--today', $args, true) ? 'today' : 'cover';

try {
    $config = Config::load(cover_config_path());
    $db = new Db($config->databasePath());
} catch (Throwable $e) {
    fwrite(STDERR, "Could not start: " . $e->getMessage() . "\n");
    exit(1);
}

$cards = new Cards($db);

if ($wipe) {
    $removed = $db->execute('DELETE FROM cards');
    echo "Removed {$removed} existing card(s).\n";
}

/** Working days only: never a Monday, and never a weekend. */
function cover_next_working_day(DateTimeImmutable $from, int $skip = 1): DateTimeImmutable
{
    $day = $from;
    while ($skip > 0) {
        $day = $day->modify('+1 day');
        $weekday = (int) $day->format('N');
        if ($weekday !== 1 && $weekday <= 5) {
            $skip--;
        }
    }

    return $day;
}

$zone = new DateTimeZone(Clock::ZONE);

if ($mode === 'cover') {
    // Wednesday 7 October 2026, the first day of cover.
    $start = new DateTimeImmutable('2026-10-07', $zone);
    $label = 'the cover period';
} else {
    $today = new DateTimeImmutable('today', $zone);
    // Start a couple of working days back so there is something overdue to order.
    $start = $today->modify('-4 days');
    $label = 'today';
}

/**
 * The shape of a normal week: social posts with publish times, an email, and
 * slower project work with no time on it.
 *
 * @var array<int,array{0:string,1:string,2:string,3:?string,4:?string,5:string}>
 */
$template = [
    ['Sterling POS — retail display case study', 'sterling-pos', 'social', '09:30', 'LinkedIn', 'high'],
    ['Swytch Graphics — rollout photography post', 'swytch-graphics', 'social', '11:30', 'LinkedIn', 'normal'],
    ['Anchorprint — booklet turnaround post', 'anchorprint', 'social', '14:00', 'Facebook', 'normal'],
    ['Image Pro — Gongzheng consumables note', 'image-pro-systems', 'social', '10:00', 'LinkedIn', 'normal'],
    ['Sterling POS — monthly customer email', 'sterling-pos', 'email', '08:00', 'Email', 'high'],
    ['Swytch Graphics — sample pack reprint', 'swytch-graphics', 'physical', null, null, 'normal'],
    ['Premises — photograph the Sterling office exterior', 'premises', 'premises', null, null, 'normal'],
    ['Anchorprint — proof the menu reprint', 'anchorprint', 'physical', null, null, 'low'],
    ['Image Pro — stand graphics for the open day', 'image-pro-systems', 'physical', null, null, 'normal'],
    ['Premises — chase the Etchells sign quote', 'premises', 'premises', null, null, 'high'],
];

$created = 0;
$rejected = 0;
$day = $start->modify('-1 day');

for ($i = 0; $i < count($template) * 2; $i++) {
    $spec = $template[$i % count($template)];
    // Two or three cards per working day, so a day view has something to order.
    if ($i % 3 === 0) {
        $day = cover_next_working_day($day);
    }

    $result = Cards::validate([
        'title' => $spec[0],
        'context_key' => $spec[1],
        'stream' => $spec[2],
        'due_date' => $day->format('Y-m-d'),
        'publish_time' => $spec[3],
        'channel' => $spec[4],
        'priority' => $spec[5],
        'status' => 'not_started',
        'notes' => 'Seed data. Replace with the real card before the trial week.',
    ]);

    if ($result['errors'] !== []) {
        $rejected++;
        fwrite(STDERR, 'Skipped "' . $spec[0] . '": ' . implode(' ', $result['errors']) . "\n");
        continue;
    }

    $cards->create($result['values']);
    $created++;
}

echo "Seeded {$created} card(s) around {$label}";
echo $rejected > 0 ? ", {$rejected} rejected by validation.\n" : ".\n";
echo "First due " . $start->format('D j M Y') . ", last due " . $day->format('D j M Y') . ".\n";
echo "These are examples in the right shape, not the real plan. Replace before the trial week.\n";
