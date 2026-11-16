<?php

declare(strict_types=1);

return [

    'greeting' => 'Bonjour :name,',
    'closing' => 'Cordialement',

    'payment_reminder' => [
        'subject' => [
            'level_1' => 'Rappel de paiement - facture :number',
            'level_2' => 'Relance de paiement - facture :number',
            'level_3' => 'Dernière relance de paiement - facture :number',
        ],
        'body' => [
            'level_1' => 'La facture <strong>:number</strong>, échue le :due_date, est toujours ouverte. Si vous avez déjà effectué le paiement, veuillez ne pas tenir compte de ce message.',
            'level_2' => 'Malgré notre précédent rappel, la facture <strong>:number</strong>, échue le :due_date, n\'est toujours pas payée. Nous vous prions de bien vouloir régler dans les meilleurs délais.',
            'level_3' => 'Ceci est la dernière relance pour la facture <strong>:number</strong>, échue le :due_date. À défaut de paiement, nous pourrons engager une procédure de recouvrement.',
        ],
        'amount_due' => 'Montant à payer : <strong>:amount :currency</strong>',
    ],

    'document' => [
        'subject' => [
            'invoice' => 'Facture :number',
            'quote' => 'Devis :number',
            'sales_order' => 'Confirmation de commande :number',
            'delivery_note' => 'Bon de livraison :number',
            'work_order' => 'Rapport d\'intervention :number',
        ],
        'intro' => [
            'invoice' => 'Voici les informations de la facture :number.',
            'quote' => 'Voici les informations du devis :number.',
            'sales_order' => 'Voici les informations de la commande :number.',
            'delivery_note' => 'Voici les informations du bon de livraison :number.',
            'work_order' => 'Voici les informations du rapport d\'intervention :number.',
            'document' => 'Voici les informations du document :number.',
        ],
        'number' => 'Numéro',
        'date' => 'Date',
        'total' => 'Total',
    ],

    'appointment_reminder' => [
        'subject' => 'Rappel de rendez-vous :number du :date',
        'body' => 'Nous vous rappelons le rendez-vous <strong>:title</strong> (:number) prévu le :date à :time.',
        'address' => 'Adresse : :address',
    ],

    'portal_activity' => [
        'quote_approved' => [
            'subject' => 'Devis :number approuvé par le client',
            'body' => ':customer a approuvé le devis :number depuis le portail client.',
        ],
        'quote_rejected' => [
            'subject' => 'Devis :number refusé par le client',
            'body' => ':customer a refusé le devis :number depuis le portail client.',
            'reason' => 'Motif : :reason',
        ],
        'ticket_replied' => [
            'subject' => 'Nouvelle réponse du client au ticket :number',
            'body' => ':customer a répondu au ticket :number depuis le portail client.',
        ],
    ],

    'subscription_renewal' => [
        'subject' => 'Renouvellement de l\'abonnement :number',
        'body' => 'Votre abonnement <strong>:name</strong> (:number) se termine le :end_date.',
        'auto_renew' => 'Il sera renouvelé automatiquement.',
        'manual_renew' => 'Il ne sera pas renouvelé automatiquement : veuillez nous contacter si vous souhaitez continuer.',
    ],

    'account_statement' => [
        'subject' => 'Relevé de compte au :date',
        'intro' => 'Voici votre relevé de compte au :date.',
        'columns' => [
            'invoice' => 'Facture',
            'due_date' => 'Échéance',
            'balance' => 'Solde',
        ],
        'total_due' => 'Total dû',
        'overdue' => 'Dont échu',
        'no_open_items' => 'Il n\'y a aucun poste ouvert.',
    ],

];
