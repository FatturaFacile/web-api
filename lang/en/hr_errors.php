<?php

declare(strict_types=1);

return [
    'time_schedule_group_rule' => [
        'version_immutable' => 'A rule version cannot be changed: add a new one.',
    ],
    'recruiting' => [
        'job_posting_not_open' => 'The job posting is not open.',
        'application_closed_by_actions' => 'An application is closed with the hire or reject actions.',
        'application_already_closed' => 'The application is already closed.',
    ],
    'time_record' => [
        'invalid_in_state' => 'Clock event not valid in the current state (:state).',
        'deleted_not_editable' => 'A deleted clock event cannot be changed: enter a new one.',
        'correction_reason_required' => 'State the reason for the correction (it stays in the history).',
    ],
];
