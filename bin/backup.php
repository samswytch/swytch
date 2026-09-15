<?php

declare(strict_types=1);

/**
 * Nightly SQLite backup, keeping fourteen days.
 *
 * This is the only safety net if the database is corrupted while nobody can
 * redeploy. It uses SQLite's own VACUUM INTO rather than copying the file,
 * because a plain copy taken while the app is mid-write can capture the
 * database without its -wal companion and produce a backup that will not open.
 *
 * Cron (times are UTC on this server — see the note about 25 October in
 * README.md):
 *
 *   17 2 * * * /usr/bin/php /home/om44wfu4/coverapp/bin/backup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

const KEEP_DAYS = 14;

try {
    $config = Config::load(cover_config_path());
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed: could not read the configuration.\n" . $e->getMessage() . "\n");
    exit(1);
}

$backupDir = $config->backupDir();
if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true)) {
    fwrite(STDERR, "Backup failed: could not create {$backupDir}.\n");
    exit(1);
}

$target = $backupDir . '/app-' . (new DateTimeImmutable('now', new DateTimeZone(Clock::ZONE)))->format('Y-m-d-His') . '.sqlite';

try {
    $db = new Db($config->databasePath());
    // VACUUM INTO takes a consistent snapshot even while the app is writing.
    $statement = $db->pdo()->prepare('VACUUM INTO ?');
    $statement->execute([$target]);
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!is_file($target) || filesize($target) === 0) {
    fwrite(STDERR, "Backup failed: {$target} was not written.\n");
    exit(1);
}

$removed = 0;
$cutoff = time() - (KEEP_DAYS * 86400);
foreach (glob($backupDir . '/app-*.sqlite') ?: [] as $old) {
    if (filemtime($old) < $cutoff && unlink($old)) {
        $removed++;
    }
}

echo 'Wrote ' . $target . ' (' . number_format(filesize($target) / 1024, 1) . " KB)\n";
echo 'Removed ' . $removed . " backup(s) older than " . KEEP_DAYS . " days\n";
