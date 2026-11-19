<?php

declare(strict_types=1);

return [
    'mail' => [
        'magic_link' => [
            'subject' => 'Votre lien de connexion pour :company',
            'greeting' => 'Bonjour :name',
            'intro' => "Utilisez le bouton ci-dessous pour ouvrir votre espace client chez :company. Le lien ne fonctionne qu'une fois et expire dans :minutes minutes.",
            'action' => 'Ouvrir mon espace',
            'security' => "Si vous n'avez pas demandé ce lien, vous pouvez ignorer cet e-mail.",
        ],
        'otp' => [
            'subject' => 'Votre code de vérification pour :company',
            'greeting' => 'Bonjour :name',
            'intro' => 'Saisissez ce code pour terminer la connexion à :company. Il expire dans :minutes minutes.',
            'security' => "Ne communiquez jamais ce code. Si vous n'avez pas tenté de vous connecter, ignorez cet e-mail.",
        ],
    ],
    'messages' => [
        'link_requested' => "Si l'adresse appartient à un client, un lien de connexion a été envoyé.",
        'otp_required' => 'Un code de vérification a été envoyé à votre adresse e-mail.',
        'authenticated' => 'Connexion réussie.',
        'logged_out' => 'Déconnexion réussie.',
        'checkout_created' => 'Page de paiement créée.',
        'ticket_created' => 'Votre demande a été envoyée.',
    ],
    'errors' => [
        'otp_invalid' => "Le code de vérification n'est pas correct.",
        'otp_locked' => 'Trop de codes erronés. Demandez un nouveau lien de connexion.',
        'session_required' => "Connectez-vous à l'espace client pour continuer.",
        'invoice_not_payable' => "Cette facture n'a plus rien à payer en ligne.",
        'xml_not_available' => "Aucun fichier de facture électronique n'existe pour ce document.",
        'ticket_target_invalid' => "Le document lié n'appartient pas à votre compte.",
    ],
];
