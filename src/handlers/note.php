<?php

declare(strict_types=1);

/**
 * The one field written after a log entry is inserted, and only while it is
 * still empty. Nothing in the log is ever reworded or removed.
 */
function cover_note(App $app): void
{
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($payload)) {
        App::jsonError('That could not be saved. Try again.', 400);
    }

    $id = $payload['id'] ?? null;
    $note = is_string($payload['note'] ?? null) ? trim($payload['note']) : '';

    if (!is_int($id) || $id <= 0) {
        App::jsonError('That could not be saved. Try again.', 400);
    }
    if ($note === '') {
        App::jsonError('Write something first.', 400);
    }
    if (strlen($note) > 5000) {
        App::jsonError('That note is too long. Keep it to the decision itself.', 400);
    }

    try {
        $result = $app->logBook()->addNote($id, $note);
    } catch (DatabaseError $e) {
        App::jsonError($e->getMessage(), 503);
    }

    if ($result === 'not_found') {
        App::jsonError('That log entry is no longer there.', 404);
    }
    if ($result === 'already_noted') {
        App::jsonError('A note is already on that entry. The log cannot be edited once written.', 409);
    }

    App::json(['ok' => true]);
}
