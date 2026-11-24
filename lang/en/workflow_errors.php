<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Delegator and delegate cannot be the identical user.',
        'circular' => 'Circular delegation rejected: the delegate user has already delegated to you.',
        'overlap' => 'You already have an active delegation covering part of this period.',
    ],
    'inbox' => [
        'period_too_long' => 'The period cannot be longer than 366 days.',
    ],
    'definition' => [
        'publish_to_activate' => 'Publish the definition to activate it.',
        'has_running_instances' => 'The definition has running instances: cancel them or deactivate the definition.',
        'graph_locked' => 'Only a draft definition can change its graph: create a new version.',
    ],
];
