<?php

declare(strict_types=1);

return [
    /*
    | What to do when a new lead matches an existing one: merge (update the existing lead and do not create a new
    | one), notify (create it, link it to the original and warn the owner) or create (ignore duplicates).
    */
    'duplicate_strategy' => env('CRM_LEAD_DUPLICATE_STRATEGY', 'notify'),

    // Minimum similarity (0-1, Levenshtein based) between normalized company names to flag a duplicate.
    'company_similarity' => 0.88,

    // Rating thresholds: score >= hot => hot, score >= warm => warm, otherwise cold.
    'thresholds' => ['hot' => 70, 'warm' => 40],

    // Leads at or above this score are qualified automatically.
    'auto_qualify_score' => 80,

    // Default dialing prefix per ISO country, used to normalise phone numbers to E.164.
    'dial_codes' => [
        'IT' => '39', 'CH' => '41', 'DE' => '49', 'FR' => '33', 'AT' => '43', 'ES' => '34',
        'GB' => '44', 'LI' => '423', 'SM' => '378', 'NL' => '31', 'BE' => '32', 'US' => '1',
    ],

    'scoring' => [
        // Demographic fit.
        'industries' => [
            'manufacturing' => 10, 'logistics' => 10, 'retail' => 8, 'construction' => 8,
            'professional_services' => 6, 'it' => 6, 'healthcare' => 6,
        ],
        'revenue_tiers' => [ // min estimated revenue => points
            10000000 => 15, 1000000 => 10, 250000 => 5,
        ],
        'regions' => [ // canton / region => points
            'ticino' => 5, 'lombardia' => 5, 'piemonte' => 4, 'veneto' => 4, 'emilia-romagna' => 4, 'lazio' => 3,
        ],
        // Behavioural engagement: points per event, with a cap.
        'behaviour' => [
            'email_opens' => ['points' => 2, 'cap' => 10],
            'catalog_downloads' => ['points' => 8, 'cap' => 16],
            'site_visits' => ['points' => 2, 'cap' => 10],
        ],
    ],

    'push' => [
        // Optional push gateway (FCM/OneSignal proxy). The user id is sent so the gateway resolves the devices.
        'url' => env('CRM_LEAD_PUSH_URL'),
        'token' => env('CRM_LEAD_PUSH_TOKEN'),
    ],
];
