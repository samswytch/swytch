<?php

declare(strict_types=1);

/**
 * Wiring, and the small amount of routing this app needs.
 *
 * Everything is behind the shared password except the login screen itself and
 * the static assets, which LiteSpeed serves directly without PHP ever seeing
 * them.
 */
final class App
{
    private Config $config;
    private ?Db $db = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Db
    {
        if ($this->db === null) {
            $this->db = new Db($this->config->databasePath());
        }

        return $this->db;
    }

    public function auth(): Auth
    {
        return new Auth($this->db(), $this->config);
    }

    public function logBook(): LogBook
    {
        return new LogBook($this->db());
    }

    public function cards(): Cards
    {
        return new Cards($this->db());
    }

    public function usage(): Usage
    {
        return new Usage($this->db(), $this->config);
    }

    public function content(): Content
    {
        return new Content(APP_ROOT . '/content');
    }

    public function prompt(): Prompt
    {
        return new Prompt($this->content());
    }

    public function anthropic(): Anthropic
    {
        return new Anthropic($this->config);
    }

    /** The path the browser asked for, without the query string. */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') ?: '/' : '/';
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }

    /**
     * Redirects are relative on purpose: behind LiteSpeed the host and scheme
     * PHP sees are not always the ones the browser used, and redirecting to the
     * wrong origin silently drops the session cookie that was just set.
     */
    public static function redirect(string $path): void
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    /** @param array<string,mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function jsonError(string $message, int $status): void
    {
        self::json(['error' => $message], $status);
    }

    /** Redirect to HTTPS, once the certificate exists and the flag is turned on. */
    public function enforceHttps(): void
    {
        if (!$this->config->forceHttps()) {
            return;
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $forwarded = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        if ($https || $forwarded) {
            return;
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'plan.swytch.graphics';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }

    /** @param array<string,mixed> $data */
    public function render(string $view, array $data = []): void
    {
        $app = $this;
        extract($data, EXTR_SKIP);
        require APP_ROOT . '/src/views/layout.php';
        exit;
    }
}
