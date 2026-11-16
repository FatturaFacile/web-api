<?php

declare(strict_types=1);

return [
    'project' => [
        'number_already_used' => 'This project number is already used.',
        'cannot_start' => 'Project is :status: it cannot start.',
        'cannot_pause' => 'Project is :status: it cannot pause.',
        'cannot_resume' => 'Project is :status: it cannot resume.',
        'cannot_complete' => 'Project is :status: it cannot complete.',
        'no_longer_changeable' => 'Project is :status: it can no longer change.',
        'contract_value_via_change_orders' => 'The contract value of a started project changes only through change orders.',
        'recognition_method_locked' => 'The recognition method cannot change once costs are booked on the project.',
        'cannot_delete_with_bookings' => 'Time or costs are booked on the project: it cannot be deleted.',
    ],
    'rate_card' => [
        'overlaps' => 'Another rate card for the same scope overlaps this validity period.',
        'managers_only' => 'Only the managers of the company can change company-wide rates and capacity.',
    ],
    'cost' => [
        'project_closed' => 'Project :number is :status: no further costs can be booked.',
        'approve_only_pending' => 'Cost entry is :status, only pending entries can be approved.',
        'reject_only_pending' => 'Cost entry is :status, only pending entries can be rejected.',
        'category_invalid' => 'The cost category is not valid.',
        'total_negative' => 'The total cost cannot be negative.',
        'allocation_needs_task' => 'Every allocation must point to a task of the project.',
        'allocation_not_100' => 'The allocation percentages must add up to 100.',
    ],
    'task' => [
        'not_in_project' => 'The task does not belong to the project.',
        'cannot_delete_with_bookings' => 'Hours or costs are booked on this task: it cannot be deleted.',
        'has_subtasks' => 'The task has subtasks: delete or move them first.',
        'parent_invalid' => 'The parent must be another task of the same project.',
    ],
    'phase' => [
        'not_in_project' => 'The phase does not belong to the project.',
    ],
    'billing' => [
        'milestone_not_completed' => 'The milestone is not completed yet: complete it before billing.',
        'milestone_already_billed' => 'The milestone is already billed.',
        'milestone_no_amount' => 'The milestone has no billing amount or percentage.',
        'nothing_to_invoice' => 'There are no approved billable hours or costs to invoice up to this date.',
        'project_closed' => 'Project :number is :status: it can no longer be billed.',
    ],
    'change_order' => [
        'project_closed' => 'Project :number is :status: no change orders can be added.',
        'number_already_used' => 'This change order number is already used on the project.',
        'already_decided' => 'Change order is :status: it was already decided.',
        'project_closed_apply' => 'Project :number is :status: change orders can no longer be applied.',
        'cost_impact_mismatch' => 'The cost impact must equal the sum of the cost deltas of the lines.',
        'revenue_impact_mismatch' => 'The revenue impact must equal the sum of the revenue deltas of the lines.',
    ],
    'closing' => [
        'open_timesheets' => ':count timesheets are still draft, submitted or rejected.',
        'pending_expenses' => ':count expenses are waiting for approval.',
        'unbilled_time_and_materials' => 'Approved billable hours and costs worth :amount are not invoiced.',
        'unbilled_milestones' => ':count completed milestones are not invoiced.',
        'draft_invoices' => ':count draft invoices of the project are not issued yet.',
        'unbilled_recognised_revenue' => 'Recognised revenue exceeds the invoiced revenue by :amount: invoice it before closing.',
        'open_commitments' => 'Purchase orders worth :amount are still open on the project.',
        'already_closed' => 'Project :number is :status: it cannot be closed again.',
    ],
    'milestone' => [
        'billed_locked' => 'The milestone is already billed and can no longer change.',
        'already_completed' => 'The milestone is already completed.',
        'project_closed' => 'Project :number is :status: its milestones can no longer change.',
        'not_in_project' => 'The milestone does not belong to the project.',
    ],
    'revenue' => [
        'project_closed' => 'Project :number is :status: revenue is no longer recognised on it.',
        'no_method' => 'The project has no revenue recognition method: its revenue is the invoiced revenue.',
        'percentage_only_poc' => 'A completion percentage can be stated only under the percentage-of-completion method.',
        'budget_required' => 'Cost-to-cost recognition needs an approved budget to estimate the total cost.',
        'already_recognised_for_date' => 'Revenue was already recognised for this date.',
        'already_recognised_later' => 'Revenue was already recognised for a later date: runs must follow date order.',
    ],
    'schedule' => [
        'end_before_start' => 'The planned end date must be on or after the planned start date.',
        'dependency_cycle' => 'The task dependencies form a cycle: the critical path cannot be computed.',
        'project_closed' => 'Project :number is :status: its schedule can no longer change.',
    ],
    'dependency' => [
        'predecessor_other_project' => 'The predecessor must be a task of the same project.',
        'self' => 'A task cannot depend on itself.',
        'already_exists' => 'This dependency already exists.',
        'cycle' => 'This dependency would create a cycle in the schedule.',
    ],
    'timesheet' => [
        'approve_only_submitted' => 'Timesheet is :status: only submitted timesheets can be approved.',
        'reject_only_submitted' => 'Timesheet is :status: only submitted timesheets can be rejected.',
        'locked' => 'Timesheet is :status and locked: only draft or rejected timesheets can be changed.',
        'project_closed' => 'Project :number is :status: it no longer accepts time.',
        'hours_range' => 'Hours worked must be greater than 0 and at most 24.',
        'overtime_range' => 'Overtime hours must be between 0 and the hours worked.',
        'day_exceeds_24' => 'The person already has :hours hours booked on :date: a day cannot exceed 24 hours.',
        'rate_missing' => 'No cost and billing rate is configured for this person on this date: add a rate card or a project resource.',
    ],
];
