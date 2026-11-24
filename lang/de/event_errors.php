<?php

declare(strict_types=1);

return [
    'event' => [
        'not_published' => 'Die Veranstaltung ist nicht für Anmeldungen geöffnet.',
        'full' => 'Die Veranstaltung ist ausgebucht.',
        'period' => 'Das Ende darf nicht vor dem Beginn liegen.',
        'capacity_below_confirmed' => 'Die Kapazität darf nicht unter den bestätigten Anmeldungen liegen.',
        'not_draft' => 'Nur eine Veranstaltung im Entwurf kann veröffentlicht werden.',
        'ticket_required' => 'Fügen Sie vor der Veröffentlichung mindestens eine aktive Ticketart hinzu.',
        'cannot_cancel' => 'Nur eine Veranstaltung im Entwurf oder veröffentlicht kann abgesagt werden.',
        'delete_draft_only' => 'Nur eine Veranstaltung im Entwurf ohne Anmeldungen kann gelöscht werden.',
        'code_taken' => 'Der Code :code wird bereits von einer anderen Veranstaltung verwendet.',
    ],
    'ticket' => [
        'unavailable' => 'Die Ticketart existiert nicht oder ist nicht im Verkauf.',
        'has_registrations' => 'Die Ticketart hat Anmeldungen: deaktivieren Sie sie stattdessen.',
    ],
    'registration' => [
        'already_cancelled' => 'Die Anmeldung ist bereits storniert.',
        'checked_in' => 'Eine Anmeldung mit Check-in kann nicht storniert werden.',
        'not_confirmed' => 'Nur eine bestätigte Anmeldung kann bezahlt werden.',
    ],
    'checkin' => [
        'unknown_token' => 'Keine Anmeldung passt zu diesem QR-Code.',
        'not_confirmed' => 'Nur eine bestätigte Anmeldung kann einchecken.',
        'already' => 'Dieser Teilnehmer hat bereits um :at eingecheckt.',
    ],
];
