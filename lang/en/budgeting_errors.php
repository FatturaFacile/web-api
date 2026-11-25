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
        'formal_immutable' => 'An approved budget is never reopened: create a revision instead.',
        'baseline_not_approved' => 'A revised budget can be approved only when the budget it revises is approved or locked.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Only an approved or locked version can be re-forecast.',
        'no_lines' => 'The version has no lines to re-forecast.',
    ],
    'revision' => [
        'baseline_not_approved' => 'Only an approved or locked original or revised budget can be revised.',
        'already_open' => 'A revised budget of this fiscal year is still being prepared: approve or delete it first.',
        'suffix' => 'Revision :number',
        'adjustment_range' => 'The adjustment cannot take the amounts below zero.',
        'transfer_same' => 'The two lines of a transfer must be different.',
        'transfer_insufficient' => 'The line to take the amount from does not have enough budget in this period.',
        'roll_base' => 'Only an approved or locked rolling forecast can be rolled forward.',
    ],
    'grid' => [
        'slot_range' => 'The column must be between 1 and :max.',
    ],
    'settings' => [
        'thresholds' => 'The amber threshold cannot be higher than the red threshold.',
    ],
    'override' => [
        'source_required' => 'An override for a document needs the document.',
        'already_open' => 'This document already has an open override request.',
        'expiry_past' => 'The expiry date cannot be in the past.',
        'not_open' => 'Only a pending or approved override can be cancelled.',
        'not_pending' => 'The override has already been decided.',
        'four_eyes' => 'Four-eyes rule: you cannot decide your own override request.',
    ],
    'spend' => [
        'blocked' => 'The budget does not allow this spend: :available :currency available, :requested :currency requested. Ask for an override.',
        'warning' => 'This spend goes over the budget (:available :currency available, :requested :currency requested).',
        'overridden' => 'This spend goes over the budget and an approved override allows it (:available :currency available, :requested :currency requested).',
    ],
    'import' => [
        'missing_column' => 'The column :column is missing.',
        'account_unknown' => 'The account :code does not exist.',
        'cost_center_unknown' => 'The cost center :code does not exist.',
        'project_unknown' => 'The project :code does not exist.',
        'period_invalid' => 'The period must be a whole number from 1 to :max.',
        'amount_invalid' => 'The amount must be a number of zero or more.',
        'number_invalid' => 'The value must be a number of zero or more.',
        'duplicate' => 'This account, cost center, project and period already appear in row :row.',
        'too_many_rows' => 'The file has more than :max rows: split it.',
        'empty' => 'The file is empty.',
        'xls_unsupported' => 'The old .xls format is not supported: save the file as .xlsx or .csv.',
    ],
];
