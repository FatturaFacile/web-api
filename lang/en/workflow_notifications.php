<?php

declare(strict_types=1);

return [
    'request' => ['title' => 'Approval requested', 'body' => 'A :type :label waits for your decision. Due: :due. Open the link to review it.'],
    'reminder' => ['title' => 'Approval reminder', 'body' => 'A :type :label is still waiting for your decision (due :due).'],
    'escalation' => ['title' => 'Approval escalated to you', 'body' => 'A :type :label was not decided in time and now needs your decision.'],
    'approved' => ['title' => 'Request approved', 'body' => 'Your :type :label was approved.'],
    'rejected' => ['title' => 'Request rejected', 'body' => 'Your :type :label was rejected. Open the link to see the reason.'],
    'changes_requested' => ['title' => 'Changes requested', 'body' => 'An approver asked for changes to your :type :label. Open the link to see the reason.'],
    'expired' => ['title' => 'Approval expired', 'body' => 'Nobody decided on your :type :label in time. It was closed.'],
    'withdrawn' => ['title' => 'Request withdrawn', 'body' => 'The requester withdrew the :type :label. Nothing is left to decide.'],
    'blocked' => ['title' => 'Approval blocked', 'body' => 'The approval of a :type :label cannot continue because no approver can be found. Assign an approver or cancel it.'],
    'delegation' => ['title' => 'Delegation activated', 'body' => 'You stand in for a colleague from :from to :to. Their approvals appear in your inbox.'],
    'subject' => [
        'sales_quote' => 'quote',
        'purchase_request' => 'purchase request',
        'purchase_order' => 'purchase order',
        'supplier_invoice' => 'supplier invoice',
        'expense_report' => 'expense report',
        'leave_request' => 'leave request',
        'payment_batch' => 'payment batch',
        'supplier_master' => 'supplier record',
        'dms_document' => 'document',
        'document' => 'document',
    ],
];
