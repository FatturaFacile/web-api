<?php

declare(strict_types=1);

return [
    'messages' => [
        'calendar_created' => 'Holiday calendar created.',
        'calendar_updated' => 'Holiday calendar updated.',
        'calendar_deleted' => 'Holiday calendar deleted.',
        'policy_created' => 'Leave entitlement policy created.',
        'policy_updated' => 'Leave entitlement policy updated.',
        'policy_deleted' => 'Leave entitlement policy deleted.',
        'accruals_calculated' => 'Monthly leave accruals calculated.',
        'request_submitted' => 'Leave request submitted.',
        'sick_registered' => 'Sick leave or accident registered.',
        'request_approved' => 'Leave request approved.',
        'request_rejected' => 'Leave request rejected.',
        'request_cancelled' => 'Leave request cancelled.',
        'threshold_saved' => 'Department minimum staffing threshold saved.',
    ],
    'errors' => [
        'unsupported_country' => 'Unsupported jurisdiction country :country (expected CH or IT).',
        'invalid_swiss_canton' => 'Invalid Swiss canton code :canton.',
        'invalid_patron_saint_date' => 'Invalid patron saint date :date (expected MM-DD).',
        'invalid_inps_puc' => 'Invalid INPS PUC medical certificate protocol ":puc" (must be 9 alphanumeric characters).',
        'inps_puc_required' => 'The 9-character INPS PUC protocol number is required for Italian sick leave.',
        'end_date_before_start' => 'End date cannot precede start date.',
        'overlapping_request' => 'An overlapping active leave request already exists between :start_date and :end_date.',
        'insufficient_balance' => 'Insufficient leave balance (available: :available, requested: :requested).',
        'staffing_threshold_breached' => 'Department minimum staffing threshold breached on :date (:present present, :required required).',
        'invalid_state_transition' => 'Cannot :action a leave request in status :status.',
        'locked_by_workflow' => 'This leave request is governed by an active approval workflow (Mod 006).',
        'legge_104_limit_exceeded' => 'Legge 104/1992 monthly limit exceeded (used: :used h, requested: :requested h, limit: :limit h).',
        'invalid_decision' => 'Unsupported decision :decision.',
        'employee_id_required' => 'Parameter employee_id is required.',
    ],
];
