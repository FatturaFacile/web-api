<?php

declare(strict_types=1);

return [

    'messages' => [
        'widgets_retrieved' => 'Widgets de tableau de bord disponibles récupérés avec succès',
        'layout_retrieved' => 'Disposition du tableau de bord récupérée avec succès',
        'layout_updated' => 'Disposition du tableau de bord enregistrée avec succès',
        'layout_reset' => 'Disposition du tableau de bord rétablie aux paramètres par défaut',
        'data_retrieved' => 'Données du tableau de bord récupérées avec succès',
    ],

    'errors' => [
        'unknown_widget' => 'Le widget de tableau de bord ":widget" n\'existe pas.',
        'exceeds_grid' => 'Le widget ne tient pas dans la grille de :columns colonnes.',
        'unknown_option' => 'L\'option ":option" n\'est pas disponible pour ce widget.',
        'invalid_option_value' => 'L\'option ":option" doit être l\'une des valeurs suivantes : :values.',
        'invalid_option_range' => 'L\'option ":option" doit être un entier compris entre :min et :max.',
        'widget_failed' => 'Les données du widget n\'ont pas pu être calculées.',
    ],

    'widgets' => [
        'revenue_kpi' => [
            'title' => 'Chiffre d\'affaires',
            'description' => 'Montant net facturé sur la période sélectionnée, comparé à la précédente.',
        ],
        'cash_flow_trend' => [
            'title' => 'Flux de trésorerie',
            'description' => 'Encaissements comparés aux décaissements des derniers mois.',
        ],
        'overdue_invoices' => [
            'title' => 'Factures échues',
            'description' => 'Factures clients dépassant leur échéance, avec le retard moyen.',
        ],
        'low_stock_alert' => [
            'title' => 'Stock faible',
            'description' => 'Articles et variantes au niveau du stock minimum ou en dessous.',
        ],
        'ecommerce_sync_status' => [
            'title' => 'Synchronisation e-commerce',
            'description' => 'Commandes importées et en échec, et dernière synchronisation de chaque canal.',
        ],
        'top_selling_products' => [
            'title' => 'Articles les plus vendus',
            'description' => 'Les meilleures ventes du mois en cours.',
        ],
    ],

];
