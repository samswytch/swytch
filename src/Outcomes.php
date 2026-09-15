<?php

declare(strict_types=1);

/**
 * Every assistant reply ends with a machine-readable outcome line, which the
 * server strips before Sadie sees it and uses to decide what goes to the log.
 *
 * A marker on the end of the text was chosen over a tool call because it cannot
 * fail in a way that costs her the answer: if the model omits it, she still
 * gets a complete reply and nothing is logged. The app is deployed once and
 * nobody is on call, so failure modes that degrade quietly to "still useful"
 * beat clever ones that don't.
 */
final class Outcomes
{
    public const GO_AHEAD = 'go_ahead';
    public const GO_AHEAD_LOGGED = 'go_ahead_logged';
    public const ASK_KEV = 'ask_kev';
    public const PARK = 'park';

    private const PATTERN = '/<<OUTCOME:\s*(go_ahead_logged|go_ahead|ask_kev|park)\s*>>/';

    /** What the interface says under a reply. Deliberately plain, and no colour — §12. */
    public const LABELS = [
        self::GO_AHEAD => 'Your call. Nothing logged.',
        self::GO_AHEAD_LOGGED => 'Your call, and written to the log for Sam.',
        self::ASK_KEV => 'Ask Kev. He is in the office and can answer today.',
        self::PARK => 'Parked until Monday 26 October, and written to the log.',
    ];

    /**
     * Strip every marker from a finished reply and report the last outcome found.
     *
     * Falls back to go_ahead when the model omits the marker. Per the authority
     * envelope, "go ahead" is the common case and uncertainty resolves towards
     * acting, not towards parking, so an absent marker should not turn into a stop.
     *
     * @return array{text:string,outcome:string}
     */
    public static function extract(string $raw): array
    {
        $outcome = self::GO_AHEAD;
        if (preg_match_all(self::PATTERN, $raw, $matches) > 0) {
            $outcome = (string) end($matches[1]);
        }

        $text = (string) preg_replace(self::PATTERN, '', $raw);

        return ['text' => rtrim($text), 'outcome' => $outcome];
    }

    /**
     * Which outcomes produce a log entry, and of which kind (BRIEF.md §9).
     *
     * `ask_kev` is logged too. §9 as drafted lists three kinds, but something
     * routed to Kev is exactly the sort of thing Sam wants to find on his
     * return — it is a decision that was taken while he was away, by someone
     * else, and the log is the only place it would otherwise be recorded.
     * Tier 1 "go ahead" stays unlogged; the envelope is explicit that a log of
     * every post is noise and will not get read.
     */
    public static function logKind(string $outcome): ?string
    {
        if ($outcome === self::GO_AHEAD_LOGGED) {
            return 'decision';
        }
        if ($outcome === self::PARK) {
            return 'parked';
        }
        if ($outcome === self::ASK_KEV) {
            return 'ask_kev';
        }

        return null;
    }

    public static function label(string $outcome): string
    {
        return self::LABELS[$outcome] ?? self::LABELS[self::GO_AHEAD];
    }
}
