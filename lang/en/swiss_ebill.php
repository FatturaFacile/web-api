<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Records retrieved successfully.',
        'loaded' => 'Record retrieved successfully.',
        'saved' => 'The Swiss eBill settings were saved.',
        'connection_ok' => 'The eBill gateway is reachable and accepted the credentials.',
        'connection_failed' => 'The eBill gateway test failed.',
        'secret_rotated' => 'A new webhook secret was issued. Copy it now: it is not shown again.',
        'participant_found' => 'The participant is enrolled in the eBill directory.',
        'participant_not_found' => 'No participant matches this identifier in the eBill directory.',
        'customer_synced' => 'The eBill status of the customer was verified.',
        'customer_linked' => 'The customer was linked to the eBill participant.',
        'bulk_verify_queued' => 'The verification of the customers runs in the background.',
        'transmission_queued' => 'The invoice was queued for eBill delivery.',
        'transmission_cancelled' => 'The eBill was recalled.',
        'webhook_processed' => 'The notifications were processed.',
    ],

    'errors' => [
        'ebill_disabled' => 'Swiss eBill is not enabled for this company.',
        'not_configured' => 'Swiss eBill is not configured for this company.',
        'already_transmitted' => 'This invoice already has an active eBill transmission.',
        'cannot_revise' => 'Only a rejected, failed or cancelled transmission can be revised.',
        'cannot_retry' => 'Only a transmission that failed technically can be retried.',
        'cannot_cancel' => 'This eBill can no longer be recalled.',
        'gateway_error' => 'The eBill gateway refused the request.',
        'gateway_unavailable' => 'The eBill gateway cannot be reached right now. Try again later.',
        'payload_invalid' => 'The eBill cannot be built: mandatory data is missing or invalid.',
        'invalid_transition' => 'The eBill cannot move to this status from its current one.',
        'invalid_signature' => 'The webhook signature is not valid.',
        'unknown_webhook' => 'Unknown webhook endpoint.',
        'unknown_transaction' => 'No eBill carries this transaction id.',
        'payload_unreadable' => 'The webhook body cannot be read.',
        'participant_not_found' => 'The participant is not in the eBill directory.',
        'invalid_lookup_value' => 'The identifier is not valid for this lookup type.',
        'payload_unavailable' => 'No payload is stored for this eBill.',
        'sandbox_not_allowed' => 'The sandbox simulator is not available in production.',
    ],

    'notification' => [
        'rejected_subject' => 'eBill rejected by the customer: invoice :number',
        'rejected_line' => 'The customer :customer rejected the eBill of invoice :number in the e-banking.',
        'rejected_code' => 'Rejection code: :code',
        'rejected_reason' => 'Reason given by the customer: :reason',
        'rejected_action' => 'Open the invoice, correct what is needed and use "Revise & resend eBill".',
    ],
];
