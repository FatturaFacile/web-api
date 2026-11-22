<?php

declare(strict_types=1);

return [
    'target' => [
        'response' => 'première réponse',
        'next_update' => 'prochaine mise à jour',
        'resolution' => 'résolution',
    ],
    'escalation' => [
        'warning_title' => 'Alerte SLA sur :ticket : :percent% du délai de :target utilisé',
        'breach_title' => 'Violation du SLA sur :ticket : délai de :target dépassé (:percent%)',
        'body' => 'Le ticket :ticket (:subject) demande votre attention : l\'objectif de :target est à :percent% du délai.',
    ],
];
