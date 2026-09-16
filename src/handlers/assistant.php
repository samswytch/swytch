<?php

declare(strict_types=1);

/**
 * The assistant (BRIEF.md §6). The Anthropic API key is used here and nowhere
 * else — it never reaches the browser.
 *
 * One blocking request in, one JSON reply out. The reply carries both the raw
 * text, which the browser keeps so it can be re-sent as conversation history,
 * and the HTML rendered by this app's own markdown renderer, so there is no
 * second markdown implementation in the browser to drift from the one the log
 * screen uses.
 */
function cover_assistant(App $app): void
{
    // The probe measured a 115-second request returning intact and
    // max_execution_time is 300, so this is headroom over curl's 90, not a hope.
    set_time_limit(180);

    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($payload)) {
        App::jsonError('That request could not be read. Reload the page and try again.', 400);
    }

    $conversationId = is_string($payload['conversationId'] ?? null) ? trim($payload['conversationId']) : '';
    if ($conversationId === '' || strlen($conversationId) > 100) {
        App::jsonError('That request could not be read. Reload the page and try again.', 400);
    }

    // §6: "She picks a context, or it is inherited from the card." When a card
    // is named, the card decides — the browser's own contextKey is not trusted
    // to agree with it.
    $card = null;
    if (isset($payload['cardId']) && $payload['cardId'] !== null && $payload['cardId'] !== '') {
        $cardId = is_int($payload['cardId']) ? $payload['cardId'] : (int) $payload['cardId'];
        $card = $cardId > 0 ? $app->cards()->find($cardId) : null;
        if ($card === null) {
            App::jsonError('That card is no longer there. Start a new question.', 400);
        }
    }

    $contextKey = $card !== null
        ? (string) $card['context_key']
        : (is_string($payload['contextKey'] ?? null) ? $payload['contextKey'] : null);

    $context = Contexts::find($contextKey);
    if ($context === null) {
        App::jsonError('Pick which brand this is about before sending.', 400);
    }

    try {
        $messages = Conversation::parse($payload['messages'] ?? null);
    } catch (ValidationError $e) {
        App::jsonError($e->getMessage(), $e->status());
    }

    // Everything below this point costs money, so the caps are checked first.
    try {
        $capped = $app->usage()->checkAndRecord($conversationId);
        if (!$capped['ok']) {
            App::jsonError($capped['message'], 429);
        }
        $systemBlocks = $app->prompt()->systemBlocks($context, $card);
    } catch (DatabaseError | ContentError $e) {
        App::jsonError($e->getMessage(), 503);
    }

    try {
        $reply = $app->anthropic()->ask($systemBlocks, $messages);
    } catch (AssistantError $e) {
        App::jsonError($e->getMessage(), 502);
    }

    $result = Outcomes::extract($reply['text']);
    $text = $result['text'];
    $outcome = $result['outcome'];

    if (trim($text) === '') {
        App::jsonError('Claude returned an empty answer. Try again.', 502);
    }

    $logId = null;
    $logError = null;
    $kind = Outcomes::logKind($outcome);

    if ($kind !== null) {
        try {
            $logId = $app->logBook()->add(
                $kind,
                $context['key'],
                Conversation::lastUserText($messages),
                $text
            );
        } catch (Throwable $e) {
            // The answer is worth more than the log entry, so it is still
            // delivered — with a plain warning that the record did not stick.
            error_log('Could not write the log entry: ' . $e->getMessage());
            $logError = 'This could not be written to the log. Note it down for Sam yourself before you move on.';
        }
    }

    $noteLabels = [
        'decision' => ['What you decided. Optional.', 'Ran it with the second headline.'],
        'parked' => ['Your note on this. Optional.', 'Drafted it; holding until the 26th.'],
        'ask_kev' => ['What Kev said, once you have asked him. Optional.', 'Kev said quote £340, lead time two weeks.'],
    ];
    $noteKind = $kind ?? 'decision';

    App::json([
        'text' => $text,
        'html' => Markdown::toHtml($text),
        'outcome' => $outcome,
        'outcomeLabel' => Outcomes::label($outcome),
        'logId' => $logId,
        'logError' => $logError,
        'noteKind' => $noteKind,
        'noteLabel' => $noteLabels[$noteKind][0] ?? $noteLabels['decision'][0],
        'notePlaceholder' => $noteLabels[$noteKind][1] ?? $noteLabels['decision'][1],
        'noteHeading' => LogBook::noteLabel($noteKind),
    ]);
}
