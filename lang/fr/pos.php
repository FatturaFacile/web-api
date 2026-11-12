<?php

declare(strict_types=1);

return [

    'terminal_label' => 'Terminal de caisse',
    'terminal_inactive' => "Le terminal de caisse n'est pas actif.",
    'terminal_busy' => 'Le terminal a déjà une session ouverte.',
    'session_closed' => "La session de caisse n'est pas ouverte.",
    'cash_tendered_too_low' => "L'espèce reçue ne couvre pas le total de la vente.",
    'mixed_split_invalid' => 'Pour les paiements mixtes, le montant en espèces doit être positif et inférieur au total.',
    'prepaid_card_required' => 'Les paiements prépayés nécessitent un bon ou une carte prépayée.',
    'already_refunded' => 'Cette vente a déjà été remboursée.',
    'already_transmitted' => "Une vente déjà transmise à l'imprimante fiscale ne peut pas être remboursée ici.",
    'refund_session_closed' => "Les ventes d'une session clôturée ne peuvent pas être remboursées.",
    'rt_already_transmitted' => "Cette vente a déjà été transmise à l'imprimante fiscale.",
    'rt_refunded_sale' => 'Une vente remboursée ne peut pas être transmise.',
    'rt_simulated_notice' => "Imprimante fiscale simulée : rien n'a été envoyé à l'Agenzia delle Entrate.",
    'refund_note' => 'Remboursement du ticket :number',
    'earn_note' => 'Points pour le ticket :number',
    'redeem_note' => 'Paiement du ticket :number',

    'messages' => [
        'terminal_created' => 'Terminal de caisse créé avec succès',
        'session_opened' => 'Session de caisse ouverte',
        'session_closed' => 'Session de caisse clôturée',
        'session_report' => 'Rapport de la session de caisse récupéré avec succès',
        'sale_registered' => 'Vente enregistrée avec succès',
        'sale_retrieved' => 'Vente récupérée avec succès',
        'sale_refunded' => 'Vente remboursée avec succès',
        'rt_transmitted' => "Vente transmise à l'imprimante fiscale",
        'rt_simulated' => "Vente enregistrée par l'imprimante fiscale simulée : rien n'a été envoyé à l'Agenzia delle Entrate",
        'rt_not_transmitted' => "L'imprimante fiscale n'a pas encore accepté la vente : le ticket est conservé et peut être renvoyé",
    ],

];
