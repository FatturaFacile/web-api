<?php

declare(strict_types=1);

return [
    'ticket_not_escalatable' => 'A resolved, closed or cancelled ticket cannot be escalated to field service.',

    'messages' => [
        'escalated' => 'Ticket escalated to field service',
        'escalation_exists' => 'The ticket already has an open field intervention',
        'completed' => 'Intervention completed and settled',
    ],

    'timeline' => [
        'activity' => 'Activity',
        'opportunity' => 'Opportunity',
        'invoice' => 'Invoice',
        'ticket' => 'Ticket',
        'work_order' => 'Field intervention',
        'contract' => 'Maintenance contract',
        'lead' => 'Lead',
    ],
];
