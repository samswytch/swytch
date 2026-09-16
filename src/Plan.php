<?php

declare(strict_types=1);

/**
 * The day view's ordering (BRIEF.md §5).
 *
 * "The plan is generated, not assembled. She does not build a priority list."
 * So this is a query run when she opens the page, not a stored list and not a
 * scheduled job — there is nothing to go stale and nothing that can silently
 * fail to run overnight.
 *
 * The rules, in order:
 *   1. Anything with a publish_time today, in time order.
 *   2. Anything overdue, or due within two days, that is not done.
 *   3. The next open item on project work (physical materials, premises).
 *   4. Stop. Cap at six items.
 *
 * Every item carries one short line saying why it is there. §5 is explicit that
 * this is not decoration: over twelve days it teaches her how the ordering
 * works, which is the transferable part.
 */
final class Plan
{
    public const CAP = 6;

    /** "due within two days" — today, tomorrow, the day after. */
    private const SOON_DAYS = 2;

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int,array{card:array<string,mixed>,reason:string,rule:int}>
     */
    public function forDay(string $isoDate): array
    {
        $today = Cards::parseDate($isoDate);
        if ($today === null) {
            return [];
        }

        $soon = $today->modify('+' . self::SOON_DAYS . ' days')->format('Y-m-d');
        $chosen = [];
        $seen = [];

        /**
         * $mostOf caps how many a single rule may contribute. Rule 3 takes one,
         * and it has to be the first row this plan has not already used — doing
         * that with LIMIT 1 in SQL meant that when rule 2 had already taken the
         * oldest project item, rule 3 silently contributed nothing at all.
         */
        $take = function (array $rows, int $rule, callable $reason, ?int $mostOf = null) use (&$chosen, &$seen): void {
            $added = 0;
            foreach ($rows as $card) {
                if (count($chosen) >= self::CAP || ($mostOf !== null && $added >= $mostOf)) {
                    return;
                }
                $id = (int) $card['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $chosen[] = ['card' => $card, 'reason' => $reason($card), 'rule' => $rule];
                $added++;
            }
        };

        // 1. Anything with a publish_time today, in time order.
        $take(
            $this->db->all(
                "SELECT * FROM cards
                 WHERE due_date = ? AND publish_time IS NOT NULL AND status != 'done'
                 ORDER BY publish_time ASC, id ASC",
                [$isoDate]
            ),
            1,
            static fn(array $c): string => 'publishes at ' . $c['publish_time']
        );

        // 2. Anything overdue, or due within two days, that is not done.
        //
        //    §5 reads "anything overdue, or due within two days and not
        //    started". Requiring not_started on the second half hid a card she
        //    had picked up yesterday and was due today — it would reappear only
        //    once it was late, which is exactly the wrong moment. Status other
        //    than done is not a reason to leave something off the day's plan;
        //    the reason line says which it is.
        //
        //    "due_date <= $soon" covers both halves: overdue is any date before
        //    today, and today is never after $soon.
        $take(
            $this->db->all(
                "SELECT * FROM cards
                 WHERE status != 'done' AND due_date <= ?
                 ORDER BY due_date ASC, COALESCE(publish_time, '99:99') ASC, id ASC",
                [$soon]
            ),
            2,
            fn(array $c): string => self::dueReason((string) $c['due_date'], $isoDate, (string) $c['status'])
        );

        // 3. The next open item on project work — one item, the oldest that is
        //    not already on the plan.
        $take(
            $this->db->all(
                "SELECT * FROM cards
                 WHERE status != 'done' AND stream IN ('physical', 'premises')
                 ORDER BY due_date ASC, id ASC",
                []
            ),
            3,
            static fn(array $c): string => 'oldest open item',
            1
        );

        return $chosen;
    }

    /**
     * "due Thursday, not started" — §5's own example. A weekday name is enough
     * inside a working week and ambiguous outside one, so anything more than
     * six days away from today is named by its date instead.
     *
     * The status is named because rule 2 now admits work already in progress,
     * and "due Friday" alone would not tell her whether she had started it.
     */
    private static function dueReason(string $dueIso, string $todayIso, string $status): string
    {
        $due = Cards::parseDate($dueIso);
        $today = Cards::parseDate($todayIso);
        if ($due === null || $today === null) {
            return 'due ' . $dueIso;
        }

        $started = $status === 'in_progress' ? ', in progress' : ', not started';
        $days = (int) $today->diff($due)->format('%r%a');

        if ($days < 0) {
            // Overdue already says it has not been finished, so the only status
            // worth adding is that it is under way.
            return 'overdue, was due ' . self::when($due, abs($days))
                . ($status === 'in_progress' ? ', in progress' : '');
        }
        if ($days === 0) {
            return 'due today' . $started;
        }
        if ($days === 1) {
            return 'due tomorrow' . $started;
        }

        return 'due ' . self::when($due, $days) . $started;
    }

    private static function when(DateTimeImmutable $date, int $distanceInDays): string
    {
        return $distanceInDays <= 6 ? $date->format('l') : $date->format('j F');
    }

    /**
     * What moved today: anything completed today, whether or not it was on the
     * plan — she should get credit for work she picked up herself.
     *
     * @return array<int,array<string,mixed>>
     */
    public function completedOn(string $isoDate): array
    {
        // completed_at is stored in UTC and the working day is a London one, so
        // the day is a range between two UTC instants, not a matching prefix.
        // In summer, comparing the UTC date alone would drop anything she
        // finished after 11pm BST from her own close-out.
        $midnight = Cards::parseDate($isoDate);
        if ($midnight === null) {
            return [];
        }

        $utc = new DateTimeZone('UTC');
        $from = $midnight->setTimezone($utc)->format(Clock::STORED);
        $to = $midnight->modify('+1 day')->setTimezone($utc)->format(Clock::STORED);

        return $this->db->all(
            'SELECT * FROM cards WHERE completed_at >= ? AND completed_at < ? ORDER BY completed_at ASC',
            [$from, $to]
        );
    }
}
