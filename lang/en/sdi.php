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
        'channel_updated' => 'SDI channel updated',
        'poll_queued' => 'SDI channel read queued',
    ],
    'send_failed_title' => 'SDI transmission failed',
    'sending_disabled' => 'SDI transmission is disabled for this tenant.',
    'not_eligible' => 'Only Italian electronic invoices can be sent to SDI.',
    'already_sent' => 'The invoice has already been transmitted to SDI (status: :status).',
    'invalid_webhook_secret' => 'Invalid webhook secret.',
    'unknown_preference_group' => 'Unknown preference group.',
    'channel_not_configured' => 'No SDI channel is configured for this company.',
    'invalid_schema' => 'The invoice does not comply with the FatturaPA schema: :errors',
    'credentials_missing' => 'Credential :key is missing for the SDI provider :provider.',
    'provider_unreachable' => 'The SDI provider :provider cannot be reached.',
    'provider_error' => 'The SDI provider :provider answered :status: :error',
    'pec_not_configured' => 'The certified mailbox of this company is not fully configured.',
    'pec_too_large' => 'SDI accepts certified messages up to 30 MB.',
    'pec_send_failed' => 'The certified message could not be sent: :error',
    'preflight' => [
        'tenant_vat' => 'The VAT number of the company is missing.',
        'tenant_address' => 'The company address field :field is missing.',
        'customer_identity' => 'The customer needs a VAT number or a fiscal code.',
        'customer_address' => 'The customer needs a complete billing address.',
        'zero_rate_nature' => 'Zero-rated lines need a VAT nature: set it on the SDI channel of the company.',
        'pec_public_administration' => 'Invoices for public administrations need a qualified signature: use an API provider.',
        'pec_recipient_is_sdi' => 'The customer certified address cannot be a mailbox of SDI.',
        'pec_waiting_address' => 'SDI has not assigned the dedicated address yet: wait for its answer to the first file.',
    ],
];
