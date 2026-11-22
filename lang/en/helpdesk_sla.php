<?php

declare(strict_types=1);

return [
    'target' => [
        'response' => 'first response',
        'next_update' => 'next update',
        'resolution' => 'resolution',
    ],
    'escalation' => [
        'warning_title' => 'SLA warning on :ticket: :percent% of the :target time used',
        'breach_title' => 'SLA breach on :ticket: :target time exceeded (:percent%)',
        'body' => 'Ticket :ticket (:subject) needs attention: the :target target is at :percent% of its time.',
    ],
];
