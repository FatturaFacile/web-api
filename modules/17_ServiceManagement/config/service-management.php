<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Module 17 Service Management: warranty & depot repair (RMA) settings
|--------------------------------------------------------------------------
| Merged into `service-management.*` by ServiceManagementServiceProvider.
*/

return [
    'rma' => [
        // Days a "prestito d'uso" loaner may stay out after the repaired unit was returned before alerting.
        'loaner_grace_days' => (int) env('ESM_RMA_LOANER_GRACE_DAYS', 7),

        // Validity of the public estimate approval link, from estimate issue.
        'estimate_token_ttl_days' => (int) env('ESM_RMA_ESTIMATE_TOKEN_TTL_DAYS', 30),

        // Default customer pre-authorized threshold when the request does not provide one (0.00 = always ask).
        'default_threshold' => (string) env('ESM_RMA_DEFAULT_THRESHOLD', '0.00'),

        // Cost bucket code carried by RmaWarrantyCostChargedEvent for GL / Inventory consumers.
        'cost_bucket_code' => env('ESM_RMA_COST_BUCKET_CODE', 'WARRANTY_PROVISION'),

        // Base URL of the customer-facing frontend that renders /rma-estimate/{token} and /rma-tracking.
        'public_base_url' => env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost')),

        // Fallback return address printed on RMA labels (tenant settings `rma.return_address` wins).
        'return_address' => env('ESM_RMA_RETURN_ADDRESS'),
    ],
];
