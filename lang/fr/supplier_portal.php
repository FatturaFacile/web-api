<?php

declare(strict_types=1);

return [
    'mail' => [
        'access_subject' => 'Votre accès au portail fournisseurs',
        'access_line' => "Utilisez le lien personnel ci-dessous pour ouvrir le portail fournisseurs : inscription, documents, commandes, demandes d'offre et factures.",
    ],
    'notification' => [
        'expiring' => [
            'subject' => 'Document bientôt expiré : :supplier',
            'body' => 'Le document ":document" du fournisseur :supplier expire le :date (dans :days jours).',
        ],
        'expired' => [
            'subject' => 'Document expiré : :supplier',
            'body' => 'Le document ":document" du fournisseur :supplier a expiré le :date.',
        ],
    ],
    'validation' => [
        'vat_number_invalid' => "Le numéro de TVA ou d'IDE n'est pas valide pour le pays indiqué.",
    ],
];
