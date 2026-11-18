<?php

declare(strict_types=1);

return [
    'mail' => [
        'magic_link' => [
            'subject' => 'Your sign-in link for :company',
            'greeting' => 'Hello :name',
            'intro' => 'Use the button below to open your customer area at :company. The link works once and expires in :minutes minutes.',
            'action' => 'Open my area',
            'security' => 'If you did not ask for this link, you can ignore this email.',
        ],
        'otp' => [
            'subject' => 'Your verification code for :company',
            'greeting' => 'Hello :name',
            'intro' => 'Enter this code to finish signing in to :company. It expires in :minutes minutes.',
            'security' => 'Never share this code. If you did not try to sign in, ignore this email.',
        ],
    ],
    'messages' => [
        'link_requested' => 'If the address belongs to a customer, a sign-in link has been sent.',
        'otp_required' => 'A verification code has been sent to your email address.',
        'authenticated' => 'Signed in successfully.',
        'logged_out' => 'Signed out successfully.',
        'checkout_created' => 'Payment page created.',
        'ticket_created' => 'Your request has been sent.',
    ],
    'errors' => [
        'otp_invalid' => 'The verification code is not correct.',
        'otp_locked' => 'Too many wrong codes. Request a new sign-in link.',
        'session_required' => 'Sign in to the customer area to continue.',
        'invoice_not_payable' => 'This invoice has nothing left to pay online.',
        'xml_not_available' => 'No electronic invoice file exists for this document.',
        'ticket_target_invalid' => 'The linked document does not belong to your account.',
    ],
];
