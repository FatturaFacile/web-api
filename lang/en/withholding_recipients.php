<?php

declare(strict_types=1);

return [
    'messages' => [
        'saved' => 'Data saved.',
        'deleted' => 'Item deleted.',
        'inl_generated' => 'INL preventive communication generated.',
        'inl_sent' => 'INL communication recorded as sent.',
        'payment_posted' => 'Payment posted and F24 scheduled.',
        'payments_posted' => 'Payments posted and F24 scheduled.',
        'returns_generated' => 'Annual returns generated.',
    ],
    'errors' => [
        'inl_not_applicable' => 'Only occasional workers need the INL communication.',
        'inl_cancelled' => 'A cancelled communication cannot be sent.',
        'inl_notification_missing' => 'Payment blocked: no INL preventive communication has been sent for this worker.',
        'inl_notification_late' => 'Payment blocked: the INL communication was sent after the work started.',
        'inl_notification_expired' => 'Payment blocked: the INL communication does not cover the date of the service.',
        'recipient_inactive' => 'The recipient is not active.',
        'service_after_payment' => 'The service cannot be dated after the payment.',
        'net_not_positive' => 'The net payable amount is not positive.',
        'regime_locked' => 'The regime cannot change once compensations have been posted.',
        'recipient_has_settlements' => 'A recipient with posted compensations cannot be deleted.',
        'certificate_not_applicable' => 'Only agents can declare the reduced withholding.',
    ],
    'inl' => [
        'title' => 'Preventive communication of occasional self-employed work',
        'legal_basis' => 'Art. 14 D.Lgs. 81/2008 — to be sent to the labour inspectorate (INL) before the work starts.',
        'client' => 'Client',
        'client_tax_id' => 'Client tax code',
        'worker' => 'Worker',
        'worker_tax_id' => 'Worker tax code',
        'activity' => 'Activity',
        'place' => 'Place of work',
        'start' => 'Start date',
        'end' => 'End date',
        'compensation' => 'Estimated compensation',
        'recipient' => 'Recipient',
    ],
];
