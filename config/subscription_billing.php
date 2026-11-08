<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Subscription engine (modules/19_SubscriptionBilling, sub_subscriptions)
|--------------------------------------------------------------------------
|
| The engine is the only subscription billing of the application (the deprecated /api/v1/subscriptions API runs on
| it). Its billing issues real invoices through the invoicing services (gapless numbers at emission, ledger posting
| from InvoicePostedEvent, every line on the deferred revenue account) and is idempotent: one invoice per
| subscription and period, claimed under a row lock and a unique index, so a second or concurrent run bills nothing
| twice. The monthly recognition posts deferred revenue to revenue through the General Ledger posting service.
|
| scheduled_billing: subscriptions:process (02:00) bills the due periods, the per-tenant subscriptions:billing-run
| (02:05) retries what failed, subscriptions:revenue-recognition runs on the 1st. On by default; set
| SUBSCRIPTION_BILLING_SCHEDULED=false to bill only by hand (POST /api/v1/subscriptions/billing-runs).
|
| auto_post_invoices: false leaves the subscription invoices as drafts to review and issue from the invoicing module;
| their revenue is recognized only once they are issued.
|
*/

return [
    'scheduled_billing' => (bool) env('SUBSCRIPTION_BILLING_SCHEDULED', true),
    'auto_post_invoices' => (bool) env('SUBSCRIPTION_BILLING_AUTO_POST', true),
];
