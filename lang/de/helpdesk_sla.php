<?php

declare(strict_types=1);

return [
    'target' => [
        'response' => 'Erstreaktion',
        'next_update' => 'nächstes Update',
        'resolution' => 'Lösung',
    ],
    'escalation' => [
        'warning_title' => 'SLA-Warnung bei :ticket: :percent% der Zeit für :target verbraucht',
        'breach_title' => 'SLA-Verletzung bei :ticket: Zeit für :target überschritten (:percent%)',
        'body' => 'Ticket :ticket (:subject) braucht Aufmerksamkeit: Das Ziel für :target liegt bei :percent% der Zeit.',
    ],
];
