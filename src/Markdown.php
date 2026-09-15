<?php

declare(strict_types=1);

/**
 * A small markdown renderer for the assistant's replies.
 *
 * It exists instead of a dependency because every piece of text it handles goes
 * through htmlspecialchars before any tag is added, and the only tags it can
 * ever emit are the fixed set below. Model output cannot become markup. It
 * covers what Claude actually writes in a chat reply: paragraphs, headings,
 * lists, quotes, fenced code, and inline emphasis, code and links.
 *
 * This is the only markdown implementation in the app. The log screen renders
 * stored answers with it, and the assistant endpoint returns the HTML it
 * produces alongside the raw text, so the browser never has to parse markdown
 * itself and there is no second implementation to drift.
 */
final class Markdown
{
    public static function toHtml(string $source): string
    {
        $html = '';
        foreach (self::blocks($source) as $block) {
            switch ($block['kind']) {
                case 'heading':
                    $tag = $block['level'] === 2 ? 'h2' : 'h3';
                    $html .= "<{$tag}>" . self::inline($block['text']) . "</{$tag}>";
                    break;
                case 'list':
                    $tag = $block['ordered'] ? 'ol' : 'ul';
                    $html .= "<{$tag}>";
                    foreach ($block['items'] as $item) {
                        $html .= '<li>' . self::inline($item) . '</li>';
                    }
                    $html .= "</{$tag}>";
                    break;
                case 'quote':
                    $html .= '<blockquote>' . self::inline(implode(' ', $block['lines'])) . '</blockquote>';
                    break;
                case 'code':
                    $html .= '<pre>' . htmlspecialchars(implode("\n", $block['lines']), ENT_QUOTES, 'UTF-8') . '</pre>';
                    break;
                default:
                    $html .= '<p>' . self::inline(implode(' ', $block['lines'])) . '</p>';
            }
        }

        return $html;
    }

    /** @return array<int,array<string,mixed>> */
    private static function blocks(string $source): array
    {
        $blocks = [];
        $lines = explode("\n", str_replace("\r\n", "\n", $source));
        $fenced = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}```/', $line) === 1) {
                if ($fenced !== null) {
                    $blocks[] = ['kind' => 'code', 'lines' => $fenced];
                    $fenced = null;
                } else {
                    $fenced = [];
                }
                continue;
            }
            if ($fenced !== null) {
                $fenced[] = $line;
                continue;
            }

            if (trim($line) === '') {
                $blocks[] = ['kind' => 'paragraph', 'lines' => []];
                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $blocks[] = ['kind' => 'heading', 'level' => strlen($m[1]) <= 2 ? 2 : 3, 'text' => $m[2]];
                continue;
            }

            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m) === 1) {
                $last = count($blocks) - 1;
                if ($last >= 0 && $blocks[$last]['kind'] === 'quote') {
                    $blocks[$last]['lines'][] = $m[1];
                } else {
                    $blocks[] = ['kind' => 'quote', 'lines' => [$m[1]]];
                }
                continue;
            }

            $bullet = preg_match('/^\s{0,3}[-*+]\s+(.*)$/', $line, $bm) === 1;
            $numbered = preg_match('/^\s{0,3}\d{1,3}[.)]\s+(.*)$/', $line, $nm) === 1;
            if ($bullet || $numbered) {
                $ordered = $numbered;
                $text = $bullet ? $bm[1] : $nm[1];
                $last = count($blocks) - 1;
                if ($last >= 0 && $blocks[$last]['kind'] === 'list' && $blocks[$last]['ordered'] === $ordered) {
                    $blocks[$last]['items'][] = $text;
                } else {
                    $blocks[] = ['kind' => 'list', 'ordered' => $ordered, 'items' => [$text]];
                }
                continue;
            }

            $last = count($blocks) - 1;
            if ($last >= 0 && $blocks[$last]['kind'] === 'paragraph' && $blocks[$last]['lines'] !== []) {
                $blocks[$last]['lines'][] = $line;
            } else {
                $blocks[] = ['kind' => 'paragraph', 'lines' => [$line]];
            }
        }

        if ($fenced !== null) {
            $blocks[] = ['kind' => 'code', 'lines' => $fenced];
        }

        return array_values(array_filter(
            $blocks,
            static fn(array $b): bool => $b['kind'] !== 'paragraph' || $b['lines'] !== []
        ));
    }

    private static function inline(string $text): string
    {
        $pattern = '/(`[^`]+`)|(\*\*.+?\*\*)|(\*[^*\n]+\*)|(_[^_\n]+_)|(\[[^\]\n]+\]\([^()\s]+\))/s';
        $out = '';
        $rest = $text;

        while ($rest !== '') {
            if (preg_match($pattern, $rest, $m, PREG_OFFSET_CAPTURE) !== 1) {
                $out .= htmlspecialchars($rest, ENT_QUOTES, 'UTF-8');
                break;
            }

            $token = $m[0][0];
            $offset = $m[0][1];
            $out .= htmlspecialchars(substr($rest, 0, $offset), ENT_QUOTES, 'UTF-8');

            if (str_starts_with($token, '`')) {
                $out .= '<code>' . htmlspecialchars(substr($token, 1, -1), ENT_QUOTES, 'UTF-8') . '</code>';
            } elseif (str_starts_with($token, '**')) {
                $out .= '<strong>' . self::inline(substr($token, 2, -2)) . '</strong>';
            } elseif (str_starts_with($token, '[')) {
                $split = strpos($token, '](');
                $label = substr($token, 1, $split - 1);
                $href = substr($token, $split + 2, -1);
                // Anything that is not plainly a web or mail address is
                // rendered as text, not a link.
                if (preg_match('#^(https?://|mailto:)#i', $href) === 1) {
                    $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                        . '" target="_blank" rel="noopener noreferrer">'
                        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
                } else {
                    $out .= htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                }
            } else {
                $out .= '<em>' . self::inline(substr($token, 1, -1)) . '</em>';
            }

            $rest = substr($rest, $offset + strlen($token));
        }

        return $out;
    }
}
