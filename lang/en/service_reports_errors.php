<?php

declare(strict_types=1);

return [
    'consolidated.min_two' => 'At least two service reports are required to generate a consolidated invoice.',
    'consolidated.not_found' => 'One or more service reports could not be found.',
    'consolidated.same_customer' => 'All consolidated service reports must belong to the same customer/contact.',
    'consolidated.cancelled' => 'Service report :number is cancelled and cannot be invoiced.',
    'consolidated.not_signed' => 'Service report :number is not signed by the customer.',
    'consolidated.already_invoiced' => 'Service report :number has already been invoiced.',
    'consolidated.no_billable_lines' => 'None of the selected service reports contain billable lines.',
    'invoice.cancelled' => 'Cancelled service reports cannot be invoiced.',
    'invoice.not_signed' => 'The service report must be signed by the customer before generating an invoice.',
    'invoice.already_invoiced' => 'This service report has already been invoiced.',
    'invoice.no_billable_lines' => 'The service report has no billable lines.',
    'save.signed_locked' => 'Signed service reports cannot be modified.',
    'save.invoiced_locked' => 'Invoiced service reports cannot be modified.',
    'sign.already_signed' => 'This service report is already signed.',
    'sign.invalid_signature' => 'Invalid PNG signature image payload.',
];
