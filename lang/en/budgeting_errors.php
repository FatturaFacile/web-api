<?php

declare(strict_types=1);

return [
    'version' => [
        'locked' => 'A locked version cannot be changed.',
        'not_draft' => 'Only a draft version can be changed: it was already submitted, approved or locked.',
        'name_taken' => 'A version with this name already exists for the fiscal year :year.',
    ],
    'line' => [
        'duplicate' => 'A line for this account, cost center, project and period already exists.',
        'period_range' => 'The period must be between 1 and 12.',
        'account_missing' => 'The account does not exist in this company.',
        'cost_center_missing' => 'The cost center does not exist in this company.',
        'project_missing' => 'The project does not exist in this company.',
    ],
    'spread' => [
        'range' => 'The periods must be between 1 and 12 and the end cannot be before the start.',
        'method' => 'The allocation rule is not valid.',
        'no_seasonal_weights' => 'The version has no seasonal weights: set them before using the seasonal rule.',
        'weights_count' => 'The seasonal weights must be exactly twelve.',
        'weights_invalid' => 'The seasonal weights cannot be negative and their sum in the periods used must be above zero.',
        'percentages_count' => 'Enter exactly :count percentages, one per period of the range.',
        'percentages_sum' => 'The percentages cannot be negative and must add up to 100.',
    ],
    'approval' => [
        'empty' => 'A version without lines cannot be submitted.',
        'reason_required' => 'A comment with the reason is required.',
        'budget_exists' => 'The budget :name is already approved for the fiscal year :year: reject or replace it first.',
        'invalid_transition' => 'The version is :status: the action :action is not allowed.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Only an approved or locked version can be re-forecast.',
        'no_lines' => 'The version has no lines to re-forecast.',
    ],
];
