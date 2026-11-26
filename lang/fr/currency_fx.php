<?php

declare(strict_types=1);

/**
 * Messages du moteur multidevise (Mod 003) : les réponses de l'API et les erreurs de ses règles. Garder it, de, fr et en synchronisés.
 */
return [
    'messages' => [
        'listed' => 'Enregistrements récupérés avec succès.',
        'resolved' => 'Taux de change déterminé.',
        'rate_stored' => 'Taux de change enregistré.',
        'sync_queued' => 'Synchronisation des taux de change mise en file d\'attente.',
        'exposure' => 'Exposition aux devises calculée.',
        'settings' => 'Paramètres de devises récupérés.',
        'settings_saved' => 'Paramètres de devises enregistrés.',
        'revaluation_simulated' => 'Réévaluation des changes simulée.',
        'revaluation' => 'Réévaluation des changes récupérée.',
        'revaluation_posted' => 'Réévaluation des changes comptabilisée.',
        'revaluation_reversed' => 'Réévaluation des changes extournée.',
    ],
    'errors' => [
        'exchange_rate_missing' => 'Aucun taux de change :base/:quote n\'est disponible au :date ni dans les :days jours précédents : saisissez un taux manuel ou synchronisez les taux.',
        'sync_already_running' => 'Une synchronisation :provider est déjà en file d\'attente ou en cours.',
        'same_currency' => 'La devise de base et la devise de cotation doivent être différentes.',
        'contract_reason_required' => 'Un taux contractuel exige un motif.',
        'currency_in_use' => 'Ces devises ont encore des postes ouverts ou des soldes et ne peuvent pas être désactivées : :currencies.',
        'vat_method_not_allowed' => 'Cette méthode de taux TVA n\'est pas admise pour les sociétés en :country.',
        'vat_method_locked' => 'La méthode de taux TVA doit être conservée au moins une période fiscale avant de pouvoir être modifiée.',
        'account_not_found' => 'Le compte n\'existe pas dans le plan comptable de la société.',
        'nothing_to_revalue' => 'Il n\'y a aucun poste ouvert ni compte en devise étrangère à réévaluer au :date.',
        'period_end_invalid' => 'La fin de période n\'est pas une fin valide d\'une période :type.',
        'revaluation_not_found' => 'La réévaluation des changes n\'existe pas.',
        'revaluation_already_posted' => 'La réévaluation des changes est déjà comptabilisée.',
        'revaluation_already_reversed' => 'La réévaluation des changes est déjà extournée.',
        'revaluation_not_posted' => 'Seule une réévaluation des changes comptabilisée peut être extournée.',
        'revaluation_period_conflict' => 'Les positions au :date ont déjà été réévaluées et comptabilisées : extournez d\'abord cette réévaluation.',
        'line_account_missing' => 'La ligne de réévaluation :reference n\'a pas de compte comptable.',
        'reversal_date_invalid' => 'La date d\'extourne doit être postérieure à la fin de période (:date).',
        'idempotency_key_required' => 'L\'en-tête Idempotency-Key (8 à 100 caractères) est obligatoire pour comptabiliser une réévaluation.',
    ],
];
