<?php

declare(strict_types=1);

/**
 * Caps on the assistant route (BRIEF.md §11).
 *
 * A shared password sitting in front of an API key deserves a ceiling, and
 * nobody is watching the bill during the cover period. Both caps are counted in
 * SQLite rather than in the PHP session so they survive a new session and a
 * restart.
 *
 * The session cap is keyed on a conversation id the browser generates, so it is
 * a guard against a runaway conversation rather than against a determined
 * person. The daily cap is the one that actually bounds the spend.
 */
final class Usage
{
    private Db $db;
    private Config $config;

    public function __construct(Db $db, Config $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /** @return array{ok:bool,message:string} */
    public function checkAndRecord(string $conversationId): array
    {
        $dailyCap = $this->config->dailyMessageCap();
        $sessionCap = $this->config->sessionMessageCap();

        $today = (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM assistant_usage WHERE created_at >= ?',
            [Clock::startOfLondonDayUtc()]
        )['n'] ?? 0);

        if ($today >= $dailyCap) {
            return [
                'ok' => false,
                'message' => "The assistant has used its {$dailyCap} messages for today. It resets at " .
                    'midnight. Until then, work from the authority envelope document and Asana, and ask ' .
                    'Kev for anything commercial or urgent.',
            ];
        }

        $session = (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM assistant_usage WHERE conversation_id = ?',
            [$conversationId]
        )['n'] ?? 0);

        if ($session >= $sessionCap) {
            return [
                'ok' => false,
                'message' => "This conversation has reached {$sessionCap} messages. Start a new question " .
                    'to carry on — the limit is per conversation, not per day.',
            ];
        }

        $this->db->execute(
            'INSERT INTO assistant_usage (conversation_id, created_at) VALUES (?, ?)',
            [$conversationId, Clock::nowUtc()]
        );

        return ['ok' => true, 'message' => ''];
    }
}
