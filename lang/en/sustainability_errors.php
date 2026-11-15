<?php

declare(strict_types=1);

return [
    'factor' => [
        'unit_not_allowed' => 'The unit :unit is not valid for the activity type :type.',
        'in_use' => 'The factor is used by :count activity records: create a new version instead of changing its value, unit or activity type.',
        'missing_for_activity' => 'No active emission factor exists for :type in the region :region.',
        'type_mismatch' => 'The emission factor is for :expected, not for :type.',
        'inactive' => 'The emission factor is not active.',
        'unit_mismatch' => 'The factor is expressed per :factor_unit, the quantity in :unit.',
        'latest_version_only' => 'Only the latest version of a factor can be versioned again.',
    ],
    'period' => [
        'overlap' => 'The period overlaps the reporting period :name.',
        'closed' => 'The reporting period :name is closed.',
        'status_expected' => 'The reporting period is :status: expected :expected.',
    ],
    'activity' => [
        'locked' => 'The reporting period :name is closed: its activity data cannot change.',
    ],
    'target' => [
        'status_expected' => 'The target is :status: expected :expected.',
        'locked' => 'Only a draft target can change its scope, baseline year, target year and percentage.',
        'year_order' => 'The target year must be after the baseline year.',
        'baseline_missing' => 'No emissions are recorded for :scope in :year: the baseline cannot be frozen.',
        'not_yet_decidable' => 'The target year :year has not ended and the target is not reached yet.',
    ],
    'kpi' => [
        'inactive' => 'The KPI is not active.',
        'period_closed' => 'The reporting period :name is closed: its KPI values cannot change.',
    ],
    'materiality' => [
        'locked' => 'An approved materiality assessment cannot be changed.',
        'no_topics' => 'The assessment needs at least one topic before approval.',
        'status_expected' => 'The assessment is :status: expected :expected.',
    ],
    'esrs' => [
        'datapoint_unknown' => 'The data point does not exist in the catalog of the company.',
        'approval_reserved' => 'Approving a value is reserved to owners, administrators and approvers.',
        'omission_reason_required' => 'A not applicable data point needs the reason of the omission.',
        'value_required' => 'The data point :code needs a value before it can be reviewed or approved.',
    ],
    'supplier_request' => [
        'supplier_unknown' => 'The supplier does not exist in the active company.',
        'submission_invalid' => 'The submission is not a valid document of this supplier for the emission statement requirement.',
        'not_scope3' => 'The activity type :type is not a Scope 3 activity.',
        'category_mismatch' => 'The activity type :type does not belong to Scope 3 category :category.',
        'status_expected' => 'The request is :status: expected :expected.',
    ],
];
