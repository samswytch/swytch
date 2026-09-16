<?php

declare(strict_types=1);

/**
 * A very small assertion harness. No framework, for the same reason the app has
 * none: nothing here can be updated for three weeks, and a dependency tree is a
 * liability rather than a convenience.
 */
final class Tests
{
    private int $passed = 0;
    /** @var array<int,string> */
    private array $failures = [];
    private string $group = '';
    private bool $quiet;

    public function __construct(bool $quiet = false)
    {
        $this->quiet = $quiet;
    }

    public function group(string $name): void
    {
        $this->group = $name;
        if (!$this->quiet) {
            echo "\n  {$name}\n";
        }
    }

    public function ok(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            if (!$this->quiet) {
                echo "    ok    {$label}\n";
            }
            return;
        }
        $this->failures[] = "{$this->group}: {$label}";
        if (!$this->quiet) {
            echo "    FAIL  {$label}\n";
        }
    }

    /** @param mixed $actual @param mixed $expected */
    public function is($actual, $expected, string $label): void
    {
        $same = $actual === $expected;
        if (!$same && !$this->quiet) {
            $this->ok(false, $label . ' — got ' . self::show($actual) . ', wanted ' . self::show($expected));
            return;
        }
        $this->ok($same, $label);
    }

    public function contains(string $haystack, string $needle, string $label): void
    {
        $this->ok(str_contains($haystack, $needle), $label . ' — looking for "' . $needle . '"');
    }

    public function lacks(string $haystack, string $needle, string $label): void
    {
        $this->ok(!str_contains($haystack, $needle), $label . ' — must not contain "' . $needle . '"');
    }

    /** @param mixed $value */
    private static function show($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        return '"' . (string) $value . '"';
    }

    public function report(): int
    {
        $failed = count($this->failures);
        if (!$this->quiet) {
            echo "\n";
            foreach ($this->failures as $failure) {
                echo "  FAILED: {$failure}\n";
            }
            echo "\n  {$this->passed} passed, {$failed} failed\n";
        } else {
            echo "{$this->passed} passed, {$failed} failed\n";
            foreach ($this->failures as $failure) {
                echo "  FAILED: {$failure}\n";
            }
        }

        return $failed === 0 ? 0 : 1;
    }
}

/** A throwaway database with the real schema applied. */
function test_db(): Db
{
    static $path = null;
    if ($path === null) {
        $path = sys_get_temp_dir() . '/cover-tests-' . getmypid() . '.sqlite';
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($path . $suffix);
        }
        register_shutdown_function(static function () use ($path): void {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
        });
    }
    $db = new Db($path);
    $db->applySchema(APP_ROOT . '/db/schema.sql');

    return $db;
}

function test_reset(Db $db): void
{
    $db->execute('DELETE FROM cards');
    $db->execute('DELETE FROM log_entries');
    $db->execute('DELETE FROM assistant_usage');
    $db->execute('DELETE FROM login_attempts');
}
