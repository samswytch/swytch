<?php

declare(strict_types=1);

/**
 * BRIEF.md §11: "Errors are legible. Every failure mode gets a plain-English
 * message telling her what to do — not a stack trace, not a spinner that never
 * resolves."
 *
 * So: display_errors stays off, everything is written to the log file in the
 * data directory, and what reaches the screen is a sentence she can act on.
 */
final class Errors
{
    public static function install(string $logPath): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', $logPath);
        error_reporting(E_ALL);

        set_exception_handler(static function (Throwable $e): void {
            error_log('Unhandled: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' .
                $e->getFile() . ':' . $e->getLine());
            self::render(
                'Something went wrong and the app could not finish that. Try again. If it keeps ' .
                'happening, work from Asana and the authority envelope document and carry on — ' .
                'do not spend the day trying to fix it.'
            );
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * Before the configuration is readable there is no log file to write to, so
     * this one goes to the server's own error log and says what to fix.
     */
    public static function renderBootFailure(string $message): void
    {
        error_log('Cover app failed to start: ' . $message);
        self::render($message, 'The app is not set up yet');
    }

    public static function render(string $message, string $title = 'Something went wrong'): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!doctype html>
<html lang="en-GB">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle}</title>
<link rel="stylesheet" href="/assets/app.css"></head>
<body>
<main class="main">
<h1 class="page-title">{$safeTitle}</h1>
<p class="notice">{$safeMessage}</p>
</main>
</body>
</html>
HTML;
        exit;
    }
}
