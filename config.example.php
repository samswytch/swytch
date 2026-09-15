<?php

/**
 * Copy to /home/om44wfu4/appdata/config.php on the server and fill in.
 *
 * This file lives OUTSIDE the web root and is never committed. It is included
 * by absolute path from src/Config.php.
 */

return [
    // ---- Anthropic -------------------------------------------------------
    // Server-side only. This value must never reach the browser.
    'anthropic_api_key' => '',

    // Confirmed against https://docs.claude.com/en/api/overview on
    // 14 September 2026: claude-opus-5 is the current model.
    'anthropic_model' => 'claude-opus-5',

    // How hard the model works per reply: low, medium, high, xhigh, max.
    // Higher is slower. Replies are blocking, and curl gives up at 90s, so
    // medium is the setting that comfortably fits.
    'assistant_effort' => 'medium',

    // ---- The shared password --------------------------------------------
    // One password for both users (BRIEF.md §11). Generate the hash with:
    //   php -r 'echo password_hash("the password here", PASSWORD_DEFAULT), "\n";'
    // Never store the password itself.
    'password_hash' => '',

    // ---- Caps ------------------------------------------------------------
    'daily_message_cap' => 200,
    'session_message_cap' => 50,

    // ---- Paths -----------------------------------------------------------
    'data_dir' => '/home/om44wfu4/appdata',

    // ---- Transport flags -------------------------------------------------
    // Both default to false on purpose. Turning either on before the
    // certificate exists locks you out of your own app. Flip them to true
    // once https://plan.swytch.graphics loads without a warning.
    'cookie_secure' => false,
    'force_https' => false,
];
