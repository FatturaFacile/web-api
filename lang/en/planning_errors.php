<?php

declare(strict_types=1);

return [
    'scenario' => [
        'locked' => 'A locked scenario cannot be changed.',
        'not_draft' => 'Only a draft scenario can be changed: it is already under review, approved or locked.',
        'horizon_order' => 'The end of the horizon cannot be before its start.',
        'horizon_too_long' => 'The horizon cannot be longer than :months months.',
        'lines_outside_horizon' => 'Some plan lines lie outside the new horizon: remove them first.',
        'published' => 'The scenario was published and cannot be deleted or sent back to draft.',
        'baseline_exists' => 'The company already has a baseline scenario: create a what-if scenario or copy the baseline.',
    ],
    'resource' => [
        'code_taken' => 'The resource code :code is already used.',
        'rate_exists' => 'The item already has a load rate on this resource.',
        'in_use' => 'The resource has capacity constraints in scenarios that are no longer drafts and cannot be deleted.',
    ],
    'line' => [
        'outside_horizon' => 'The month lies outside the horizon of the scenario.',
        'duplicate' => 'A line for this side, item and month already exists.',
        'item_missing' => 'The item does not exist in this company.',
        'item_required' => 'Choose an item or enter an item code.',
    ],
    'cycle' => [
        'empty' => 'A scenario without plan lines cannot be submitted.',
        'capacity_overloaded' => ':count resource months are overloaded: fix the plan or accept the overload with a comment.',
        'overload_comment' => 'Accepting a capacity overload needs a comment.',
        'reason_required' => 'A comment with the reason is required.',
        'invalid_transition' => 'The scenario is :status: the action :action is not allowed.',
        'not_publishable' => 'Only an approved or locked scenario can be published.',
    ],
];
