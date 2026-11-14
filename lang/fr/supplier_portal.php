<?php

declare(strict_types=1);

return [
    'mail' => [
        'access_subject' => 'Votre acces au portail fournisseurs',
        'access_line' => "Utilisez le lien personnel ci-dessous pour ouvrir le portail fournisseurs : inscription, documents, commandes, demandes d'offre et factures.",
    ],
    'notification' => [
        'expiring' => [
            'subject' => 'Document bientot expire : :supplier',
            'body' => 'Le document ":document" du fournisseur :supplier expire le :date (dans :days jours).',
        ],
        'expired' => [
            'subject' => 'Document expire : :supplier',
            'body' => 'Le document ":document" du fournisseur :supplier a expire le :date.',
        ],
    ],
    'validation' => [
        'vat_number_invalid' => "Le numero de TVA ou d'IDE n'est pas valide pour le pays indique.",
    ],
];
