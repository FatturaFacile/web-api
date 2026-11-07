<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Subscription billing module (sub_subscriptions)
|--------------------------------------------------------------------------
|
| Renewals run every day. The recurring billing run and the revenue recognition schedule write receivables and revenue
| for invoices that the module only references (synthetic invoice ids, not issued fiscal invoices), so they stay manual
| (POST /api/v1/subscriptions/billing-runs and /revenue-recognition/recognize) unless a company turns this switch on.
|
*/

return [
    'scheduled_billing' => (bool) env('SUBSCRIPTION_BILLING_SCHEDULED', false),
];
