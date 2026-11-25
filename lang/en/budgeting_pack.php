<?php

declare(strict_types=1);

return [
    'sheet' => [
        'summary' => 'Summary',
        'breakdown' => 'Breakdown',
        'versions' => 'Versions',
        'rolling' => 'Rolling forecast',
        'variance_account' => 'Variance by account',
        'variance_cost_center' => 'Variance by cost center',
        'volume_price' => 'Volume and price',
        'rules' => 'Spend rules',
        'commitments' => 'Open commitments',
        'overrides' => 'Overrides',
    ],
    'field' => [
        'item' => 'Item', 'value' => 'Value', 'version' => 'Version', 'fiscal_year' => 'Fiscal year', 'type' => 'Type', 'status' => 'Status',
        'currency' => 'Currency', 'approved_at' => 'Approved on', 'through_period' => 'Through period', 'budget' => 'Budget', 'actual' => 'Actual',
        'variance' => 'Variance', 'variance_percent' => 'Variance %', 'committed' => 'Open commitments', 'available' => 'Available budget',
        'account' => 'Account', 'account_name' => 'Account name', 'cost_center' => 'Cost center', 'project' => 'Project', 'total' => 'Total',
        'scenario' => 'Scenario', 'revision' => 'Revision', 'reason' => 'Reason', 'period' => 'Period', 'forecast' => 'Forecast', 'code' => 'Code',
        'name' => 'Name', 'traffic_light' => 'Traffic light', 'volume_variance' => 'Volume variance', 'price_variance' => 'Price variance',
        'decomposable' => 'Decomposed', 'mode' => 'Mode', 'threshold' => 'Threshold %', 'stages' => 'Stages', 'active' => 'Active', 'source' => 'Document',
        'number' => 'Number', 'date' => 'Date', 'amount' => 'Amount', 'open_amount' => 'Open amount', 'scope' => 'Scope', 'requested' => 'Requested',
        'approved' => 'Approved', 'decision' => 'Decision comment', 'decided_at' => 'Decided on', 'valid_until' => 'Valid until',
    ],
    'type' => ['budget' => 'Original budget', 'revised' => 'Revised budget', 'forecast' => 'Forecast', 'rolling_forecast' => 'Rolling forecast', 'scenario' => 'Scenario'],
    'status' => ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'locked' => 'Locked'],
    'scenario' => ['best_case' => 'Best case', 'worst_case' => 'Worst case', 'custom' => 'Custom'],
    'light' => ['green' => 'Green', 'amber' => 'Amber', 'red' => 'Red'],
    'mode' => ['allow' => 'Allow', 'warning' => 'Warning', 'hard_block' => 'Hard block'],
    'source' => ['requisition' => 'Requisition', 'purchase_order' => 'Purchase order'],
    'scope' => ['document' => 'Document', 'allowance' => 'Allowance'],
    'override_status' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'consumed' => 'Used'],
    'yes' => 'Yes',
    'no' => 'No',
];
