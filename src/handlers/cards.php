<?php

declare(strict_types=1);

/**
 * Card create, edit and inline status change (BRIEF.md §3 and §8).
 *
 * Plain form posts rather than fetch: the card form is where required fields
 * are enforced and where the Monday rejection has to state its reason inline,
 * and a server-rendered form does both without depending on JavaScript.
 */

function cover_card_new(App $app): void
{
    $app->render('card-form', [
        'title' => 'New card — Marketing cover',
        'card' => null,
        'values' => [
            'title' => '',
            'context_key' => '',
            'stream' => '',
            'due_date' => '',
            'publish_time' => '',
            'channel' => '',
            'status' => 'not_started',
            'priority' => 'normal',
            'asana_url' => '',
            'notes' => '',
        ],
        'errors' => [],
    ]);
}

function cover_card_edit(App $app, int $id): void
{
    $card = $app->cards()->find($id);
    if ($card === null) {
        http_response_code(404);
        $app->render('notfound', ['title' => 'Not found — Marketing cover']);
    }

    $app->render('card-form', [
        'title' => 'Card — Marketing cover',
        'card' => $card,
        'values' => $card,
        'errors' => [],
    ]);
}

function cover_card_save(App $app, ?int $id): void
{
    $result = Cards::validate($_POST);

    if ($result['errors'] !== []) {
        // Re-render with what she typed still in the boxes and the reason next
        // to the field that caused it.
        http_response_code(422);
        $app->render('card-form', [
            'title' => ($id === null ? 'New card' : 'Card') . ' — Marketing cover',
            'card' => $id === null ? null : $app->cards()->find($id),
            'values' => array_merge($result['values'], ['id' => $id]),
            'errors' => $result['errors'],
        ]);
    }

    if ($id === null) {
        $newId = $app->cards()->create($result['values']);
        App::redirect('/cards/' . $newId . '?saved=1');
    }

    $app->cards()->update($id, $result['values']);
    App::redirect('/cards/' . $id . '?saved=1');
}

/**
 * Inline status change. Posts from the list view and the day view, and returns
 * to wherever it was pressed so she does not lose her place.
 */
function cover_card_status(App $app, int $id): void
{
    $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $app->cards()->setStatus($id, $status);

    $back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '/cards';
    // Only ever back into this app, never to a URL someone else supplied.
    if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
        $back = '/cards';
    }

    App::redirect($back);
}

/** All cards, all fields from §3 (BRIEF.md §10). */
function cover_cards_csv(App $app): void
{
    $rows = [];
    foreach ($app->cards()->all() as $card) {
        $rows[] = [
            (int) $card['id'],
            (string) $card['title'],
            Contexts::name((string) $card['context_key']),
            Cards::STREAM_LABELS[(string) $card['stream']] ?? (string) $card['stream'],
            (string) $card['due_date'],
            $card['publish_time'] === null ? null : (string) $card['publish_time'],
            $card['channel'] === null ? null : (string) $card['channel'],
            Cards::STATUS_LABELS[(string) $card['status']] ?? (string) $card['status'],
            Cards::PRIORITY_LABELS[(string) $card['priority']] ?? (string) $card['priority'],
            $card['asana_url'] === null ? null : (string) $card['asana_url'],
            $card['notes'] === null ? null : (string) $card['notes'],
            Clock::forCsv($card['created_at'] === null ? null : (string) $card['created_at']),
            Clock::forCsv($card['completed_at'] === null ? null : (string) $card['completed_at']),
        ];
    }

    $csv = Csv::build(
        ['id', 'title', 'context', 'stream', 'due_date', 'publish_time', 'channel',
         'status', 'priority', 'asana_url', 'notes', 'created_at', 'completed_at'],
        $rows
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . Csv::filename('marketing-cover-cards') . '"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}
