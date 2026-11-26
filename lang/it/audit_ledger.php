<?php

declare(strict_types=1);

return [
    'export' => [
        'pdf' => [
            'title' => 'Esportazione del registro di audit - riepilogo',
            'tenant' => 'Azienda',
            'period' => 'Periodo',
            'entries' => 'Registrazioni',
            'sequences' => 'Sequenze',
            'start_hash' => 'Hash della prima registrazione',
            'end_hash' => 'Hash dell\'ultima registrazione',
            'verification' => 'Verifica della catena',
            'generated' => 'Generato il',
            'note' => 'Lo SHA-256 di questo pacchetto è firmato. Verifica la catena con gli hash di manifest.json.',
        ],
    ],
    'alerts' => [
        'chain_broken' => [
            'title' => 'Allarme integrità dell\'audit trail',
            'body' => 'La verifica della catena di audit è fallita (:outcome, sequenza :sequence). Apri le impostazioni di audit e avvia la procedura di incidente.',
        ],
    ],
];
