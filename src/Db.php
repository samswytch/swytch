<?php

declare(strict_types=1);

/**
 * SQLite through PDO. One file, no credentials, and a backup is a file copy.
 *
 * WAL mode is on because the directory holds -wal and -shm companions anyway
 * and it stops a reader blocking a writer — which, with one person using the
 * app, mostly means the nightly backup never collides with her asking a
 * question.
 */
final class Db
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_writable($directory)) {
            throw new DatabaseError(
                "The data directory {$directory} is not writable. SQLite needs to write the database " .
                "and its -wal and -shm companions, so the directory itself needs permission, not just the file."
            );
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            // Wait rather than fail if the backup happens to hold the file.
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (PDOException $e) {
            throw new DatabaseError(
                'The database could not be opened. If this keeps happening, work from Asana and the ' .
                'authority envelope document and carry on — do not spend the day trying to fix it.',
                0,
                $e
            );
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<int|string,mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException $e) {
            error_log('Database query failed: ' . $e->getMessage());
            throw new DatabaseError(
                'The database could not be reached. If this keeps happening, work from Asana and the ' .
                'authority envelope document and carry on — do not spend the day trying to fix it.',
                0,
                $e
            );
        }
    }

    public function applySchema(string $schemaPath): void
    {
        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            throw new DatabaseError("Could not read {$schemaPath}.");
        }
        $this->pdo->exec($sql);
    }
}

final class DatabaseError extends RuntimeException
{
}
