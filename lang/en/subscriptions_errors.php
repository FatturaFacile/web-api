<?php

declare(strict_types=1);

return [
    'dunning' => [
        'invoice_not_unpaid' => 'The invoice is not an unpaid issued invoice of this subscription.',
    ],
    'subscription' => [
        'start_date_locked' => 'The start date of a subscription already billed cannot change.',
        'no_quantity_item' => 'The subscription has no plan item whose quantity can change.',
        'already_closed' => 'The subscription is already closed.',
        'pending_approval' => 'The subscription is waiting for approval and cannot change.',
    ],
    'plan' => [
        'not_active' => 'The plan is not an active plan of this company.',
        'currency_interval_mismatch' => 'The new plan must have the currency and the billing interval of the subscription.',
        'use_downgrade' => 'The new plan costs less: use a downgrade.',
        'use_upgrade' => 'The new plan costs more: use an upgrade.',
    ],
    'usage' => [
        'item_not_metered' => 'The item is not a metered item of a subscription of this company.',
        'subscription_closed' => 'The subscription of the item is closed.',
        'metric_mismatch' => 'The metric does not match the metric of the item.',
    ],
];
