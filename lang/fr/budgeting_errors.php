<?php

declare(strict_types=1);

return [
    'version' => [
        'locked' => 'Une version verrouillée ne peut pas être modifiée.',
        'not_draft' => 'Seule une version en brouillon peut être modifiée : celle-ci a déjà été soumise, approuvée ou verrouillée.',
        'name_taken' => 'Une version portant ce nom existe déjà pour l\'exercice :year.',
    ],
    'line' => [
        'duplicate' => 'Une ligne existe déjà pour ce compte, ce centre de coûts, ce projet et cette période.',
        'period_range' => 'La période doit être comprise entre 1 et 12.',
        'account_missing' => 'Le compte n\'existe pas dans cette entreprise.',
        'cost_center_missing' => 'Le centre de coûts n\'existe pas dans cette entreprise.',
        'project_missing' => 'Le projet n\'existe pas dans cette entreprise.',
    ],
    'spread' => [
        'range' => 'Les périodes doivent être comprises entre 1 et 12 et la fin ne peut pas précéder le début.',
        'method' => 'La règle de répartition n\'est pas valide.',
        'no_seasonal_weights' => 'La version n\'a pas de poids saisonniers : définissez-les avant d\'utiliser la règle saisonnière.',
        'weights_count' => 'Les poids saisonniers doivent être exactement douze.',
        'weights_invalid' => 'Les poids saisonniers ne peuvent pas être négatifs et leur somme sur les périodes utilisées doit être supérieure à zéro.',
        'percentages_count' => 'Saisissez exactement :count pourcentages, un par période de la plage.',
        'percentages_sum' => 'Les pourcentages ne peuvent pas être négatifs et doivent totaliser 100.',
    ],
    'approval' => [
        'empty' => 'Une version sans lignes ne peut pas être soumise.',
        'reason_required' => 'Un commentaire avec le motif est obligatoire.',
        'budget_exists' => 'Le budget :name est déjà approuvé pour l\'exercice :year : rejetez-le ou remplacez-le d\'abord.',
        'invalid_transition' => 'La version est :status : l\'action :action n\'est pas autorisée.',
        'formal_immutable' => 'Un budget approuvé n\'est jamais rouvert : créez une révision.',
        'baseline_not_approved' => 'Un budget révisé ne peut être approuvé que si le budget qu\'il révise est approuvé ou verrouillé.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Seule une version approuvée ou verrouillée peut être re-prévue.',
        'no_lines' => 'La version n\'a pas de lignes à re-prévoir.',
    ],
    'revision' => [
        'baseline_not_approved' => 'Seul un budget initial ou révisé approuvé ou verrouillé peut être révisé.',
        'already_open' => 'Un budget révisé de cet exercice est encore en préparation : approuvez-le ou supprimez-le d\'abord.',
        'suffix' => 'Révision :number',
        'adjustment_range' => 'L\'ajustement ne peut pas ramener les montants sous zéro.',
        'transfer_same' => 'Les deux lignes d\'un transfert doivent être différentes.',
        'transfer_insufficient' => 'La ligne source n\'a pas assez de budget sur cette période.',
        'roll_base' => 'Seul un rolling forecast approuvé ou verrouillé peut être décalé.',
    ],
    'grid' => [
        'slot_range' => 'La colonne doit être comprise entre 1 et :max.',
    ],
    'settings' => [
        'thresholds' => 'Le seuil orange ne peut pas dépasser le seuil rouge.',
    ],
    'override' => [
        'source_required' => 'Une dérogation pour un document nécessite le document.',
        'already_open' => 'Ce document a déjà une demande de dérogation ouverte.',
        'expiry_past' => 'La date d\'expiration ne peut pas être dans le passé.',
        'not_open' => 'Seule une dérogation en attente ou approuvée peut être annulée.',
        'not_pending' => 'La dérogation a déjà été décidée.',
        'four_eyes' => 'Principe des quatre yeux : vous ne pouvez pas décider de votre propre demande de dérogation.',
    ],
    'spend' => [
        'blocked' => 'Le budget n\'autorise pas cette dépense : :available :currency disponibles, :requested :currency demandés. Demandez une dérogation.',
        'warning' => 'Cette dépense dépasse le budget (:available :currency disponibles, :requested :currency demandés).',
        'overridden' => 'Cette dépense dépasse le budget et une dérogation approuvée l\'autorise (:available :currency disponibles, :requested :currency demandés).',
    ],
    'import' => [
        'missing_column' => 'La colonne :column est absente.',
        'account_unknown' => 'Le compte :code n\'existe pas.',
        'cost_center_unknown' => 'Le centre de coûts :code n\'existe pas.',
        'project_unknown' => 'Le projet :code n\'existe pas.',
        'period_invalid' => 'La période doit être un nombre entier de 1 à :max.',
        'amount_invalid' => 'Le montant doit être un nombre supérieur ou égal à zéro.',
        'number_invalid' => 'La valeur doit être un nombre supérieur ou égal à zéro.',
        'duplicate' => 'Ce compte, ce centre de coûts, ce projet et cette période figurent déjà à la ligne :row.',
        'too_many_rows' => 'Le fichier compte plus de :max lignes : divisez-le.',
        'empty' => 'Le fichier est vide.',
        'xls_unsupported' => 'L\'ancien format .xls n\'est pas pris en charge : enregistrez le fichier en .xlsx ou .csv.',
    ],
];
