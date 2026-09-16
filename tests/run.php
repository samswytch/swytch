<?php

declare(strict_types=1);

/**
 * The test suite.  php tests/run.php        full output
 *                  php tests/run.php -q     one summary line, for mutation runs
 *
 * Needs no configuration and no server: it builds its own throwaway database
 * from db/schema.sql and exercises the logic directly.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/harness.php';

$t = new Tests(in_array('-q', $argv, true));
$db = test_db();
$zone = new DateTimeZone(Clock::ZONE);
$today = new DateTimeImmutable('today', $zone);
$iso = static fn(int $o): string => $today->modify(($o >= 0 ? '+' : '') . $o . ' days')->format('Y-m-d');

// ---------------------------------------------------------------- Cards ----

$t->group('Cards::validate — required fields (§3)');
$empty = Cards::validate([]);
$t->contains($empty['errors']['title'] ?? '', 'short title', 'title is required');
$t->contains($empty['errors']['context_key'] ?? '', 'which brand', 'context is required');
$t->contains($empty['errors']['stream'] ?? '', 'a stream', 'stream is required');
$t->contains($empty['errors']['due_date'] ?? '', 'due date', 'due date is required');

$good = Cards::validate([
    'title' => 'A card', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => $iso(2),
]);
$t->is($good['errors'], [], 'a complete card validates');
$t->is($good['values']['status'], 'not_started', 'status defaults to not_started');
$t->is($good['values']['priority'], 'normal', 'priority defaults to normal');

$t->group('Cards::validate — the Monday rule (§5)');
$monday = new DateTimeImmutable('2026-10-12', $zone);
$t->is((int) $monday->format('N'), 1, 'the fixture date really is a Monday');
$mon = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => '2026-10-12']);
$t->contains($mon['errors']['due_date'] ?? '', 'does not work on Swytch marketing on Mondays', 'a Monday is refused');
$t->contains($mon['errors']['due_date'] ?? '', 'Friday 9 October', 'the Friday before is offered');
$t->contains($mon['errors']['due_date'] ?? '', 'Tuesday 13 October', 'the Tuesday after is offered');
$tue = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => '2026-10-13']);
$t->is($tue['errors'], [], 'the Tuesday after is accepted');

$t->group('Cards::validate — the rest');
$pt = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => $iso(2), 'publish_time' => '11:30']);
$t->contains($pt['errors']['publish_time'] ?? '', 'social and email', 'a publish time is refused on physical work');
$ptok = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'social', 'due_date' => $iso(2), 'publish_time' => '11:30']);
$t->is($ptok['errors'], [], 'a publish time is fine on social');
$badtime = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'social', 'due_date' => $iso(2), 'publish_time' => '25:00']);
$t->contains($badtime['errors']['publish_time'] ?? '', '24-hour time', 'an impossible time is refused');
$rolled = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => '2026-02-31']);
$t->contains($rolled['errors']['due_date'] ?? '', 'due date', '31 February is refused rather than rolled over');
$url = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => $iso(2), 'asana_url' => 'notalink']);
$t->contains($url['errors']['asana_url'] ?? '', 'does not look like a link', 'a non-URL Asana link is refused');
$chan = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'social', 'due_date' => $iso(2), 'channel' => 'TikTok']);
$t->contains($chan['errors']['channel'] ?? '', 'four channels', 'a channel outside §3 is refused');
$ctx = Cards::validate(['title' => 'x', 'context_key' => 'not-a-brand', 'stream' => 'physical', 'due_date' => $iso(2)]);
$t->contains($ctx['errors']['context_key'] ?? '', 'which brand', 'an unknown context is refused');
$junk = Cards::validate(['title' => 'x', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => $iso(2), 'status' => 'wat', 'priority' => 'urgent']);
$t->is($junk['values']['status'], 'not_started', 'an unknown status falls back rather than being stored');
$t->is($junk['values']['priority'], 'normal', 'an unknown priority falls back');

$t->group('Cards — completed_at lifecycle');
test_reset($db);
$cards = new Cards($db);
$id = $cards->create(Cards::validate(['title' => 'c', 'context_key' => 'premises', 'stream' => 'physical', 'due_date' => $iso(1)])['values']);
$t->is($cards->find($id)['completed_at'], null, 'a new card has no completion time');
$cards->setStatus($id, 'done');
$t->ok($cards->find($id)['completed_at'] !== null, 'marking done sets it');

// Backdated, because two calls in the same second would look identical whether
// or not the value was being rewritten.
$backdated = '2026-01-02 03:04:05';
$db->execute('UPDATE cards SET completed_at = ? WHERE id = ?', [$backdated, $id]);
$cards->setStatus($id, 'done');
$t->is($cards->find($id)['completed_at'], $backdated, 'marking done again does not rewrite it');
$cards->update($id, Cards::validate(['title' => 'c', 'context_key' => 'premises', 'stream' => 'physical',
    'due_date' => $iso(1), 'status' => 'done'])['values']);
$t->is($cards->find($id)['completed_at'], $backdated, 'nor does saving the card again');
$cards->setStatus($id, 'in_progress');
$t->is($cards->find($id)['completed_at'], null, 'reopening clears it');
$t->is($cards->setStatus($id, 'nonsense'), false, 'an unknown status is refused');
$t->is($cards->find($id)['status'], 'in_progress', 'and leaves the card alone');

// ----------------------------------------------------------------- Plan ----

$t->group('Plan — the ordering rules (§5)');
test_reset($db);
$make = static function (array $o) use ($cards): int {
    $r = Cards::validate($o + ['context_key' => 'premises', 'stream' => 'social', 'status' => 'not_started', 'priority' => 'normal']);
    $r['values']['due_date'] = $o['due_date']; // the Monday rule is a form rule
    return $cards->create($r['values']);
};
$make(['title' => 'pm post', 'due_date' => $iso(0), 'publish_time' => '14:00']);
$make(['title' => 'am email', 'due_date' => $iso(0), 'publish_time' => '08:00', 'stream' => 'email']);
$make(['title' => 'already out', 'due_date' => $iso(0), 'publish_time' => '09:00', 'status' => 'done']);
$make(['title' => 'started, due today', 'due_date' => $iso(0), 'status' => 'in_progress']);
$make(['title' => 'overdue', 'due_date' => $iso(-3)]);
$make(['title' => 'due in two', 'due_date' => $iso(2)]);
$make(['title' => 'due in three', 'due_date' => $iso(3)]);
$make(['title' => 'done and overdue', 'due_date' => $iso(-5), 'status' => 'done']);
$make(['title' => 'project work', 'due_date' => $iso(30), 'stream' => 'premises']);

$plan = new Plan($db);
$items = $plan->forDay($today->format('Y-m-d'));
$titles = array_map(static fn(array $i): string => (string) $i['card']['title'], $items);
$rules = array_map(static fn(array $i): int => $i['rule'], $items);
$reasons = [];
foreach ($items as $i) {
    $reasons[(string) $i['card']['title']] = $i['reason'];
}

$t->is(array_slice($titles, 0, 2), ['am email', 'pm post'], 'rule 1 puts publish times in time order');
$t->is($rules[0], 1, 'and marks them rule 1');
$t->ok(!in_array('already out', $titles, true), 'a published card is not repeated');
$t->ok(in_array('started, due today', $titles, true), 'work in progress due today is on the plan');
$t->ok(in_array('overdue', $titles, true), 'overdue work is on the plan');
$t->ok(in_array('due in two', $titles, true), 'work due inside two days is on the plan');
$t->ok(!in_array('due in three', $titles, true), 'work due in three days is not');
$t->ok(!in_array('done and overdue', $titles, true), 'done work is never on the plan');
$t->ok(in_array('project work', $titles, true), 'rule 3 pulls in the next project item');
$t->is($rules[count($rules) - 1], 3, 'and it comes last');

$t->group('Plan — the why-it-is-here line (§5)');
$t->is($reasons['am email'] ?? '', 'publishes at 08:00', 'a publish time says when');
$t->contains($reasons['overdue'] ?? '', 'overdue, was due', 'overdue work says so');
$t->is($reasons['started, due today'] ?? '', 'due today, in progress', 'status is named');
$t->contains($reasons['due in two'] ?? '', 'not started', 'so is not-started');
$t->is($reasons['project work'] ?? '', 'oldest open item', 'project work says why it is there');
$t->is(count($items), count(array_filter($items, static fn(array $i): bool => $i['reason'] !== '')), 'every item has a reason');

$t->group('Plan — the cap of six');
test_reset($db);
for ($i = 1; $i <= 20; $i++) {
    $make(['title' => "card {$i}", 'due_date' => $iso(-1)]);
}
// Asserted as the literal six from §5, not as Plan::CAP — comparing the result
// against the constant that produced it would follow the constant anywhere.
$t->is(count((new Plan($db))->forDay($today->format('Y-m-d'))), 6, 'the plan stops at six');
$t->is(Plan::CAP, 6, 'and the cap is still the six the brief asks for');

$t->group('Plan — rule 3 contributes exactly one item');
test_reset($db);
// Room to spare under the cap, and three open project cards. Rule 3 says "the
// next open item", singular, so exactly one of them may appear.
$make(['title' => 'one overdue thing', 'due_date' => $iso(-1)]);
$make(['title' => 'project A', 'due_date' => $iso(20), 'stream' => 'premises']);
$make(['title' => 'project B', 'due_date' => $iso(21), 'stream' => 'physical']);
$make(['title' => 'project C', 'due_date' => $iso(22), 'stream' => 'premises']);
$three = (new Plan($db))->forDay($today->format('Y-m-d'));
$byRule3 = array_values(array_filter($three, static fn(array $i): bool => $i['rule'] === 3));
$t->is(count($byRule3), 1, 'exactly one item comes from rule 3');
$t->is((string) $byRule3[0]['card']['title'], 'project A', 'and it is the oldest open one');
$t->is(count($three), 2, 'so the plan is the overdue card plus one project card');

$t->group('Plan — rule 3 skips what the plan already has');
test_reset($db);
// The only open project item is also the only overdue item, so rule 2 takes it
// first. Rule 3 must not then produce a duplicate or an empty slot.
$make(['title' => 'the one project card', 'due_date' => $iso(-1), 'stream' => 'premises']);
$one = (new Plan($db))->forDay($today->format('Y-m-d'));
$t->is(count($one), 1, 'one card produces one item, not two');
$t->is($one[0]['rule'], 2, 'claimed by rule 2');

$t->group('Plan — what moved, across the London/UTC boundary');
test_reset($db);
$utc = new DateTimeZone('UTC');
$todayCard = $make(['title' => 'finished today', 'due_date' => $iso(0)]);
$cards->setStatus($todayCard, 'done');

// A card finished at five to midnight London yesterday. In summer that is
// stored as 22:55 UTC on yesterday's date; a range that is off by a day in
// either direction puts it on the wrong side of the boundary.
$yesterdayCard = $make(['title' => 'finished yesterday', 'due_date' => $iso(-1)]);
$lateYesterday = $today->modify('-1 day')->setTime(23, 55)->setTimezone($utc)->format(Clock::STORED);
$db->execute('UPDATE cards SET status = ?, completed_at = ? WHERE id = ?', ['done', $lateYesterday, $yesterdayCard]);

$movedToday = (new Plan($db))->completedOn($today->format('Y-m-d'));
$movedYesterday = (new Plan($db))->completedOn($today->modify('-1 day')->format('Y-m-d'));
$titles = static fn(array $rows): array => array_map(static fn(array $c): string => (string) $c['title'], $rows);
$t->is($titles($movedToday), ['finished today'], 'today lists only what was finished today');
$t->is($titles($movedYesterday), ['finished yesterday'], 'yesterday lists only what was finished then');

// And once more in winter, when London is UTC and the arithmetic differs.
test_reset($db);
$winter = new DateTimeImmutable('2026-12-15', $zone);
$winterCard = $make(['title' => 'finished in December', 'due_date' => '2026-12-15']);
$db->execute('UPDATE cards SET status = ?, completed_at = ? WHERE id = ?',
    ['done', $winter->setTime(23, 55)->setTimezone($utc)->format(Clock::STORED), $winterCard]);
$t->is(count((new Plan($db))->completedOn('2026-12-15')), 1, 'late on a winter evening still counts as that day');
$t->is(count((new Plan($db))->completedOn('2026-12-16')), 0, 'and not as the next one');

// ------------------------------------------------------------- Outcomes ----

$t->group('Outcomes — the marker (§6)');
$r = Outcomes::extract("Fine to run.\n\n<<OUTCOME: go_ahead_logged>>");
$t->is($r['outcome'], 'go_ahead_logged', 'the marker is read');
$t->lacks($r['text'], '<<OUTCOME', 'and stripped from the text');
$t->is($r['text'], 'Fine to run.', 'leaving the answer intact');
$t->is(Outcomes::extract('No marker here.')['outcome'], 'go_ahead', 'a missing marker means go ahead, not park');
$t->is(Outcomes::extract("a <<OUTCOME: park>> b <<OUTCOME: ask_kev>>")['outcome'], 'ask_kev', 'the last marker wins');
$t->lacks(Outcomes::extract("a <<OUTCOME: park>> b <<OUTCOME: ask_kev>>")['text'], 'OUTCOME', 'every marker is stripped');
$t->is(Outcomes::logKind('go_ahead'), null, 'tier 1 is not logged');
$t->is(Outcomes::logKind('go_ahead_logged'), 'decision', 'tier 2 logs a decision');
$t->is(Outcomes::logKind('ask_kev'), 'ask_kev', 'tier 3 logs a question for Kev');
$t->is(Outcomes::logKind('park'), 'parked', 'tier 4 logs a parked item');

$t->group('Prompt — the card block (§6)');
test_reset($db);
$promptCards = new Cards($db);
$cardId = $promptCards->create(Cards::validate([
    'title' => 'Anchorprint menu reprint', 'context_key' => 'anchorprint', 'stream' => 'social',
    'due_date' => $iso(1), 'publish_time' => '14:00', 'channel' => 'Facebook',
    'notes' => 'Check the bleed.', 'asana_url' => 'https://app.asana.com/0/1/2',
])['values']);
$theCard = $promptCards->find($cardId);
$prompt = new Prompt(new Content(APP_ROOT . '/content'));
$anchor = Contexts::find('anchorprint');

$without = $prompt->systemBlocks($anchor);
$with = $prompt->systemBlocks($anchor, $theCard);
$t->is(count($without), 4, 'without a card the prompt is four blocks');
$t->is(count($with), 5, 'with a card it is five');

$cardBlock = $with[3]['text'];
$t->contains($cardBlock, 'The card she has open', 'the card block is labelled');
$t->contains($cardBlock, 'Anchorprint menu reprint', 'it names the card');
$t->contains($cardBlock, 'Publishes at: 14:00', 'it carries the publish time');
$t->contains($cardBlock, 'Check the bleed.', 'it carries her notes');
$t->lacks($cardBlock, 'asana.com', 'it leaves out the Asana link, which the model cannot follow');
$t->is(isset($with[3]['cache_control']), false, 'the card sits after the cache breakpoint');
$t->is(isset($with[2]['cache_control']), true, 'which is still on the brand pack');
$t->contains($with[2]['text'], 'Brand pack: Anchorprint', 'and the pack is the card\'s own brand');

// ------------------------------------------------------------- Markdown ----

$t->group('Markdown — model output can never become markup');
$html = Markdown::toHtml('A <script>alert(1)</script> and **bold** and `code`.');
$t->lacks($html, '<script>', 'a script tag is escaped');
$t->contains($html, '&lt;script&gt;', 'and shown as text');
$t->contains($html, '<strong>bold</strong>', 'bold still renders');
$t->contains($html, '<code>code</code>', 'code still renders');
$t->contains(Markdown::toHtml('[x](https://a.test/b)'), 'href="https://a.test/b"', 'a web link renders');
$t->contains(Markdown::toHtml('[x](mailto:a@b.test)'), 'href="mailto:a@b.test"', 'a mail link renders');
// What matters is that nothing but http(s) and mailto ever becomes an anchor.
// A rejected scheme may still appear as escaped text — that is harmless, and
// asserting on the substring alone would be asserting the wrong thing.
foreach (['[x](javascript:alert1)', '[x](JaVaScRiPt:alert1)', '[x](data:text/html,<script>)', '[x](javascript:alert(1))'] as $nasty) {
    $t->lacks(Markdown::toHtml($nasty), '<a ', 'no anchor from ' . $nasty);
}
$t->lacks(Markdown::toHtml('<img src=x onerror=alert(1)>'), '<img', 'raw HTML is escaped');
// A quote inside a URL must be escaped into the attribute, not end it. If the
// href escaping ever lost ENT_QUOTES this would render as a live event handler.
$breakout = Markdown::toHtml('[a](https://x.test"onmouseover=alert1)');
$t->lacks($breakout, '"onmouseover=', 'a quote in an href cannot start a new attribute');
$t->contains($breakout, '&quot;onmouseover', 'it is escaped into the href instead');

// ------------------------------------------------------------------ Csv ----

$t->group('Csv');
$csv = Csv::build(['a', 'b'], [['=1+1', 'say "hi"']]);
$t->contains($csv, "\u{FEFF}", 'starts with a byte order mark for Excel');
$t->contains($csv, '"\'=1+1"', 'a leading = is defused');
$t->contains($csv, '"say ""hi"""', 'quotes are doubled');

// ---------------------------------------------------------------- Clock ----

$t->group('Clock — UTC storage, London days');
$t->is(Clock::forCsv('2026-07-15 22:30:00'), '2026-07-15 23:30', 'summer time shows as London');
$t->is(Clock::forCsv('2026-12-15 22:30:00'), '2026-12-15 22:30', 'winter time is UTC');
$t->is(Clock::forHumans(null), '', 'a missing timestamp is blank, not an epoch');

// -------------------------------------------------------------- LogBook ----

$t->group('LogBook — append-only (§9)');
test_reset($db);
$log = new LogBook($db);
$entryId = $log->add('decision', 'premises', 'q', 'a');
$t->is($log->addNote($entryId, 'first'), 'saved', 'a note can be added once');
$t->is($log->addNote($entryId, 'second'), 'already_noted', 'but not twice');
$t->is($log->addNote(999999, 'x'), 'not_found', 'and not to an entry that is not there');
$rows = $log->all();
$t->is($rows[0]['note'], 'first', 'the first note is the one kept');
foreach (['decision', 'parked', 'ask_kev'] as $kind) {
    $log->add($kind, 'premises', 'q', 'a');
}
$log->addCloseout($today->format('Y-m-d'), 'moved', 'not moved', 'note');
$t->is(count($log->all()), 5, 'all four kinds are accepted');
$t->ok($log->closeoutFor($today->format('Y-m-d')) !== null, 'today has a close-out');
$t->is($log->closeoutFor($today->modify('+1 day')->format('Y-m-d')), null, 'tomorrow does not');
$t->is(LogBook::noteLabel('ask_kev'), 'What Kev said', 'an ask-Kev entry asks what Kev said');

// ---------------------------------------------------------------- Usage ----

$t->group('Usage — the caps (§11)');
test_reset($db);
$capConfig = Config::load(getenv('COVER_CONFIG'));
$usage = new Usage($db, $capConfig);
$t->is($usage->checkAndRecord('conv-a')['ok'], true, 'the first message is allowed');
for ($i = 0; $i < $capConfig->sessionMessageCap(); $i++) {
    $usage->checkAndRecord('conv-b');
}
$hit = $usage->checkAndRecord('conv-b');
$t->is($hit['ok'], false, 'the per-conversation cap bites');
$t->contains($hit['message'], 'Start a new question', 'and says what to do');
$t->is($usage->checkAndRecord('conv-c')['ok'], true, 'a different conversation is unaffected');
test_reset($db);
$db->execute(
    "INSERT INTO assistant_usage (conversation_id, created_at)
     SELECT 'old', ? FROM (WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM c WHERE x<400) SELECT x FROM c)",
    [$today->modify('-2 days')->format(Clock::STORED)]
);
$t->is($usage->checkAndRecord('fresh')['ok'], true, 'yesterday does not count against today');

$t->group('Config — a hand-edited file with lines missing');
$partial = sys_get_temp_dir() . '/cover-partial-config-' . getmypid() . '.php';
file_put_contents($partial, "<?php\nreturn ['anthropic_api_key' => 'k', 'password_hash' => 'h', 'data_dir' => '" . sys_get_temp_dir() . "'];\n");
register_shutdown_function(static fn() => @unlink($partial));
$thin = Config::load($partial);

// The app installs a handler that turns every warning into an exception, so a
// missing key is fatal there even though it would degrade quietly here. The
// test has to run under the same rule or it cannot see the difference.
$strict = static function (callable $fn) {
    set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        return $fn();
    } catch (Throwable $e) {
        return 'THREW: ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
};
$t->is($strict(static fn() => $thin->cookieSecure()), false, 'a missing cookie_secure defaults to off, not a fatal error');
$t->is($strict(static fn() => $thin->forceHttps()), false, 'a missing force_https defaults to off');
$t->is($thin->anthropicModel(), 'claude-opus-5', 'a missing model falls back to the confirmed default');
$t->is($thin->effort(), 'medium', 'a missing effort falls back to medium');
$t->is($thin->dailyMessageCap(), 200, 'a missing daily cap falls back');
$t->is($thin->sessionMessageCap(), 50, 'a missing session cap falls back');

// --------------------------------------------------------- Conversation ----

$t->group('Conversation — what the browser may send (§6)');
$textOnly = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]];
$t->is(count(Conversation::parse($textOnly)), 1, 'a plain question parses');

$refuse = static function (array $messages) use ($t): string {
    try {
        Conversation::parse($messages);
        return '(accepted)';
    } catch (ValidationError $e) {
        return $e->getMessage();
    }
};
$t->contains($refuse([]), 'no question to answer', 'an empty conversation is refused');
$t->contains(
    $refuse([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'a']]], ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'b']]]]),
    'no question to answer',
    'a conversation ending on the assistant is refused'
);
$t->contains(
    $refuse([['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/tiff', 'data' => 'QUJD'], ['type' => 'text', 'text' => 'x']]]]),
    'cannot read',
    'an unsupported image type is refused'
);
$t->contains(
    $refuse([['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/png', 'data' => base64_encode('not a png at all')], ['type' => 'text', 'text' => 'x']]]]),
    'not really a PNG',
    'a file lying about its type is refused'
);
$t->contains(
    $refuse([['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/png', 'data' => 'not base64!'], ['type' => 'text', 'text' => 'x']]]]),
    'did not arrive intact',
    'corrupt base64 is refused'
);
$t->contains(
    $refuse([['role' => 'user', 'content' => [['type' => 'text', 'text' => str_repeat('a', Conversation::MAX_TEXT_CHARS + 1)]]]]),
    'too long',
    'an over-long message is refused'
);
$png = "\x89PNG\r\n\x1a\n" . str_repeat('x', 40);
$withPng = [['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/png', 'data' => base64_encode($png)], ['type' => 'text', 'text' => 'check']]]];
$parsed = Conversation::parse($withPng);
$t->is($parsed[0]['content'][0]['source']['media_type'], 'image/png', 'a real PNG is passed through');
$t->contains(Conversation::lastUserText($parsed), 'not stored', 'and the log records that it was not stored');

// ----------------------------------------------------------------- Auth ----

$t->group('Auth — the shared password (§11)');
test_reset($db);
$auth = new Auth($db, $capConfig);
$t->is($auth->signIn('definitely-wrong', '10.0.0.1')['ok'], false, 'a wrong password is refused');
for ($i = 0; $i < 12; $i++) {
    $auth->signIn('wrong-' . $i, '10.0.0.2');
}
$locked = $auth->signIn('wrong-again', '10.0.0.2');
$t->contains($locked['message'], 'Too many wrong passwords', 'repeated guesses from one address are throttled');
$t->is($auth->signIn('wrong', '10.0.0.3')['ok'], false, 'a different address is not locked out by it');

exit($t->report());
