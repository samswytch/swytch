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
 * Close-out notes are the third kind of entry in §9. They arrive with the day
 * view in phase 3; the CHECK constraint in db/schema.sql widens then.
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
     * Newest last, so the screen reads start to finish as the period went.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->db->all(
            'SELECT id, kind, context_key, question, answer, note, created_at, note_added_at
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

    public function toCsv(): string
    {
        $rows = [];
        foreach ($this->all() as $entry) {
            $rows[] = [
                (int) $entry['id'],
                $entry['kind'] === 'decision' ? 'Logged decision' : 'Parked item',
                Contexts::name((string) $entry['context_key']),
                Clock::forCsv($entry['created_at'] === null ? null : (string) $entry['created_at']),
                (string) $entry['question'],
                (string) $entry['answer'],
                $entry['note'] === null ? null : (string) $entry['note'],
                Clock::forCsv($entry['note_added_at'] === null ? null : (string) $entry['note_added_at']),
            ];
        }

        return Csv::build(
            ['id', 'kind', 'context', 'logged_at', 'question', 'answer', 'her_note', 'note_added_at'],
            $rows
        );
    }
}
