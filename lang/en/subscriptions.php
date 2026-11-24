<?php

declare(strict_types=1);

return [

    'invalid_transition' => 'A :status subscription cannot be :action.',

    'actions' => [
        'paused' => 'paused',
        'resumed' => 'resumed',
        'cancelled' => 'cancelled',
    ],

    'messages' => [
        'list' => 'Subscriptions retrieved successfully',
        'created' => 'Subscription created successfully',
        'retrieved' => 'Subscription retrieved successfully',
        'updated' => 'Subscription updated successfully',
        'deleted' => 'Subscription deleted successfully',
        'runs' => 'Billing runs retrieved successfully',
        'billed' => '{0} No invoices generated|{1} :count invoice generated|[2,*] :count invoices generated',
        'paused' => 'Subscription paused successfully',
        'resumed' => 'Subscription resumed successfully',
        'cancelled' => 'Subscription cancelled successfully',
    ],

];
