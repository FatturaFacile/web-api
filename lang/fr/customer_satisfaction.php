<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Liste récupérée avec succès.',
        'loaded' => 'Enregistrement récupéré avec succès.',
        'saved' => 'Enregistré avec succès.',
        'deleted' => 'Supprimé avec succès.',
        'defaults_installed' => 'Les modèles d\'enquête par défaut ont été installés.',
        'no_matching_rule' => 'Aucune règle d\'enquête active ne correspond à cet événement.',
        'dispatch_recorded' => 'L\'envoi de l\'enquête a été enregistré.',
        'dispatches_processed' => 'Les invitations d\'enquête échues ont été traitées.',
        'report_generated' => 'Le rapport ISO 9001 a été généré.',
        'score_recorded' => 'Merci, votre note a été enregistrée.',
        'feedback_recorded' => 'Merci, votre commentaire a été enregistré.',
        'opted_out' => 'Vous ne recevrez plus d\'enquêtes de satisfaction.',
    ],

    'errors' => [
        'survey_not_found' => 'Ce lien d\'enquête n\'est pas valide.',
        'survey_expired' => 'Ce lien d\'enquête a expiré.',
        'survey_not_available' => 'Cette enquête n\'est pas disponible.',
        'invalid_score' => 'La note doit être comprise entre :min et :max.',
        'score_required' => 'Donnez d\'abord une note, puis ajoutez votre commentaire.',
        'comment_required' => 'Écrivez un commentaire ou enregistrez une note vocale.',
        'voice_not_allowed' => 'Les commentaires vocaux ne sont pas activés pour cette enquête.',
        'invalid_status_transition' => 'Un dossier ne peut pas passer de « :from » à « :to ».',
        'resolution_requires_root_cause' => 'Résoudre ou clôturer un dossier exige la cause racine et le résumé de la résolution.',
        'unsupported_export_format' => 'Format d\'exportation non pris en charge.',
    ],

    'crm_task_subject' => 'Récupération d\'un détracteur : appeler :customer sous 24 heures',

    'report' => [
        'title' => 'Rapport de satisfaction client',
        'period' => 'Période',
        'kpis' => 'Indicateurs clés',
        'response_rate' => 'Taux de réponse %',
        'sla_compliance' => 'Respect du SLA 24h %',
        'open_cases' => 'Dossiers détracteurs ouverts',
        'breakdown' => 'Détail',
        'dimension' => 'Dimension',
        'responses' => 'Réponses',
        'corrective_actions' => 'Non-conformités et actions correctives',
        'resolution_rate' => 'Taux de résolution closed-loop %',
        'management_review_notes' => 'Notes de la revue de direction',
        'columns' => [
            'dimension' => 'Dimension',
            'month' => 'Mois',
            'responses' => 'Réponses',
            'nps' => 'NPS',
            'promoters_pct' => 'Promoteurs %',
            'passives_pct' => 'Passifs %',
            'detractors_pct' => 'Détracteurs %',
            'csat_pct' => 'CSAT %',
            'csat_average' => 'CSAT moyen',
            'ces_average' => 'CES moyen',
            'ces_low_effort_rate' => 'CES faible effort %',
        ],
    ],
];
