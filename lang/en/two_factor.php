<?php

declare(strict_types=1);

return [
    'messages' => [
        'challenge_required' => 'Enter the code of your authenticator app to complete the sign-in.',
        'setup_started' => 'Scan the QR code with your authenticator app and confirm with the first code.',
        'enabled' => 'Two-factor authentication enabled. Store the recovery codes in a safe place.',
        'disabled' => 'Two-factor authentication disabled.',
        'recovery_codes_regenerated' => 'New recovery codes generated. The previous ones no longer work.',
        'policy_updated' => 'Two-factor authentication policy updated.',
        'member_reset' => 'The user\'s two-factor authentication has been reset.',
    ],
    'errors' => [
        'challenge_invalid_title' => 'Sign-in expired',
        'challenge_invalid_detail' => 'The verification expired or is not valid. Sign in again with email and password.',
        'code_invalid_title' => 'Invalid code',
        'code_invalid_detail' => 'The code is not valid or has already been used.',
        'password_invalid' => 'The password is not correct.',
        'already_enabled_title' => 'Already enabled',
        'already_enabled_detail' => 'Two-factor authentication is already enabled. Disable it before setting it up again.',
        'no_pending_setup_title' => 'No setup in progress',
        'no_pending_setup_detail' => 'Start the two-factor setup first.',
        'not_enabled_title' => 'Not enabled',
        'not_enabled_detail' => 'Two-factor authentication is not enabled.',
        'setup_required_title' => 'Two-factor authentication required',
        'setup_required_detail' => 'Your organisation requires two-factor authentication. Set it up to continue.',
        'admin_setup_required_title' => 'Set up your own two factors first',
        'admin_setup_required_detail' => 'Enable two-factor authentication on your account before making it mandatory.',
        'member_not_found_title' => 'User not found',
        'member_not_found_detail' => 'The user is not a member of this organisation.',
        'reset_not_allowed_title' => 'Reset not allowed',
        'reset_not_allowed_detail' => 'You cannot reset your own account, a platform administrator or a user who also belongs to other organisations.',
    ],
];
