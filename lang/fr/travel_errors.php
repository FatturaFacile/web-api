<?php

declare(strict_types=1);

return [
    'trip' => [
        'not_editable' => 'Le déplacement ne peut plus être modifié dans son statut actuel.',
        'only_draft_editable' => 'Seul un déplacement en brouillon peut être modifié : retire-le ou rouvre-le d\'abord.',
        'only_draft_deletable' => 'Seul un déplacement en brouillon peut être supprimé.',
        'submit_only_draft' => 'Seul un déplacement en brouillon peut être soumis.',
        'overlaps_another' => 'L\'employé a déjà un déplacement soumis, approuvé ou terminé à ces dates.',
        'invalid_transition' => 'Un déplacement au statut :from ne peut pas passer à :to.',
        'not_started' => 'Le déplacement n\'a pas encore commencé.',
        'reconcile_only_completed' => 'Seul un déplacement terminé peut être réconcilié.',
        'pending_expenses' => ':count note(s) de frais liée(s) attend(ent) encore une approbation : approuve-les ou dissocie-les d\'abord.',
        'own_only' => 'Tu ne peux demander des déplacements que pour toi-même.',
        'return_before_departure' => 'L\'heure de retour doit être postérieure à l\'heure de départ.',
    ],
    'advance' => [
        'exceeds_budget' => 'L\'avance ne peut pas dépasser le budget estimé.',
        'not_payable_now' => 'L\'avance ne peut être enregistrée que pour un déplacement approuvé ou terminé.',
        'none' => 'Le déplacement n\'a pas d\'avance.',
        'already_paid' => 'L\'avance a déjà été enregistrée comme payée.',
    ],
    'rate' => [
        'per_diem_exists' => 'Une indemnité journalière existe déjà pour cette juridiction, cette année, ce périmètre et ce pays.',
        'mileage_exists' => 'Une indemnité kilométrique existe déjà pour cette juridiction, cette année et ce type de véhicule.',
    ],
    'per_diem' => [
        'day_outside_trip' => 'Un jour est en dehors des dates du déplacement.',
        'rate_missing' => 'Aucune indemnité journalière pour :jurisdiction en :year : ajoute-la au barème.',
        'currency_mismatch' => 'L\'indemnité journalière est en :rate mais le déplacement est en :trip.',
    ],
    'mileage' => [
        'date_outside_trip' => 'Le trajet est en dehors des dates du déplacement.',
        'rate_missing' => 'Aucune indemnité kilométrique pour :jurisdiction en :year pour ce véhicule : ajoute-la au barème.',
        'currency_mismatch' => 'L\'indemnité kilométrique est en :rate mais le déplacement est en :trip.',
    ],
    'leg' => [
        'currency_mismatch' => 'Le coût d\'une étape doit être dans la devise du déplacement (:trip).',
        'hotel_needs_dates' => 'Un séjour à l\'hôtel nécessite une arrivée et un départ.',
    ],
    'expense' => [
        'not_found' => 'La note de frais n\'existe pas.',
        'other_employee' => 'La note de frais appartient à un autre employé.',
        'not_reimbursable' => 'Seule une note de frais remboursable peut être liée à un déplacement.',
        'rejected' => 'Une note de frais refusée ne peut pas être liée à un déplacement.',
        'currency_mismatch' => 'La note de frais est en :expense mais le déplacement est en :trip.',
        'date_outside_trip' => 'La note de frais est datée trop loin du déplacement.',
        'already_linked' => 'La note de frais est déjà liée à un déplacement.',
    ],
];
