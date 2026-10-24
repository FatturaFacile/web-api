<?php

declare(strict_types=1);

return [
    'unknown_uuid' => 'Unknown SDI UUID.',
    'unsupported_event' => 'Unsupported event type.',
    'status_final' => 'The invoice SDI status is already final.',
    'invalid_fatturapa_xml' => 'The document is not a valid FatturaPA XML.',

    'messages' => [
        'sent' => 'Invoice sent to SDI',
        'logs' => 'SDI logs retrieved successfully',
        'notification_received' => 'Notification received',
    ],
    'send_failed_title' => 'SDI transmission failed',
    'sending_disabled' => 'SDI transmission is disabled for this tenant.',
    'not_eligible' => 'Only Italian electronic invoices can be sent to SDI.',
    'already_sent' => 'The invoice has already been transmitted to SDI (status: :status).',
    'invalid_webhook_secret' => 'Invalid webhook secret.',
    'unknown_preference_group' => 'Unknown preference group.',
];
