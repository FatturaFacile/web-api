<?php

declare(strict_types=1);

return [
    'messages' => [
        'requested' => 'If the email address exists in our system, you will receive an email with instructions to recover your password.',
        'token_valid' => 'The link is valid.',
        'updated' => 'Password updated. You can now sign in with your new password.',
    ],
    'errors' => [
        'token_invalid_title' => 'Invalid link',
        'token_invalid_detail' => 'The password reset link is not valid or has already been used.',
        'token_expired_title' => 'Link expired',
        'token_expired_detail' => 'The password reset link has expired. Please request a new one.',
        'locked_title' => 'Too many attempts',
        'locked_detail' => 'Too many failed attempts. Please try again later.',
    ],
    'mail' => [
        'reset' => [
            'subject' => 'Reset your password - :app',
            'intro' => 'We received a request to reset the password of your :app account.',
            'action' => 'Reset password',
            'expires' => 'The link is valid until :date and can be used only once.',
            'security' => 'If you did not make this request, ignore this message: your password stays the same. Never share this link.',
        ],
        'changed' => [
            'subject' => 'Your password has been changed - :app',
            'intro' => 'The password of your :app account has been changed. For your security, all active sessions have been closed.',
            'date' => 'Date and time: :date',
            'ip' => 'IP address: :ip',
            'device' => 'Browser and device: :device',
            'unknown' => 'not available',
            'security' => 'If this was not you, contact support immediately: someone may have had access to your mailbox.',
            'security_profile' => 'If this was not you, someone knows your password: reset it right away with “Forgot password” and contact support.',
        ],
        'alert' => [
            'subject' => 'Many password recovery requests - :app',
            'intro' => 'We received many password recovery requests for your :app account and have temporarily blocked them.',
            'security' => 'Your password has not been changed. If this was not you, you can ignore this message; if you keep receiving it, contact support.',
        ],
    ],
];
