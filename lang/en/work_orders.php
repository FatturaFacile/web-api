<?php

declare(strict_types=1);

return [

    'locked' => 'A signed or invoiced work report can no longer be edited.',
    'already_signed' => 'This work report has already been signed.',
    'not_signable' => 'Only draft or completed work reports can be signed.',
    'not_invoiceable' => 'Only completed or signed work reports can be invoiced.',
    'no_lines' => 'A work report without lines cannot be invoiced.',
    'not_completable' => 'Only draft work reports can be completed.',
    'internal_not_invoiceable' => 'An internal work order has no customer and cannot be invoiced.',
    'visit_requires_technician' => 'A visit needs a technician: state one or assign the work order first.',
    'part_unknown_product' => 'No product of the company has this item number.',
    'part_not_open' => 'Parts can be consumed only on a work order that is scheduled, dispatched or in progress.',
    'part_quantity_positive' => 'The quantity must be greater than zero.',
    'billing_not_completed' => 'Only a completed work order can be invoiced.',
    'billing_not_billable' => 'This work order is not billable.',
    'billing_already_invoiced' => 'This work order has already been invoiced.',
    'billing_nothing_to_invoice' => 'There is nothing billable on this work order.',

    'messages' => [
        'created' => 'Work report created successfully',
        'updated' => 'Work report updated successfully',
        'retrieved' => 'Work report retrieved successfully',
        'completed' => 'Work report completed',
        'signed' => 'Work report signed successfully',
        'invoiced' => 'Invoice created from work report',
    ],

];
