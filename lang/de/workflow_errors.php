<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Delegierende und vertretende Person dürfen nicht dieselbe sein.',
        'circular' => 'Zirkuläre Delegation abgelehnt: Die vertretende Person hat bereits an Sie delegiert.',
        'overlap' => 'Sie haben bereits eine aktive Delegation, die einen Teil dieses Zeitraums abdeckt.',
    ],
    'inbox' => [
        'period_too_long' => 'Der Zeitraum darf nicht länger als 366 Tage sein.',
    ],
    'definition' => [
        'publish_to_activate' => 'Veröffentlichen Sie die Definition, um sie zu aktivieren.',
        'has_running_instances' => 'Die Definition hat laufende Instanzen: Brechen Sie diese ab oder deaktivieren Sie die Definition.',
        'graph_locked' => 'Nur eine Definition im Entwurf kann ihren Graphen ändern: Erstellen Sie eine neue Version.',
    ],
];
