<?php

declare(strict_types=1);

/**
 * Deploy-once means there is one chance to notice that something is not wired
 * up. This reports on the things the assistant needs: the configuration, the
 * database tables, the content files, the writable data directory, and the PHP
 * extensions this host was measured to be missing.
 *
 * It does not call the Anthropic API — that costs money, and the key is checked
 * by asking the assistant one question.
 */
function cover_health(App $app): void
{
    $checks = [];

    $checks[] = cover_health_check('Configuration', static function () use ($app): string {
        $config = $app->config();
        $config->anthropicApiKey();
        $config->passwordHash();

        return 'Loaded. Model ' . $config->anthropicModel() . ', effort ' . $config->effort() .
            ', caps ' . $config->dailyMessageCap() . '/day and ' . $config->sessionMessageCap() .
            '/conversation. Cookie secure: ' . ($config->cookieSecure() ? 'on' : 'off') .
            '. Force HTTPS: ' . ($config->forceHttps() ? 'on' : 'off') . '.';
    });

    $checks[] = cover_health_check('Data directory', static function () use ($app): string {
        $dir = $app->config()->dataDir();
        if (!is_writable($dir)) {
            throw new RuntimeException(
                "{$dir} is not writable. SQLite needs to write the database and its -wal and -shm " .
                'companions, so the directory itself needs permission, not just the file.'
            );
        }
        $backups = $app->config()->backupDir();

        return $dir . ' is writable. Backups directory ' .
            (is_dir($backups) ? 'present.' : 'MISSING — create ' . $backups . ' so the nightly cron can write.');
    });

    $checks[] = cover_health_check('Database', static function () use ($app): string {
        $rows = $app->db()->all(
            "SELECT name FROM sqlite_master WHERE type = 'table'
             AND name IN ('log_entries', 'assistant_usage', 'login_attempts') ORDER BY name"
        );
        $found = array_column($rows, 'name');
        if (count($found) < 3) {
            throw new RuntimeException(
                'Only found ' . (implode(', ', $found) ?: 'no tables') . '. Run bin/setup-db.php over SSH.'
            );
        }
        $mode = $app->db()->one('PRAGMA journal_mode');

        return 'Reachable. Tables: ' . implode(', ', $found) .
            '. Journal mode: ' . ($mode['journal_mode'] ?? 'unknown') . '.';
    });

    $checks[] = cover_health_check('Authority envelope', static function () use ($app): string {
        return 'Readable, ' . strlen($app->content()->envelope()) . ' bytes.';
    });

    $checks[] = cover_health_check('Brand packs', static function () use ($app): string {
        $sizes = [];
        foreach (Contexts::ALL as $context) {
            $sizes[] = $context['name'] . ' ' . strlen($app->content()->brandPack($context));
        }

        return 'All six readable. ' . implode(', ', $sizes) . ' bytes.';
    });

    $checks[] = cover_health_check('PHP', static function (): string {
        $missing = [];
        foreach (['curl', 'openssl', 'json', 'pdo_sqlite', 'session'] as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('Missing required extensions: ' . implode(', ', $missing) . '.');
        }

        $note = extension_loaded('mbstring')
            ? 'mbstring on.'
            : 'mbstring is OFF — enable it in PHP Parameters → Extensions before Sadie uses this, or ' .
              'apostrophes and em-dashes in pasted copy will misbehave.';

        return PHP_VERSION . ' on ' . PHP_SAPI . '. ' . $note;
    });

    $checks[] = cover_health_check('Timezone', static function (): string {
        return 'Server ' . (date_default_timezone_get()) . '; it is now ' . Clock::todayForPrompt() .
            ', ' . (Clock::toLondon(Clock::nowUtc())?->format('H:i') ?? '') . ' in London.';
    });

    $ok = true;
    foreach ($checks as $check) {
        if (!$check['ok']) {
            $ok = false;
        }
    }

    http_response_code($ok ? 200 : 503);
    $app->render('health', ['title' => 'Health — Marketing cover', 'checks' => $checks, 'ok' => $ok]);
}

/** @return array{name:string,ok:bool,detail:string} */
function cover_health_check(string $name, callable $run): array
{
    try {
        return ['name' => $name, 'ok' => true, 'detail' => (string) $run()];
    } catch (Throwable $e) {
        return ['name' => $name, 'ok' => false, 'detail' => $e->getMessage()];
    }
}
