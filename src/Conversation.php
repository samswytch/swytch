<?php

declare(strict_types=1);

/**
 * Validation for the conversation the browser posts.
 *
 * Images and PDFs are passed straight through to the Anthropic API as base64 in
 * the message content and stored nowhere — no blob storage, no database row, no
 * temporary file (BRIEF.md §6). They arrive as base64 inside the JSON body
 * rather than as a multipart upload, so nothing ever lands on disk and PHP's
 * upload machinery is not involved at all. They exist for the length of the
 * conversation and are re-sent each turn because the API is stateless.
 *
 * The `fileinfo` extension is not present on this host, so there is no MIME
 * sniffing available. It is not needed: the declared type is checked against a
 * whitelist and then against the file's own leading bytes, which is a stronger
 * check than a sniffed MIME type would have been.
 */
final class Conversation
{
    /** Base64 characters, not bytes. Roughly 8 MB of file. */
    public const MAX_ATTACHMENT_BASE64 = 11000000;

    /** Base64 characters across every attachment still in the conversation. Roughly 13 MB of file. */
    public const MAX_CONVERSATION_BASE64 = 18000000;

    public const MAX_TEXT_CHARS = 20000;
    public const MAX_TURNS = 200;

    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    public const PDF_TYPE = 'application/pdf';

    /**
     * Leading bytes each accepted format must actually start with.
     *
     * @var array<string,array<int,string>>
     */
    private const SIGNATURES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\r\n\x1a\n"],
        'image/gif' => ['GIF87a', 'GIF89a'],
        'image/webp' => ['RIFF'],
        'application/pdf' => ['%PDF-'],
    ];

    /**
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    public static function parse($raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new ValidationError('There is no question to answer. Type something and send it again.');
        }
        if (count($raw) > self::MAX_TURNS) {
            throw new ValidationError('This conversation has run very long. Start a new question.');
        }

        $budget = 0;
        $messages = [];

        foreach ($raw as $message) {
            if (!is_array($message)) {
                throw new ValidationError('That conversation could not be read. Start a new question.');
            }
            $role = $message['role'] ?? null;
            if ($role !== 'user' && $role !== 'assistant') {
                throw new ValidationError('That conversation could not be read. Start a new question.');
            }
            $content = $message['content'] ?? null;
            if (!is_array($content) || $content === []) {
                throw new ValidationError('That conversation could not be read. Start a new question.');
            }

            $parts = [];
            foreach ($content as $part) {
                $parts[] = self::parsePart($part, $budget);
            }
            $messages[] = ['role' => $role, 'content' => $parts];
        }

        $last = end($messages);
        if ($last === false || $last['role'] !== 'user') {
            throw new ValidationError('There is no question to answer. Type something and send it again.');
        }

        return $messages;
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private static function parsePart($raw, int &$budget): array
    {
        if (!is_array($raw)) {
            throw new ValidationError('That message could not be read. Start a new question.');
        }

        $type = $raw['type'] ?? null;

        if ($type === 'text') {
            $text = is_string($raw['text'] ?? null) ? $raw['text'] : '';
            if (self::length($text) > self::MAX_TEXT_CHARS) {
                throw new ValidationError(
                    'That message is too long — ' . number_format((float) self::length($text)) .
                    ' characters against a limit of ' . number_format(self::MAX_TEXT_CHARS) .
                    '. Send the part you want checked.',
                    413
                );
            }

            return ['type' => 'text', 'text' => $text];
        }

        if ($type === 'image' || $type === 'document') {
            $mediaType = is_string($raw['media_type'] ?? null) ? $raw['media_type'] : '';
            $data = is_string($raw['data'] ?? null) ? $raw['data'] : '';

            if ($type === 'image' && !in_array($mediaType, self::IMAGE_TYPES, true)) {
                throw new ValidationError(
                    'That image is in a format the assistant cannot read' .
                    ($mediaType !== '' ? " ({$mediaType})" : '') . '. Attach a JPEG, PNG, GIF or WebP.'
                );
            }
            if ($type === 'document' && $mediaType !== self::PDF_TYPE) {
                throw new ValidationError(
                    'Only PDFs can be attached as documents. Screenshot it and attach the image instead.'
                );
            }
            if ($data === '' || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $data) !== 1) {
                throw new ValidationError('That attachment did not arrive intact. Attach it again.');
            }
            if (strlen($data) > self::MAX_ATTACHMENT_BASE64) {
                throw new ValidationError(
                    'That file is ' . self::describe(strlen($data)) . ', which is over the ' .
                    self::describe(self::MAX_ATTACHMENT_BASE64) . ' limit for one attachment. ' .
                    'Export it smaller, or send the page that matters.',
                    413
                );
            }
            if (!self::looksLike($mediaType, $data)) {
                throw new ValidationError(
                    'That file is not really a ' . self::formatName($mediaType) .
                    ' inside, whatever it is named. Re-export it and attach it again.'
                );
            }

            $budget += strlen($data);
            if ($budget > self::MAX_CONVERSATION_BASE64) {
                throw new ValidationError(
                    'There are too many attachments in this conversation to send — the limit across all ' .
                    'of them is about ' . self::describe(self::MAX_CONVERSATION_BASE64) .
                    '. Start a new question with just the file you want checked.',
                    413
                );
            }

            if ($type === 'image') {
                return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $data]];
            }

            return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => self::PDF_TYPE, 'data' => $data]];
        }

        throw new ValidationError('That attachment type is not supported. Attach an image or a PDF.');
    }

    private static function formatName(string $mediaType): string
    {
        $names = [
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/gif' => 'GIF',
            'image/webp' => 'WebP',
            'application/pdf' => 'PDF',
        ];

        return $names[$mediaType] ?? $mediaType;
    }

    /** Decodes only the first few bytes, so a large attachment is not doubled in memory to check it. */
    private static function looksLike(string $mediaType, string $base64): bool
    {
        $signatures = self::SIGNATURES[$mediaType] ?? null;
        if ($signatures === null) {
            return false;
        }

        $head = base64_decode(substr($base64, 0, 24), true);
        if ($head === false) {
            return false;
        }

        foreach ($signatures as $signature) {
            if (str_starts_with($head, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** What goes in the log as "her question" — the words of the last thing she sent. */
    public static function lastUserText(array $messages): string
    {
        $last = end($messages);
        if ($last === false) {
            return '';
        }

        $text = [];
        $attachments = 0;
        foreach ($last['content'] as $part) {
            if ($part['type'] === 'text') {
                $text[] = $part['text'];
            } else {
                $attachments++;
            }
        }

        $joined = trim(implode("\n", $text));
        if ($attachments === 0) {
            return $joined;
        }

        $note = '[' . $attachments . ' attachment' . ($attachments === 1 ? '' : 's') . ', not stored]';

        return $joined === '' ? $note : $joined . "\n" . $note;
    }

    /** mbstring is expected but not depended on, so this degrades to bytes if it is off. */
    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function describe(int $base64Length): string
    {
        $bytes = (int) floor($base64Length * 3 / 4);
        $mb = $bytes / (1024 * 1024);

        return $mb >= 1 ? number_format($mb, 1) . ' MB' : (string) round($bytes / 1024) . ' KB';
    }
}

final class ValidationError extends RuntimeException
{
    private int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    /** 413 where something is genuinely too big, 400 where it is simply wrong. */
    public function status(): int
    {
        return $this->status;
    }
}
