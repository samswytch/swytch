<?php

declare(strict_types=1);

/**
 * The authority envelope and the brand packs are data, not code.
 *
 * They are read from disk on every request. They are never parsed,
 * restructured, cached in a static, or copied into the database — editing the
 * markdown is the whole editing story. See CLAUDE.md, "The content/ directory
 * is data, not code".
 *
 * On the server they sit outside the web root, so they are readable by PHP and
 * not servable by LiteSpeed. They are internal strategy documents; nothing
 * should be able to fetch them over HTTP.
 */
final class Content
{
    private string $dir;

    public function __construct(string $contentDir)
    {
        $this->dir = rtrim($contentDir, '/');
    }

    /** Loaded into the system prompt on every assistant request, whatever the context. */
    public function envelope(): string
    {
        return $this->read('authority-envelope.md');
    }

    /**
     * Loaded alongside the envelope, for the context she selected.
     *
     * @param array{key:string,name:string,colour:string,pack:string,internal:bool} $context
     */
    public function brandPack(array $context): string
    {
        return $this->read('brand-packs/' . $context['pack']);
    }

    private function read(string $relativePath): string
    {
        $absolute = $this->dir . '/' . $relativePath;
        $text = is_readable($absolute) ? file_get_contents($absolute) : false;

        if ($text === false) {
            throw new ContentError(
                "content/{$relativePath} could not be read on this deployment. The assistant cannot " .
                "answer without it. Check the deploy copied the content directory to {$this->dir}."
            );
        }
        if (trim($text) === '') {
            throw new ContentError(
                "content/{$relativePath} is empty. The assistant cannot answer without it."
            );
        }

        return $text;
    }
}

final class ContentError extends RuntimeException
{
}
