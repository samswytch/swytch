<?php

declare(strict_types=1);

/**
 * The log (BRIEF.md §9). Append-only, one stream, newest last. This is what Sam
 * reads on his return.
 *
 * Entries are written by the server the moment the assistant classifies a reply
 * as logged or parked, so the record exists whether or not Sadie comes back to
 * annotate it. She can then add what she decided — once. `note` is set by a
 * conditional update that only fires while it is still null, so nothing in the
 * log is ever reworded or removed after the fact.
 *
 * Four kinds: a logged decision, a parked item, something routed to Kev, and
 * the end-of-day close-out. The first three come from the assistant; the last
 * comes from the day view.
 */
final class LogBook
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function add(string $kind, string $contextKey, string $question, string $answer): int
    {
        $this->db->execute(
            'INSERT INTO log_entries (kind, context_key, question, answer, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [$kind, $contextKey, $question, $answer, Clock::nowUtc()]
        );

        return $this->db->lastInsertId();
    }

    /**
     * The end of a working day (BRIEF.md §5). One row, appended like everything
     * else — a second close-out on the same day would be a second row, so the
     * day view offers the form only when the day has none yet.
     */
    public function addCloseout(string $entryDate, string $moved, string $notMoved, ?string $note): int
    {
        $this->db->execute(
            'INSERT INTO log_entries (kind, entry_date, moved, not_moved, note, created_at, note_added_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'closeout',
                $entryDate,
                $moved,
                $notMoved,
                $note === null || trim($note) === '' ? null : trim($note),
                Clock::nowUtc(),
                $note === null || trim($note) === '' ? null : Clock::nowUtc(),
            ]
        );

        return $this->db->lastInsertId();
    }

    public function closeoutFor(string $entryDate): ?array
    {
        return $this->db->one(
            "SELECT * FROM log_entries WHERE kind = 'closeout' AND entry_date = ? ORDER BY id DESC LIMIT 1",
            [$entryDate]
        );
    }

    /**
     * Newest last, so the screen reads start to finish as the period went.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->db->all(
            'SELECT id, kind, context_key, question, answer, note, moved, not_moved, entry_date,
                    created_at, note_added_at
             FROM log_entries
             ORDER BY created_at ASC, id ASC'
        );
    }

    /** @return 'saved'|'already_noted'|'not_found' */
    public function addNote(int $id, string $note): string
    {
        $changed = $this->db->execute(
            'UPDATE log_entries SET note = ?, note_added_at = ? WHERE id = ? AND note IS NULL',
            [$note, Clock::nowUtc(), $id]
        );
        if ($changed > 0) {
            return 'saved';
        }

        $exists = $this->db->one('SELECT id FROM log_entries WHERE id = ?', [$id]);

        return $exists === null ? 'not_found' : 'already_noted';
    }

    /** What each kind is called wherever a person reads it. */
    public const LABELS = [
        'decision' => 'Logged decision',
        'parked' => 'Parked item',
        'ask_kev' => 'Asked Kev',
        'closeout' => 'End of day',
    ];

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? $kind;
    }

    /** The heading above her own words, which differ by kind. */
    public static function noteLabel(string $kind): string
    {
        if ($kind === 'ask_kev') {
            return 'What Kev said';
        }
        if ($kind === 'parked') {
            return 'Her note';
        }
        if ($kind === 'closeout') {
            return 'Her note';
        }

        return 'What she decided';
    }

    public function toCsv(): string
    {
        $rows = [];
        foreach ($this->all() as $entry) {
            $kind = (string) $entry['kind'];
            $rows[] = [
                (int) $entry['id'],
                self::label($kind),
                $entry['context_key'] === null ? null : Contexts::name((string) $entry['context_key']),
                Clock::forCsv($entry['created_at'] === null ? null : (string) $entry['created_at']),
                $entry['entry_date'] === null ? null : (string) $entry['entry_date'],
                $entry['question'] === null ? null : (string) $entry['question'],
                $entry['answer'] === null ? null : (string) $entry['answer'],
                $entry['moved'] === null ? null : (string) $entry['moved'],
                $entry['not_moved'] === null ? null : (string) $entry['not_moved'],
                $entry['note'] === null ? null : (string) $entry['note'],
                Clock::forCsv($entry['note_added_at'] === null ? null : (string) $entry['note_added_at']),
            ];
        }

        return Csv::build(
            ['id', 'kind', 'context', 'logged_at', 'day', 'question', 'answer',
             'what_moved', 'what_did_not', 'her_note', 'note_added_at'],
            $rows
        );
    }
}
