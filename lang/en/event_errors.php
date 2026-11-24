<?php

declare(strict_types=1);

return [
    'event' => [
        'not_published' => 'The event is not open for registration.',
        'full' => 'The event is full.',
        'period' => 'The end must not be before the start.',
        'capacity_below_confirmed' => 'The capacity cannot be lower than the confirmed registrations.',
        'not_draft' => 'Only a draft event can be published.',
        'ticket_required' => 'Add at least one active ticket type before publishing.',
        'cannot_cancel' => 'Only a draft or published event can be cancelled.',
        'delete_draft_only' => 'Only a draft event without registrations can be deleted.',
        'code_taken' => 'The code :code is already used by another event.',
    ],
    'ticket' => [
        'unavailable' => 'The ticket type does not exist or is not on sale.',
        'has_registrations' => 'The ticket type has registrations: switch it off instead.',
    ],
    'registration' => [
        'already_cancelled' => 'The registration is already cancelled.',
        'checked_in' => 'A registration that is checked in cannot be cancelled.',
        'not_confirmed' => 'Only a confirmed registration can be paid.',
    ],
    'checkin' => [
        'unknown_token' => 'No registration matches this QR code.',
        'not_confirmed' => 'Only a confirmed registration can check in.',
        'already' => 'This attendee already checked in at :at.',
    ],
];
