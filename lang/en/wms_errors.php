<?php

declare(strict_types=1);

return [
    'task' => [
        'not_editable' => 'A task that is :status can no longer be changed.',
        'field_required' => 'A :type task needs this field.',
        'bin_other_warehouse' => 'The bin does not belong to the warehouse of the task.',
        'bin_locked' => 'The bin :bin is locked: nothing can be moved into it.',
        'same_bin' => 'The destination bin must differ from the source bin.',
        'not_deletable' => 'A task that is :status cannot be deleted: cancel it instead.',
        'invalid_transition' => 'The task is :status: this step is not possible.',
        'over_planned' => 'The quantity cannot exceed the planned :planned.',
        'use_short' => 'Less than the planned :planned was done: close the task as short with a reason.',
        'short_below_planned' => 'A short task must be below the planned :planned: confirm it instead.',
        'assigned_to_other' => 'The task is assigned to another operator.',
        'wave_not_released' => 'The wave of the task has not been released yet.',
        'stock_rejected' => 'The stock movement was rejected: :reason',
        'not_a_member' => 'The user is not a member of this company.',
    ],
    'wave_rule' => [
        'bounds' => 'The minimum number of tasks cannot exceed the maximum.',
    ],
    'slotting_rule' => [
        'bounds' => 'The minimum velocity cannot exceed the maximum.',
    ],
    'slotting' => [
        'reason' => 'Class :class item at :velocity picks per day sits in a :from bin: it belongs in a :to bin.',
        'stock_moved' => 'The item is no longer stocked in the source bin: generate the recommendations again.',
        'not_pending' => 'The recommendation is already :status.',
    ],
    'cross_dock' => [
        'received_over_inbound' => 'The received quantity cannot exceed the inbound quantity of :inbound.',
        'received_below_outbound' => 'The received quantity cannot be below the :outbound already matched to the outbound side.',
        'outbound_over_available' => 'The outbound quantity cannot exceed the available :available.',
        'nothing_matched' => 'Nothing is matched to the outbound side yet.',
        'invalid_transition' => 'The cross-dock order is :status: this step is not possible.',
        'door_other_warehouse' => 'The dock door does not belong to the warehouse of the order.',
        'door_direction' => 'The dock door does not serve :direction traffic.',
        'duplicate_item' => 'The stock item appears on more than one line.',
        'outbound_over_inbound' => 'The outbound quantity cannot exceed the inbound quantity of :inbound.',
        'line_not_found' => 'The line does not belong to this cross-dock order.',
    ],
    'dock' => [
        'direction_in_use' => 'The door has live appointments of another direction: the direction cannot change.',
        'door_in_use' => 'The door still has live appointments or open cross-dock orders.',
        'not_editable' => 'An appointment that is :status can no longer be changed.',
        'door_inactive' => 'The dock door :door is not active.',
        'door_direction' => 'The dock door :door does not serve :direction traffic.',
        'ends_before_start' => 'The appointment must end after it starts.',
        'conflict' => 'The dock door :door is already booked from :from to :to.',
        'cross_dock_other_warehouse' => 'The cross-dock order belongs to another warehouse.',
        'not_deletable' => 'An appointment that is :status cannot be deleted.',
        'invalid_transition' => 'The appointment is :status: this step is not possible.',
        'no_show_too_early' => 'The appointment has not started yet: it cannot be a no-show.',
    ],
    'wave' => [
        'rule_inactive' => 'The wave rule is not active.',
        'not_enough_tasks' => 'Only :found open pick tasks match the rule, the minimum is :minimum.',
        'tasks_open' => ':count tasks of the wave are still open.',
        'work_started' => 'Work on the wave has already started: it cannot be cancelled.',
        'invalid_transition' => 'The wave is :status: this step is not possible.',
    ],
];
