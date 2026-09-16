<?php

declare(strict_types=1);

/**
 * Applies db/schema.sql. Run once per database, over SSH:
 *
 *   cd /home/om44wfu4/coverapp && php bin/setup-db.php
 *
 * Safe to run again — every statement is CREATE ... IF NOT EXISTS.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = Config::load(cover_config_path());
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the configuration.\n" . $e->getMessage() . "\n");
    exit(1);
}

try {
    $db = new Db($config->databasePath());

    $notes = Migrate::ensure($db, APP_ROOT . '/db/schema.sql');
    foreach ($notes as $note) {
        echo 'Migrated: ' . $note . "\n";
    }
    if ($notes === []) {
        echo "Already at schema version " . Migrate::SCHEMA_VERSION . "; nothing to do.\n";
    }

    $tables = $db->all(
        "SELECT name FROM sqlite_master WHERE type = 'table'
         AND name IN ('log_entries', 'cards', 'assistant_usage', 'login_attempts') ORDER BY name"
    );
    $mode = $db->one('PRAGMA journal_mode');

    echo 'Database: ' . $config->databasePath() . "\n";
    echo 'Tables:   ' . implode(', ', array_column($tables, 'name')) . "\n";
    echo 'Journal:  ' . ($mode['journal_mode'] ?? 'unknown') . "\n";

    if (!is_dir($config->backupDir())) {
        if (mkdir($config->backupDir(), 0750, true)) {
            echo 'Backups:  created ' . $config->backupDir() . "\n";
        } else {
            echo 'Backups:  COULD NOT create ' . $config->backupDir() . " — make it by hand.\n";
        }
    } else {
        echo 'Backups:  ' . $config->backupDir() . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Could not apply the schema.\n" . $e->getMessage() . "\n");
    exit(1);
}
