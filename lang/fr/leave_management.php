<?php

declare(strict_types=1);

return [
    'messages' => [
        'calendar_created' => 'Calendrier des jours fériés créé.',
        'calendar_updated' => 'Calendrier des jours fériés mis à jour.',
        'calendar_deleted' => 'Calendrier des jours fériés supprimé.',
        'policy_created' => 'Politique de droits aux congés créée.',
        'policy_updated' => 'Politique de droits aux congés mise à jour.',
        'policy_deleted' => 'Politique de droits aux congés supprimée.',
        'accruals_calculated' => 'Droits mensuels calculés.',
        'request_submitted' => 'Demande d\'absence soumise.',
        'sick_registered' => 'Maladie ou accident enregistré.',
        'request_approved' => 'Demande d\'absence approuvée.',
        'request_rejected' => 'Demande d\'absence refusée.',
        'request_cancelled' => 'Demande d\'absence annulée.',
        'threshold_saved' => 'Seuil minimum de présence du département enregistré.',
    ],
    'errors' => [
        'unsupported_country' => 'Pays non pris en charge :country (CH ou IT attendu).',
        'invalid_swiss_canton' => 'Code canton suisse invalide :canton.',
        'invalid_patron_saint_date' => 'Date du saint patron invalide :date (MM-JJ attendu).',
        'invalid_inps_puc' => 'Numéro de protocole INPS PUC ":puc" invalide (9 caractères alphanumériques requis).',
        'inps_puc_required' => 'Le numéro de protocole INPS PUC à 9 caractères est obligatoire pour un arrêt maladie en Italie.',
        'end_date_before_start' => 'La date de fin ne peut pas précéder la date de début.',
        'overlapping_request' => 'Une demande active chevauche déjà la période du :start_date au :end_date.',
        'insufficient_balance' => 'Solde de congés insuffisant (disponible : :available, demandé : :requested).',
        'staffing_threshold_breached' => 'Seuil minimum de présence dépassé le :date (:present présents, :required requis).',
        'invalid_state_transition' => 'Action :action impossible dans le statut :status.',
        'locked_by_workflow' => 'Cette demande est gérée par un workflow d\'approbation actif (Mod 006).',
        'legge_104_limit_exceeded' => 'Limite mensuelle Loi 104/1992 dépassée (utilisées : :used h, demandées : :requested h, limite : :limit h).',
        'invalid_decision' => 'Décision non valide :decision.',
        'employee_id_required' => 'Le paramètre employee_id est obligatoire.',
    ],
];
