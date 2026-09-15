<?php

declare(strict_types=1);

/**
 * Boot. Included by public/index.php, and by the scripts in bin/.
 *
 * Deliberately no autoloader and no Composer: Laravel and Symfony both need
 * mbstring and fileinfo, and this box has neither guaranteed. Twelve requires
 * are more boring than a dependency tree nobody can update for three weeks.
 */

const APP_ROOT = __DIR__ . '/..';

require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Errors.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Migrate.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Contexts.php';
require_once __DIR__ . '/Content.php';
require_once __DIR__ . '/Prompt.php';
require_once __DIR__ . '/Outcomes.php';
require_once __DIR__ . '/Markdown.php';
require_once __DIR__ . '/Csv.php';
require_once __DIR__ . '/LogBook.php';
require_once __DIR__ . '/Usage.php';
require_once __DIR__ . '/Conversation.php';
require_once __DIR__ . '/Anthropic.php';
require_once __DIR__ . '/App.php';

/**
 * The server runs on UTC. Set this before anything formats a date, so the
 * 25 October clock change — which falls inside the cover period — is handled by
 * the timezone database rather than by an assumed offset.
 */
date_default_timezone_set(Clock::ZONE);

/**
 * Where config.php lives. Outside the web root, so the API key cannot be served
 * by the web server even if a rewrite rule is wrong one day.
 *
 * COVER_CONFIG lets the test harness and the local server point somewhere else;
 * on the real host nothing sets it and the default path is used.
 */
function cover_config_path(): string
{
    $override = getenv('COVER_CONFIG');

    return is_string($override) && $override !== ''
        ? $override
        : '/home/om44wfu4/appdata/config.php';
}

function cover_boot(): App
{
    try {
        $config = Config::load(cover_config_path());
    } catch (Throwable $e) {
        Errors::renderBootFailure($e->getMessage());
        exit;
    }

    Errors::install($config->logPath());

    return new App($config);
}
