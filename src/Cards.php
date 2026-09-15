<?php

declare(strict_types=1);

/**
 * Cards (BRIEF.md §3). One card type, nothing beyond the fields listed there,
 * and no owner column — every card is Sadie's. If a card needs breaking down it
 * becomes two cards.
 *
 * There is no recurrence field and no generator: Sam pre-generates three weeks
 * of dated cards at setup, which is the point at which recurrence is resolved.
 */
final class Cards
{
    public const STREAMS = ['social', 'email', 'physical', 'premises'];
    public const STATUSES = ['not_started', 'in_progress', 'done'];
    public const PRIORITIES = ['low', 'normal', 'high'];
    public const CHANNELS = ['LinkedIn', 'Instagram', 'Facebook', 'Email'];

    /** publish_time only means anything on something that goes out at a time. */
    public const TIMED_STREAMS = ['social', 'email'];

    public const STREAM_LABELS = [
        'social' => 'Social',
        'email' => 'Email',
        'physical' => 'Physical',
        'premises' => 'Premises',
    ];

    public const STATUS_LABELS = [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ];

    public const PRIORITY_LABELS = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
    ];

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    // ---- reading ----------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM cards WHERE id = ?', [$id]);
    }

    /**
     * The list view (§8): a flat table, filtered and sorted.
     *
     * @param array<int,string> $contextKeys
     * @return array<int,array<string,mixed>>
     */
    public function all(array $contextKeys = [], string $status = '', string $sort = 'due'): array
    {
        $where = [];
        $params = [];

        if ($contextKeys !== []) {
            $where[] = 'context_key IN (' . implode(',', array_fill(0, count($contextKeys), '?')) . ')';
            $params = array_merge($params, $contextKeys);
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        } elseif ($status === 'open') {
            $where[] = "status != 'done'";
        }

        $order = $sort === 'title'
            ? 'title COLLATE NOCASE ASC'
            : 'due_date ASC, COALESCE(publish_time, \'99:99\') ASC, id ASC';

        $sql = 'SELECT * FROM cards'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY ' . $order;

        return $this->db->all($sql, $params);
    }

    public function countAll(): int
    {
        return (int) ($this->db->one('SELECT COUNT(*) AS n FROM cards')['n'] ?? 0);
    }

    // ---- writing ----------------------------------------------------------

    /** @param array<string,mixed> $values */
    public function create(array $values): int
    {
        $this->db->execute(
            'INSERT INTO cards
                (title, context_key, stream, due_date, publish_time, channel,
                 status, priority, asana_url, notes, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $values['title'], $values['context_key'], $values['stream'], $values['due_date'],
                $values['publish_time'], $values['channel'], $values['status'], $values['priority'],
                $values['asana_url'], $values['notes'], Clock::nowUtc(),
                $values['status'] === 'done' ? Clock::nowUtc() : null,
            ]
        );

        return $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $values */
    public function update(int $id, array $values): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return;
        }

        $this->db->execute(
            'UPDATE cards SET title = ?, context_key = ?, stream = ?, due_date = ?, publish_time = ?,
                    channel = ?, status = ?, priority = ?, asana_url = ?, notes = ?, completed_at = ?
             WHERE id = ?',
            [
                $values['title'], $values['context_key'], $values['stream'], $values['due_date'],
                $values['publish_time'], $values['channel'], $values['status'], $values['priority'],
                $values['asana_url'], $values['notes'],
                self::completedAt($values['status'], $existing),
                $id,
            ]
        );
    }

    /** Inline status change from the list view and the day view. */
    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $existing = $this->find($id);
        if ($existing === null) {
            return false;
        }

        $this->db->execute(
            'UPDATE cards SET status = ?, completed_at = ? WHERE id = ?',
            [$status, self::completedAt($status, $existing), $id]
        );

        return true;
    }

    /**
     * Set when it first becomes done, cleared if it is reopened, and otherwise
     * left exactly as it was — so reopening and re-closing does not rewrite the
     * original completion time.
     *
     * @param array<string,mixed> $existing
     */
    private static function completedAt(string $status, array $existing): ?string
    {
        if ($status !== 'done') {
            return null;
        }

        return $existing['completed_at'] !== null ? (string) $existing['completed_at'] : Clock::nowUtc();
    }

    // ---- validation -------------------------------------------------------

    /**
     * Required fields are enforced here, once, for the form and the seed script
     * alike (§13 phase 2).
     *
     * @param array<string,mixed> $input
     * @return array{values:array<string,mixed>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Give it a short title.';
        } elseif (mb_strlen_safe($title) > 200) {
            $errors['title'] = 'Keep the title short — 200 characters at most.';
        }

        $contextKey = (string) ($input['context_key'] ?? '');
        if (Contexts::find($contextKey) === null) {
            $errors['context_key'] = 'Pick which brand, or Premises.';
        }

        $stream = (string) ($input['stream'] ?? '');
        if (!in_array($stream, self::STREAMS, true)) {
            $errors['stream'] = 'Pick a stream.';
        }

        $dueDate = trim((string) ($input['due_date'] ?? ''));
        $parsedDue = self::parseDate($dueDate);
        if ($parsedDue === null) {
            $errors['due_date'] = 'Give it a due date.';
        } elseif ((int) $parsedDue->format('N') === 1) {
            $errors['due_date'] = self::mondayMessage($parsedDue);
        }

        $publishTime = trim((string) ($input['publish_time'] ?? ''));
        if ($publishTime !== '') {
            if (!self::isTime($publishTime)) {
                $errors['publish_time'] = 'Use a 24-hour time, like 11:30.';
            } elseif (!in_array($stream, self::TIMED_STREAMS, true)) {
                $errors['publish_time'] = 'A publish time only applies to social and email.';
            }
        }

        $channel = trim((string) ($input['channel'] ?? ''));
        if ($channel !== '' && !in_array($channel, self::CHANNELS, true)) {
            $errors['channel'] = 'Pick one of the four channels, or leave it blank.';
        }

        $status = (string) ($input['status'] ?? 'not_started');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'not_started';
        }

        $priority = (string) ($input['priority'] ?? 'normal');
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 'normal';
        }

        $asanaUrl = trim((string) ($input['asana_url'] ?? ''));
        if ($asanaUrl !== '' && preg_match('#^https?://#i', $asanaUrl) !== 1) {
            $errors['asana_url'] = 'That does not look like a link. Paste the whole Asana URL, starting with https://.';
        }

        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            'values' => [
                'title' => $title,
                'context_key' => $contextKey,
                'stream' => $stream,
                'due_date' => $dueDate,
                'publish_time' => $publishTime === '' ? null : $publishTime,
                'channel' => $channel === '' ? null : $channel,
                'status' => $status,
                'priority' => $priority,
                'asana_url' => $asanaUrl === '' ? null : $asanaUrl,
                'notes' => $notes === '' ? null : $notes,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * BRIEF.md §5: the form rejects a Monday and suggests the Friday before or
     * the Tuesday after, with the reason stated inline. Friday therefore
     * carries more load than other days, which is intended.
     */
    public static function mondayMessage(DateTimeImmutable $monday): string
    {
        $friday = $monday->modify('-3 days');
        $tuesday = $monday->modify('+1 day');

        return 'Sadie does not work on Swytch marketing on Mondays. Use ' .
            $friday->format('l j F') . ' or ' . $tuesday->format('l j F') . '.';
    }

    public static function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(Clock::ZONE));
        if ($parsed === false) {
            return null;
        }
        // createFromFormat accepts 2026-02-31 and rolls it over; this rejects it.
        return $parsed->format('Y-m-d') === $value ? $parsed : null;
    }

    public static function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** "Thursday 8 October" — how a due date reads everywhere in the interface. */
    public static function readableDate(string $isoDate): string
    {
        $parsed = self::parseDate($isoDate);

        return $parsed === null ? $isoDate : $parsed->format('l j F');
    }

    public static function shortDate(string $isoDate): string
    {
        $parsed = self::parseDate($isoDate);

        return $parsed === null ? $isoDate : $parsed->format('D j M');
    }
}

/** mbstring is expected on this host but not depended on. */
function mb_strlen_safe(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}
