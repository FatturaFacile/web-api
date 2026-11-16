<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation' => [
            'subject' => 'You are invited to join :tenant',
            'title' => 'Join :tenant',
            'intro' => 'You have been invited to join :tenant on :app.',
            'intro_by' => ':name invited you to join :tenant on :app.',
            'action' => 'Accept the invitation',
            'expires' => 'The invitation expires on :date.',
            'ignore' => 'If you did not expect this invitation you can ignore this email.',
        ],
    ],
    'errors' => [
        'plan_limit_title' => 'Plan limit reached',
        'plan_limit_detail' => 'The subscription plan allows at most :max :limit. Upgrade the plan to add more.',
        'invitation_invalid_title' => 'Invitation not valid',
        'role_in_use_title' => 'Role in use',
        'delegation_invalid_title' => 'Delegation not valid',
        'import_invalid_title' => 'Import file not valid',
    ],
];
