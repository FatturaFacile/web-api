<?php

declare(strict_types=1);

return [
    'lead_webhook' => [
        // Maximum deliveries per minute for one lead source and client IP (named limiter "crm-lead-webhook").
        'rate_limit_per_minute' => (int) env('CRM_LEAD_WEBHOOK_RATE_LIMIT', 120),

        // Accepted clock skew, in seconds, between X-Timestamp and the server time.
        'timestamp_tolerance_seconds' => (int) env('CRM_LEAD_WEBHOOK_TIMESTAMP_TOLERANCE', 300),
    ],

    // Base URL of the web application, used to build deep links in lead notifications.
    'frontend_url' => env('CRM_FRONTEND_URL', env('FRONTEND_URL', env('APP_URL', 'http://localhost'))),
];
