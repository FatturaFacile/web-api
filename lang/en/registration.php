<?php

declare(strict_types=1);

return [
    'messages' => [
        'registered' => 'Registration received. Check your inbox to confirm your email address.',
        'verified' => 'Email address confirmed. Your account is now awaiting approval.',
        'resent' => 'If the address needs confirming, you will receive a new email.',
        'requests_listed' => 'Access requests retrieved',
        'request_decided' => 'Access request updated',
    ],
    'errors' => [
        'email_not_verified_title' => 'Email not confirmed',
        'email_not_verified_detail' => 'Please confirm your email address before signing in.',
        'approval_pending_title' => 'Account awaiting approval',
        'approval_pending_detail' => 'An administrator still has to approve your access to the company.',
        'access_rejected_title' => 'Access not approved',
        'access_rejected_detail' => 'Your access request was not approved.',
        'token_invalid_title' => 'Invalid link',
        'token_invalid_detail' => 'The confirmation link is invalid or has already been used.',
        'token_expired_title' => 'Expired link',
        'token_expired_detail' => 'The confirmation link has expired. Please request a new one.',
        'forbidden_title' => 'Operation not allowed',
        'forbidden_detail' => 'This operation is reserved for platform administrators.',
        'not_decidable_title' => 'Request cannot be changed',
        'not_decidable_detail' => 'The request has already been handled, or the user has not confirmed the email address yet.',
        'invalid_tax_id' => 'The VAT number or UID is not valid for the selected country.',
    ],
    'mail' => [
        'greeting' => 'Hello :name,',
        'support' => 'For support, write to :email.',
        'signature' => 'The :app team',
        'verify' => [
            'subject' => 'Confirm your email address - :app',
            'intro' => 'Thanks for signing up to :app. Please confirm your email address to continue.',
            'action' => 'Confirm email address',
            'expires' => 'This link is valid until :date.',
            'approval' => 'After confirming, an administrator will approve your access to the company and you will receive an email.',
            'security' => 'If you did not request this registration, ignore this message: no account will be activated.',
        ],
        'exists' => [
            'subject' => 'Registration attempt on your account - :app',
            'intro' => 'Someone tried to sign up to :app with this email address, which already belongs to an account.',
            'security' => 'If it was you, sign in with your credentials. Otherwise you can ignore this message: your account has not been changed.',
        ],
        'decision' => [
            'approved_subject' => 'Access approved - :app',
            'approved_body' => 'Your access to :app has been approved. You can now sign in.',
            'rejected_subject' => 'Access not approved - :app',
            'rejected_body' => 'Your access request to :app was not approved.',
            'action' => 'Sign in',
        ],
    ],
];
