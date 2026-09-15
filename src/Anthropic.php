<?php

declare(strict_types=1);

/**
 * The Claude API, over curl, from the server only.
 *
 * Blocking and non-streaming on purpose. LiteSpeed buffers output, so an SSE
 * stream would be a thing to debug from abroad with nobody on call; a spinner
 * on a normal POST is the boring choice that works. The probe on this host
 * measured a 115-second request returning intact, so a 90-second curl timeout
 * has real headroom underneath the 300-second max_execution_time.
 *
 * One retry with backoff on 429 and 5xx, then a message that says plainly what
 * has happened and points at Asana.
 */
final class Anthropic
{
    private const VERSION = '2023-06-01';
    private const TIMEOUT_SECONDS = 90;
    private const MAX_TOKENS = 16000;

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<int,array<string,mixed>> $systemBlocks
     * @param array<int,array<string,mixed>> $messages
     * @return array{text:string,stop_reason:string}
     */
    public function ask(array $systemBlocks, array $messages): array
    {
        $body = [
            'model' => $this->config->anthropicModel(),
            'max_tokens' => self::MAX_TOKENS,
            'system' => $systemBlocks,
            // Adaptive thinking, not displayed. Nothing streams, so a thinking
            // summary would only arrive with the answer and never be read.
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => $this->config->effort()],
            'messages' => $messages,
        ];

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new AssistantError('That question could not be prepared for sending. Try again.');
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            [$status, $responseBody, $curlError] = $this->post($payload);

            if ($curlError !== null) {
                if ($attempt === 1) {
                    sleep(2);
                    continue;
                }
                throw new AssistantError(
                    'Could not reach Claude. Check you are online, then try again. ' . self::FALLBACK
                );
            }

            if ($status === 429 || $status >= 500) {
                if ($attempt === 1) {
                    sleep(3);
                    continue;
                }
            }

            return $this->interpret($status, $responseBody);
        }
    }

    private const FALLBACK = 'If it keeps failing, work from Asana and the authority envelope document ' .
        'and carry on — do not spend the day trying to fix it.';

    /** @return array{0:int,1:string,2:string|null} */
    private function post(string $payload): array
    {
        $handle = curl_init($this->config->anthropicBaseUrl() . '/v1/messages');
        if ($handle === false) {
            return [0, '', 'curl could not be initialised'];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'anthropic-version: ' . self::VERSION,
                'x-api-key: ' . $this->config->anthropicApiKey(),
            ],
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;
        curl_close($handle);

        if ($error !== null) {
            error_log('Anthropic request failed: ' . $error);
        }

        return [$status, is_string($body) ? $body : '', $error];
    }

    /** @return array{text:string,stop_reason:string} */
    private function interpret(int $status, string $body): array
    {
        if ($status === 401 || $status === 403) {
            throw new AssistantError(
                "Claude is refusing this deployment's API key. That needs Sam or Kev to look at the " .
                'Anthropic account — it is not something you can fix. ' . self::FALLBACK
            );
        }
        if ($status === 429) {
            throw new AssistantError(
                'Claude is rate limiting the account, or the monthly spend cap has been reached. ' .
                'Wait a couple of minutes and try again. ' . self::FALLBACK
            );
        }
        if ($status >= 500) {
            throw new AssistantError(
                'Claude is unavailable at the moment. Try again in a minute. ' . self::FALLBACK
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('Anthropic returned something unreadable: ' . substr($body, 0, 500));
            throw new AssistantError('Claude returned something unreadable. Try again. ' . self::FALLBACK);
        }

        if ($status !== 200) {
            $message = $decoded['error']['message'] ?? 'no reason given';
            error_log('Anthropic rejected the request: ' . $body);
            throw new AssistantError(
                'Claude rejected the request: ' . $message .
                '. Try rephrasing, or send a smaller attachment. ' . self::FALLBACK
            );
        }

        $stopReason = is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : '';

        if ($stopReason === 'refusal') {
            throw new AssistantError(
                'Claude declined to answer that one. Rephrase it, or ask Kev if it is commercial or ' .
                'urgent. This is a safety refusal from Claude, not a problem with the app.'
            );
        }

        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            throw new AssistantError(
                $stopReason === 'max_tokens'
                    ? 'That answer was too long to finish. Ask about a smaller piece of it.'
                    : 'Claude returned an empty answer. Try again.'
            );
        }

        return ['text' => $text, 'stop_reason' => $stopReason];
    }
}

final class AssistantError extends RuntimeException
{
}
