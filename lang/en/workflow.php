<?php

declare(strict_types=1);

return [
    'messages' => [
        'instance_started' => 'Workflow approval instance started successfully',
        'decision_recorded' => 'Approval decision recorded successfully',
        'step_approved' => 'Step approved successfully',
        'step_rejected' => 'Step rejected',
        'instance_completed' => 'Workflow instance completed successfully',
        'instance_cancelled' => 'Workflow instance cancelled',
        'token_validated' => 'Remote approval token validated successfully',
    ],
    'errors' => [
        'unauthorized_approver' => 'User is not authorized or delegated to approve this workflow step.',
        'invalid_state' => 'The workflow is not in a valid state for the requested decision.',
    ],
];
