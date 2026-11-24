<?php

declare(strict_types=1);

return [
    'cycle_dates_invalid' => 'The end of the cycle cannot be before its start.',
    'cycle_closed' => 'The goal cycle is closed: its goals can no longer be changed.',
    'goal_not_open' => 'The goal is completed or cancelled and takes no more check-ins.',
    'value_required' => 'The value of the key result is required.',
    'progress_required' => 'The progress of the goal is required.',
    'progress_is_derived' => 'The progress of this goal comes from its key results or child goals: check in on those.',
    'employee_required' => 'An employee goal needs an employee.',
    'team_required' => 'A team goal needs a team.',
    'parent_other_cycle' => 'The parent goal belongs to another cycle.',
    'parent_level_invalid' => 'The parent goal must be of a higher level (company, then team, then employee).',
    'parent_loop' => 'A goal cannot descend from itself.',
];
