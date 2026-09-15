<?php

declare(strict_types=1);

/**
 * Single shared password for two people for three weeks (BRIEF.md §11). No
 * accounts, no roles, no reset flow.
 *
 * PHP sessions rather than the panel's Directory Privacy, because HTTP Basic
 * has no way to sign out and this is a shared machine.
 *
 * The cookie is SameSite=Lax, which is also what keeps a cross-site form from
 * posting to this app as her: the browser will not attach the session cookie to
 * a POST that did not originate here, and the JSON endpoints additionally
 * require a content type a plain cross-site form cannot send.
 *
 * `secure` is config-controlled and off by default. Turning it on before the
 * certificate exists locks you out of your own app.
 */
final class Auth
{
    private const WINDOW_MINUTES = 15;
    private const MAX_FAILURES = 10;

    private Db $db;
    private Config $config;

    public function __construct(Db $db, Config $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->config->cookieSecure(),
        ]);
        session_name('covercookie');
        session_start();
    }

    public function isSignedIn(): bool
    {
        return ($_SESSION['signed_in'] ?? false) === true;
    }

    /** @return array{ok:bool,message:string} */
    public function signIn(string $password, string $ip): array
    {
        if ($this->isLockedOut($ip)) {
            return [
                'ok' => false,
                'message' => 'Too many wrong passwords from this connection. Wait fifteen minutes and ' .
                    'try again, or ask Sam or Kev for the password.',
            ];
        }

        if (!password_verify($password, $this->config->passwordHash())) {
            $this->recordFailure($ip);

            return ['ok' => false, 'message' => 'That password is not right. Try again, or ask Sam or Kev for it.'];
        }

        // A fresh id on sign-in, so a session id someone else already knows
        // cannot be promoted to a signed-in one.
        session_regenerate_id(true);
        $_SESSION['signed_in'] = true;

        return ['ok' => true, 'message' => ''];
    }

    public function signOut(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $this->config->cookieSecure(),
            ]);
        }
        session_destroy();
    }

    private function isLockedOut(string $ip): bool
    {
        $since = (new DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes', new DateTimeZone('UTC')))
            ->format(Clock::STORED);

        $failures = (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at >= ?',
            [$ip, $since]
        )['n'] ?? 0);

        return $failures >= self::MAX_FAILURES;
    }

    private function recordFailure(string $ip): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)',
            [$ip, Clock::nowUtc()]
        );

        // Keep the table from growing for three weeks over nothing.
        $cutoff = (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format(Clock::STORED);
        $this->db->execute('DELETE FROM login_attempts WHERE created_at < ?', [$cutoff]);
    }
}
