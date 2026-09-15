<?php

declare(strict_types=1);

/**
 * Schema upgrades, run by bin/setup-db.php.
 *
 * SQLite cannot alter a CHECK constraint in place, and `log_entries` gained two
 * kinds and three columns when the day view arrived. The rebuild below renames
 * the old table, lets db/schema.sql create the new one, copies the rows across
 * and drops the old — so the table definition lives in exactly one place and
 * there is nothing here to drift out of step with it.
 *
 * Nothing in the log is ever edited or deleted (§9), and a migration is not an
 * exception: every row comes across with its id and timestamps intact.
 */
final class Migrate
{
    /** @return array<int,string> what was done, for the operator to read */
    public static function run(Db $db, string $schemaPath): array
    {
        $notes = [];
        $pdo = $db->pdo();

        $existing = $db->one(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'log_entries'"
        );

        // 'closeout' appears in the CHECK constraint only in the current
        // definition, so its absence is what marks an old database.
        $needsRebuild = $existing !== null
            && is_string($existing['sql'])
            && !str_contains($existing['sql'], 'closeout');

        if ($needsRebuild) {
            $before = (int) ($db->one('SELECT COUNT(*) AS n FROM log_entries')['n'] ?? 0);

            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            try {
                $pdo->exec('ALTER TABLE log_entries RENAME TO log_entries_old');
                $db->applySchema($schemaPath);
                $pdo->exec(
                    'INSERT INTO log_entries
                         (id, kind, context_key, question, answer, note, created_at, note_added_at)
                     SELECT id, kind, context_key, question, answer, note, created_at, note_added_at
                     FROM log_entries_old'
                );
                $pdo->exec('DROP TABLE log_entries_old');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw new DatabaseError('Could not migrate the log table: ' . $e->getMessage(), 0, $e);
            }
            $pdo->exec('PRAGMA foreign_keys = ON');

            // The rename carried the old indexes to the old table, which has
            // now been dropped. This puts them back.
            $db->applySchema($schemaPath);

            $after = (int) ($db->one('SELECT COUNT(*) AS n FROM log_entries')['n'] ?? 0);
            if ($after !== $before) {
                throw new DatabaseError(
                    "Log migration lost rows: {$before} before, {$after} after. The old table has already " .
                    'been dropped, so restore from the most recent file in appdata/backups.'
                );
            }

            $notes[] = "Rebuilt log_entries for four entry kinds; {$before} row(s) carried across.";
        }

        $db->applySchema($schemaPath);

        return $notes;
    }
}
