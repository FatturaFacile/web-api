<?php

declare(strict_types=1);

return [
    'messages' => [
        'project_created' => 'Project created successfully',
        'project_updated' => 'Project updated successfully',
        'project_retrieved' => 'Project retrieved successfully',
        'timesheet_logged' => 'Timesheet entry logged successfully',
        'expense_recorded' => 'Project expense recorded successfully',
        'milestone_billed' => 'Milestone billed successfully',
        'wip_capitalized' => 'Work in progress (WIP) capitalized successfully',
        'revenue_recognized' => 'Project revenue recognized per IFRS 15',
    ],
    'errors' => [
        'budget_exceeded' => 'The transaction exceeds the approved project budget.',
        'project_closed' => 'Costs cannot be posted to a closed project.',
    ],
];
