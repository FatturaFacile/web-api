<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation_subject' => 'Demande d\'offre :number — :title',
        'invitation_greeting' => 'Bonjour :supplier,',
        'invitation_intro' => ':buyer vous invite à soumettre une offre pour la demande d\'offre :number (:title).',
        'invitation_deadline' => 'Date limite de soumission : :deadline (UTC).',
        'invitation_cta' => 'Ouvrir le portail d\'appel d\'offres',
        'invitation_outro' => 'Ce lien sécurisé est personnel à votre entreprise et expire automatiquement à la date limite.',

        'deadline_extended_subject' => 'Prolongation du délai : Demande d\'offre :number — :title',
        'deadline_extended_body' => 'La date limite de la demande d\'offre :number (:title) émise par :buyer a été prolongée au :deadline (UTC).',

        'award_subject' => 'Notification d\'attribution : Demande d\'offre :number — :title',
        'award_greeting' => 'Félicitations :supplier,',
        'award_body_full' => ':buyer vous a attribué l\'intégralité de la demande d\'offre :number (:title).',
        'award_body_partial' => ':buyer vous a attribué certaines lignes de la demande d\'offre :number (:title).',
        'award_po_numbers' => 'Commande(s) d\'achat générée(s) : :orders',

        'regret_subject' => 'Résultat de l\'appel d\'offres : :number — :title',
        'regret_greeting' => 'Madame, Monsieur (:supplier),',
        'regret_body' => 'Nous vous remercions pour votre participation à la demande d\'offre :number (:title) émise par :buyer. Après évaluation, le marché a été attribué à un autre fournisseur.',

        'cancelled_subject' => 'Avis d\'annulation : Demande d\'offre :number — :title',
        'cancelled_body' => 'Veuillez noter que la demande d\'offre :number (:title) émise par :buyer a été annulée.',
        'cancelled_reason' => 'Motif : :reason',
    ],
];
