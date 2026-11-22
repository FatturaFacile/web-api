<?php

declare(strict_types=1);

return [
    'target' => [
        'response' => 'prima risposta',
        'next_update' => 'prossimo aggiornamento',
        'resolution' => 'risoluzione',
    ],
    'escalation' => [
        'warning_title' => 'Avviso SLA su :ticket: usato il :percent% del tempo di :target',
        'breach_title' => 'Violazione SLA su :ticket: tempo di :target superato (:percent%)',
        'body' => 'Il ticket :ticket (:subject) richiede attenzione: l\'obiettivo di :target è al :percent% del tempo.',
    ],
];
