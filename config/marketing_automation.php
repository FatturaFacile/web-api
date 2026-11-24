<?php

declare(strict_types=1);

return [
    // Path of the real `mjml` CLI (npm i -g mjml). Empty = built-in table renderer.
    'mjml_binary' => env('MARKETING_MJML_BINARY', ''),

    // Secret used to sign click-tracking redirect targets (defaults to the application key).
    'tracking_secret' => env('MARKETING_TRACKING_SECRET'),

    // Shared secret of the provider webhooks (bounces, complaints). Empty = webhooks are refused.
    'webhook_secret' => env('MARKETING_WEBHOOK_SECRET', ''),

    // Address that receives mailto: one-click unsubscribe requests (optional).
    'unsubscribe_mailbox' => env('MARKETING_UNSUBSCRIBE_MAILBOX'),

    // Dormant customer trigger (days without an order).
    'dormant_days' => 60,

    // Abandoned cart trigger: an open quote older than this many hours without an order.
    'abandoned_cart_hours' => 24,

    // Per-provider settings. Credentials come from the environment; a tenant channel configuration overrides them.
    'providers' => [
        'smtp' => ['mailer' => env('MARKETING_SMTP_MAILER', 'smtp')],
        'ses' => ['key' => env('MARKETING_SES_KEY'), 'secret' => env('MARKETING_SES_SECRET'), 'region' => env('MARKETING_SES_REGION', 'eu-central-1')],
        'sendgrid' => ['key' => env('MARKETING_SENDGRID_KEY')],
        'mailgun' => ['key' => env('MARKETING_MAILGUN_KEY'), 'domain' => env('MARKETING_MAILGUN_DOMAIN'), 'endpoint' => env('MARKETING_MAILGUN_ENDPOINT', 'https://api.eu.mailgun.net')],
        'infomaniak' => ['mailer' => env('MARKETING_INFOMANIAK_MAILER', 'smtp')],
    ],
];
