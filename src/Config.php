<?php

declare(strict_types=1);

/**
 * Configuration, in one place, that fails loudly.
 *
 * BRIEF.md §11: "Errors are legible. Every failure mode gets a plain-English
 * message telling her what to do." A missing setting is the most likely
 * deployment failure, so it names itself rather than surfacing as null three
 * layers down.
 *
 * The file this reads lives outside the web root and is never in the
 * repository, so the API key cannot be served by the web server even if a
 * rewrite rule is wrong one day.
 */
final class Config
{
    public const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        if (!is_readable($path)) {
            throw new ConfigError(
                "The configuration file is missing. It should be at {$path}, copied from " .
                "config.example.php in the repository and filled in. It is deliberately not " .
                "part of the deploy, so it has to be put there by hand, once."
            );
        }

        /** @var mixed $values */
        $values = require $path;
        if (!is_array($values)) {
            throw new ConfigError("{$path} must return an array. See config.example.php.");
        }

        $config = new self($values);

        // Read every required value once, here, so a missing one is found at
        // boot rather than halfway through answering a question.
        $config->requireString('anthropic_api_key');
        $config->requireString('password_hash');
        $config->dataDir();
        $config->effort();

        return $config;
    }

    private function requireString(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigError(
                "'{$key}' is not set in the configuration file. See config.example.php for what it expects."
            );
        }

        return trim($value);
    }

    public function anthropicApiKey(): string
    {
        return $this->requireString('anthropic_api_key');
    }

    public function anthropicModel(): string
    {
        $model = $this->values['anthropic_model'] ?? '';

        return is_string($model) && trim($model) !== '' ? trim($model) : 'claude-opus-5';
    }

    /**
     * Only ever anything other than the default when the test harness points at
     * a stand-in server. Nothing on the live host sets it.
     */
    public function anthropicBaseUrl(): string
    {
        $url = $this->values['anthropic_base_url'] ?? '';

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : 'https://api.anthropic.com';
    }

    public function effort(): string
    {
        $effort = $this->values['assistant_effort'] ?? '';
        if (!is_string($effort) || trim($effort) === '') {
            return 'medium';
        }

        $effort = trim($effort);
        if (!in_array($effort, self::EFFORT_LEVELS, true)) {
            throw new ConfigError(
                "'assistant_effort' must be one of " . implode(', ', self::EFFORT_LEVELS) .
                ". It is currently '{$effort}'."
            );
        }

        return $effort;
    }

    public function passwordHash(): string
    {
        return $this->requireString('password_hash');
    }

    public function dailyMessageCap(): int
    {
        return $this->positiveInt('daily_message_cap', 200);
    }

    public function sessionMessageCap(): int
    {
        return $this->positiveInt('session_message_cap', 50);
    }

    private function positiveInt(string $key, int $fallback): int
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (!is_int($value) || $value <= 0) {
            throw new ConfigError("'{$key}' must be a whole number greater than zero.");
        }

        return $value;
    }

    public function dataDir(): string
    {
        $dir = $this->requireString('data_dir');
        if (!is_dir($dir)) {
            throw new ConfigError(
                "The data directory {$dir} does not exist. Create it, and make sure the directory " .
                "itself is writable — SQLite needs to create -wal and -shm files alongside the database."
            );
        }

        return rtrim($dir, '/');
    }

    public function databasePath(): string
    {
        return $this->dataDir() . '/app.sqlite';
    }

    public function logPath(): string
    {
        return $this->dataDir() . '/app.log';
    }

    public function backupDir(): string
    {
        return $this->dataDir() . '/backups';
    }

    public function cookieSecure(): bool
    {
        return $this->values['cookie_secure'] === true;
    }

    public function forceHttps(): bool
    {
        return $this->values['force_https'] === true;
    }
}

final class ConfigError extends RuntimeException
{
}
