<?php

declare(strict_types=1);

return [
    'agreement' => [
        'bonus_required' => 'Saisissez l\'objectif et le montant de la prime fixe.',
        'contributions_required' => 'Ajoutez au moins une contribution avant d\'activer l\'accord.',
        'version_conflict' => 'L\'accord a été modifié par quelqu\'un d\'autre. Rechargez-le et réessayez.',
        'not_active' => 'L’accord n’est pas actif.',
        'locked' => 'Seuls le nom, les notes et la date de fin d’un accord qui n’est plus un brouillon peuvent être modifiés.',
        'period' => 'La date de fin ne peut pas précéder la date de début.',
        'not_draft' => 'Seul un accord en brouillon peut être activé.',
        'tiers_required' => 'Ajoutez au moins un palier avant d’activer l’accord.',
        'delete_draft_only' => 'Seul un accord en brouillon sans provisions peut être supprimé.',
        'code_taken' => 'Le code :code est déjà utilisé par un autre accord.',
        'tiers_distinct' => 'Chaque palier nécessite un seuil différent.',
    ],
    'accrual' => [
        'date_outside' => 'La date doit se situer dans la période de l’accord (:from au :to).',
    ],
    'settlement' => [
        'purchase_only' => 'Les propositions de règlement ne sont disponibles que pour les accords fournisseurs (achats).',
        'invoice_required' => 'Choisissez la facture du fournisseur sur laquelle compenser la ristourne.',
        'invoice_not_found' => 'La facture n\'existe pas ou n\'appartient pas au fournisseur de l\'accord.',
        'insufficient_open_balance' => 'Le solde ouvert de la facture (:open) ne couvre pas la ristourne.',
        'invalid_transition' => 'Cette étape n\'est pas autorisée pour une proposition au statut :status.',
        'version_conflict' => 'La proposition a été modifiée par quelqu\'un d\'autre. Rechargez-la et réessayez.',
        'vat_account_required' => 'Choisissez le compte de TVA pour comptabiliser la TVA de la ristourne.',
        'charge_note' => 'Ristourne fournisseur :name (:code)',
        'charge_line' => 'Ristourne fournisseur :name, période du :from au :to',
        'set_off_description' => 'Ristourne :code compensée avec la facture :invoice',
        'nothing_to_settle' => 'Aucune remise provisionnée à régler.',
        'tax_rate_required' => 'Indiquez le taux de TVA de l’avoir.',
        'credit_note_note' => 'Remise de volume :name (:code)',
        'credit_note_line' => 'Remise de volume :name, période du :from au :to',
    ],
    'contribution' => [
        'agreement_closed' => 'L\'accord est clos : il n\'est plus possible d\'ajouter des contributions.',
        'locked' => 'Seule une contribution planifiée peut être modifiée ou supprimée.',
    ],
    'run' => [
        'purchase_only' => 'Les calculs de régularisation ne sont disponibles que pour les accords fournisseurs (achats).',
        'period_locked' => 'La période est verrouillée : un calcul de régularisation la couvre déjà jusqu\'au :date. Annulez d\'abord ce calcul.',
        'not_posted' => 'Seul un calcul de régularisation comptabilisé peut être annulé.',
        'not_latest' => 'Seul le dernier calcul de régularisation de l\'accord peut être annulé.',
        'already_settled' => 'Les régularisations de ce calcul font déjà partie d\'une proposition de règlement.',
        'journal_description' => 'Régularisation ristourne fournisseur :code jusqu\'au :to',
    ],
    'promotion' => [
        'percentage_max' => 'Une remise en pourcentage ne peut pas dépasser 100.',
        'period' => 'La date de fin ne peut pas précéder la date de début.',
        'code_taken' => 'Le code :code est déjà utilisé par une autre promotion.',
    ],
];
