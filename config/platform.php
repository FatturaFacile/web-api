<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;

/*
|--------------------------------------------------------------------------
| Platform (cross-cutting API behaviour)
|--------------------------------------------------------------------------
|
| idempotency: how long a stored response of an Idempotency-Key is replayed.
| etag_resources: route URIs of the records that support ETag / If-Match optimistic locking (the model of the record).
| recycle_bin: the soft-deleted records that can be listed, restored and purged. `purgeable` false keeps the record
| (fiscal documents are restored, never destroyed from the recycle bin). `label` lists the columns tried in order.
| notification_channels / notification_categories: the matrix of the per-user notification preferences.
|
*/

return [
    'idempotency' => [
        'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
        'max_key_length' => 255,
        'max_stored_bytes' => 1_048_576,
    ],

    'etag_resources' => [
        'api/v1/crm/contacts/{id}' => Contact::class,
        'api/v1/sales/invoices/{id}' => Invoice::class,
        'api/v1/sales/quotes/{id}' => Quote::class,
        'api/v1/inventory/products/{id}' => Product::class,
    ],

    'recycle_bin' => [
        'contacts' => ['model' => Contact::class, 'label' => ['company_name', 'last_name', 'email'], 'purgeable' => true],
        'products' => ['model' => Product::class, 'label' => ['name', 'sku'], 'purgeable' => true],
        'quotes' => ['model' => Quote::class, 'label' => ['quote_number'], 'purgeable' => true],
        'invoices' => ['model' => Invoice::class, 'label' => ['invoice_number'], 'purgeable' => false],
    ],

    'notification_channels' => ['database', 'mail', 'push', 'telegram', 'whatsapp'],

    /*
    | Outbound channels. push: Firebase Cloud Messaging HTTP v1 (iOS is delivered through FCM → APNs; credentials are the
    | service-account JSON file). telegram: Bot API. whatsapp: Cloud API (an approved template is needed outside the
    | 24-hour window).
    */
    'push' => ['fcm_project_id' => env('FCM_PROJECT_ID'), 'fcm_credentials' => env('FCM_CREDENTIALS_PATH')],
    'telegram' => ['bot_token' => env('TELEGRAM_BOT_TOKEN'), 'bot_username' => env('TELEGRAM_BOT_USERNAME'), 'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET')],
    'whatsapp' => ['token' => env('WHATSAPP_TOKEN'), 'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'), 'template' => env('WHATSAPP_TEMPLATE'), 'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'it')],

    'notification_categories' => ['general', 'billing', 'crm', 'inventory', 'hr', 'system'],
];
