<?php

declare(strict_types=1);

return [
    'password' => [
        'breached' => 'This password appears in known data breaches: choose a different one.',
    ],
    'messages' => [
        'member_unlocked' => 'The account was unlocked.',
        'scim_mapping_saved' => 'Group mapping saved.',
        'scim_mapping_removed' => 'Group mapping removed.',
        'security_audit_retrieved' => 'Security audit log retrieved.',
    ],
    'errors' => [
        'lockout_cap_too_small' => 'The maximum lock duration cannot be shorter than the base lock duration.',
        'unknown_event_type' => 'Unknown event type: :type.',
    ],
    'mail' => [
        'unknown' => 'unknown',
        'locked' => [
            'subject' => ':app: your account was locked',
            'intro' => 'Your :app account was locked for :minutes minutes after too many wrong passwords.',
            'until' => 'Locked until: :date',
            'ip' => 'IP address of the attempts: :ip',
            'device' => 'Device: :device',
            'security' => 'If it was not you, someone may be trying to guess your password: change it as soon as the lock ends and turn on two-factor authentication. Repeated lockouts make the next lock longer.',
        ],
        'unlocked' => [
            'subject' => ':app: your account was unlocked',
            'intro' => 'An administrator of your organization unlocked your :app account: you can sign in again.',
            'security' => 'If you did not ask for it, contact your organization administrator.',
        ],
    ],
];
