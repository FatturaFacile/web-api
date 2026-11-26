<?php

declare(strict_types=1);

return [
    'export' => [
        'pdf' => [
            'title' => 'Export du registre d\'audit - résumé',
            'tenant' => 'Entreprise',
            'period' => 'Période',
            'entries' => 'Entrées',
            'sequences' => 'Séquences',
            'start_hash' => 'Hash de la première entrée',
            'end_hash' => 'Hash de la dernière entrée',
            'verification' => 'Vérification de la chaîne',
            'generated' => 'Généré le',
            'note' => 'Le SHA-256 de ce paquet est signé. Vérifiez la chaîne avec les hashs de manifest.json.',
        ],
    ],
    'alerts' => [
        'chain_broken' => [
            'title' => 'Alerte d\'intégrité de la piste d\'audit',
            'body' => 'La vérification de la chaîne d\'audit a échoué (:outcome, séquence :sequence). Ouvrez les paramètres d\'audit et lancez la procédure d\'incident.',
        ],
    ],
];
