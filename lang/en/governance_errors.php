<?php

declare(strict_types=1);

return [
    'status' => [
        'unknown' => 'Unknown status :status.',
        'not_allowed' => 'The status cannot change from :from to :to: allowed :allowed.',
    ],
    'risk' => [
        'closed' => 'The risk is closed: reopen it to change it.',
        'owner_required' => 'A risk needs an owner before it is assessed.',
        'active_control_required' => 'A risk goes into treatment only when an active control mitigates it: link a control first.',
        'acceptance_notes_required' => 'Accepting a risk needs the justification in the notes.',
        'residual_exceeds_inherent' => 'The residual likelihood and impact cannot exceed the inherent ones.',
        'open_findings' => 'An open finding refers to this risk: close or reassign the finding first.',
    ],
    'control' => [
        'test_needs_active' => 'The control is :status: only an active control is tested.',
        'finding_title' => 'Control test not passed: :code :title',
    ],
    'plan' => [
        'closed' => 'The audit plan is closed.',
        'year_locked' => 'The year of a plan that has audits cannot change.',
        'audit_required' => 'A plan needs at least one audit before it is approved.',
        'audits_unfinished' => 'Every audit must be closed or cancelled before the plan is closed: :codes.',
    ],
    'audit' => [
        'locked' => 'The audit is :status and cannot be changed.',
        'only_team_and_conclusion' => 'The audit is :status: only the team and the conclusion can change.',
        'dates_inverted' => 'The planned end cannot be before the planned start.',
        'plan_not_approved' => 'The audit plan is :status: it must be approved before an audit starts.',
        'conclusion_required' => 'An audit is closed with a conclusion.',
        'untreated_findings' => 'High and critical findings still open: :codes.',
        'cancellation_reason_required' => 'Cancelling an audit needs the reason in the notes.',
        'outside_plan_year' => 'The audit must start in the year of the plan (:year).',
    ],
    'finding' => [
        'audit_not_in_fieldwork' => 'The audit is :status: findings are raised while it is in progress or reporting.',
        'locked' => 'The finding is :status and cannot be changed.',
        'action_required' => 'Raise at least one remediation action first.',
        'acceptance_reason_required' => 'Accepting the risk of a finding needs the reason in the notes.',
        'closure_notes_required' => 'Closing a finding needs the closure notes.',
        'live_actions' => 'Cancel or complete the remediation actions before reopening the finding.',
        'actions_unverified' => 'Every remediation action must be verified or cancelled (at least one verified): :codes.',
    ],
    'action' => [
        'finding_closed' => 'The finding is :status: no new action can be raised.',
        'locked' => 'The action is :status and cannot be changed.',
        'segregation_of_duties' => 'The action cannot be verified by the person who completed it.',
        'rejection_notes_required' => 'Sending an action back needs the reason in the notes.',
        'cancellation_notes_required' => 'Cancelling an action needs the reason in the notes.',
        'term_too_long' => 'The due date is too far for a :severity finding: the term is :days days (until :limit).',
    ],
    'obligation' => [
        'locked' => 'The obligation is :status and cannot be changed.',
        'not_open' => 'The obligation is :status: only a pending or in progress obligation is completed.',
    ],
    'sod_document' => [
        'conflict' => 'Segregation of duties: the person who prepared a :document cannot also approve or release it.',
        'documents' => [
            'payment' => 'payment',
            'purchase_order' => 'purchase order',
            'journal' => 'journal',
            'payroll_run' => 'payroll run',
        ],
    ],
];
