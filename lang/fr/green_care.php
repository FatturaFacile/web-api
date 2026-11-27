<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Enregistrements récupérés avec succès.',
        'loaded' => 'Enregistrement récupéré avec succès.',
        'site_saved' => 'Le site a été enregistré.',
        'site_deleted' => 'Le site a été supprimé.',
        'contract_saved' => 'Le contrat saisonnier a été enregistré.',
        'contract_deleted' => 'Le contrat saisonnier a été supprimé.',
        'contract_activated' => 'Le contrat est actif.',
        'contract_suspended' => 'Le contrat a été suspendu.',
        'billing_previewed' => 'Aperçu de facturation calculé.',
        'invoice_generated' => 'Le brouillon de facture a été généré.',
        'crew_saved' => 'L\'équipe a été enregistrée.',
        'crew_deleted' => 'L\'équipe a été supprimée.',
        'license_saved' => 'L\'autorisation de l\'opérateur a été enregistrée.',
        'license_deleted' => 'L\'autorisation de l\'opérateur a été supprimée.',
        'license_checked' => 'L\'autorisation de l\'opérateur a été vérifiée.',
        'route_saved' => 'La tournée a été enregistrée.',
        'route_deleted' => 'La tournée a été supprimée.',
        'routes_optimized' => 'Les tournées de la semaine ont été planifiées.',
        'routes_rescheduled' => 'Les arrêts touchés par l\'alerte météo ont été reprogrammés.',
        'weather_refreshed' => 'Les prévisions météo ont été mises à jour.',
        'intervention_logged' => 'L\'intervention a été enregistrée.',
        'photos_saved' => 'Les photos ont été enregistrées.',
        'treatment_recorded' => 'Le traitement a été inscrit dans le registre phytosanitaire.',
        'slip_saved' => 'Le bordereau de déchets verts a été enregistré.',
        'slip_deleted' => 'Le bordereau de déchets verts a été supprimé.',
    ],

    'errors' => [
        'operator_license_missing' => 'L\'opérateur n\'a pas d\'autorisation phytosanitaire reconnue en :jurisdiction (PER / PNRP / OPF en Suisse, patentino PAN en Italie).',
        'operator_license_expired' => 'L\'autorisation phytosanitaire de l\'opérateur n\'est pas valable le :date (échéance :expiry).',
        'registration_number_invalid' => 'Le numéro d\'homologation du produit n\'est pas valable pour :jurisdiction (exemple :hint).',
        'contract_not_active' => 'Le contrat n\'est pas actif.',
        'contract_not_billable' => 'Un contrat en brouillon ne peut pas être facturé : activez-le d\'abord.',
        'contract_invalid_transition' => 'Le contrat ne peut pas changer de statut depuis :status.',
        'nothing_to_bill' => 'Il n\'y a rien à facturer sur ce contrat.',
        'stop_already_completed' => 'L\'arrêt a déjà été clôturé.',
        'route_locked' => 'La tournée a déjà commencé ou est clôturée et ne peut pas être modifiée.',
        'week_start_invalid' => 'La semaine doit commencer un lundi.',
        'no_crews_available' => 'Aucune équipe active à planifier.',
        'warehouse_required' => 'Un entrepôt est nécessaire pour sortir le produit du stock.',
        'condominium_data_missing' => 'Le code fiscal de la copropriété est obligatoire pour facturer avec la retenue.',
        'weather_unavailable' => 'Les prévisions météo ne sont pas disponibles actuellement.',
        'file_not_found' => 'Le fichier est introuvable.',
    ],

    'validation' => [
        'polygon' => 'La zone doit être un polygone GeoJSON d\'au moins quatre positions.',
        'waste_code' => 'Le code déchet n\'est pas valable pour :jurisdiction.',
    ],

    'waste' => [
        'default_description' => 'Déchets verts issus de l\'entretien des jardins',
    ],

    'billing' => [
        'installment' => 'Contrat d\'entretien :contract - échéance :number sur :total',
        'snow_standby' => 'Contrat :contract - redevance de piquet de déneigement hivernal',
        'extra_passage' => 'Passage supplémentaire n° :number (:included inclus) du :date',
        'time_and_material' => 'Régie et matériel :date - :site',
        'waste' => 'Élimination de déchets verts :code - :kg kg (bordereau :slip)',
        'invoice_note' => 'Contrat d\'entretien des espaces verts :contract - :site',
    ],

    'register' => [
        'title_it' => 'Cahier de campagne (Quaderno di Campagna) - registre des traitements phytosanitaires',
        'title_ch' => 'Registre des traitements phytosanitaires (OFAG)',
        'company' => 'Entreprise',
        'period' => 'Période',
        'generated' => 'Généré',
        'entries' => ':count inscriptions',
        'columns' => [
            'date' => 'Date',
            'site' => 'Site',
            'crop' => 'Culture / surface',
            'area' => 'Surface (m²)',
            'pest' => 'Ravageur / adventice',
            'product' => 'Produit',
            'registration' => 'N° hom.',
            'ingredient' => 'Substance active',
            'dose' => 'Dose',
            'water' => 'Eau (l)',
            'operator' => 'Opérateur',
            'license' => 'Autorisation',
            'reentry' => 'Rentrée',
        ],
    ],
];
