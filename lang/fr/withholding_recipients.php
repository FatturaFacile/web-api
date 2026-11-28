<?php

declare(strict_types=1);

return [
    'messages' => [
        'saved' => 'Données enregistrées.',
        'deleted' => 'Élément supprimé.',
        'inl_generated' => 'Communication préalable INL générée.',
        'inl_sent' => 'Communication INL enregistrée comme envoyée.',
        'payment_posted' => 'Paiement enregistré et F24 planifié.',
        'payments_posted' => 'Paiements enregistrés et F24 planifiés.',
        'returns_generated' => 'Attestations annuelles générées.',
    ],
    'errors' => [
        'inl_not_applicable' => 'Seuls les travailleurs occasionnels nécessitent la communication INL.',
        'inl_cancelled' => 'Une communication annulée ne peut pas être envoyée.',
        'inl_notification_missing' => 'Paiement bloqué : aucune communication préalable INL n\'a été envoyée pour ce travailleur.',
        'inl_notification_late' => 'Paiement bloqué : la communication INL a été envoyée après le début de la prestation.',
        'inl_notification_expired' => 'Paiement bloqué : la communication INL ne couvre pas la date de la prestation.',
        'recipient_inactive' => 'Le bénéficiaire n\'est pas actif.',
        'service_after_payment' => 'La prestation ne peut pas être postérieure au paiement.',
        'net_not_positive' => 'Le montant net à payer n\'est pas positif.',
        'regime_locked' => 'Le régime ne peut plus changer une fois des rémunérations enregistrées.',
        'recipient_has_settlements' => 'Un bénéficiaire avec des rémunérations enregistrées ne peut pas être supprimé.',
        'certificate_not_applicable' => 'Seuls les agents peuvent déclarer la retenue réduite.',
    ],
    'inl' => [
        'title' => 'Communication préalable de travail indépendant occasionnel',
        'legal_basis' => 'Art. 14 D.Lgs. 81/2008 — à envoyer à l\'inspection du travail (INL) avant le début de la prestation.',
        'client' => 'Mandant',
        'client_tax_id' => 'Code fiscal du mandant',
        'worker' => 'Prestataire',
        'worker_tax_id' => 'Code fiscal du prestataire',
        'activity' => 'Activité',
        'place' => 'Lieu de la prestation',
        'start' => 'Date de début',
        'end' => 'Date de fin',
        'compensation' => 'Rémunération estimée',
        'recipient' => 'Destinataire',
    ],
];
