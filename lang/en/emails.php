<?php

declare(strict_types=1);

return [

    'greeting' => 'Dear :name,',
    'closing' => 'Kind regards',

    'payment_reminder' => [
        'subject' => [
            'level_1' => 'Payment reminder - invoice :number',
            'level_2' => 'Overdue payment notice - invoice :number',
            'level_3' => 'Final payment notice - invoice :number',
        ],
        'body' => [
            'level_1' => 'Invoice <strong>:number</strong>, due on :due_date, is still open. If you have already paid it, please disregard this message.',
            'level_2' => 'Despite our previous reminder, invoice <strong>:number</strong>, due on :due_date, is still unpaid. Please arrange payment as soon as possible.',
            'level_3' => 'This is the final notice for invoice <strong>:number</strong>, due on :due_date. If payment is not received, we may start debt recovery proceedings.',
        ],
        'amount_due' => 'Outstanding amount: <strong>:amount :currency</strong>',
    ],

    'subscription_renewal' => [
        'subject' => 'Subscription renewal :number',
        'body' => 'Your subscription <strong>:name</strong> (:number) ends on :end_date.',
        'auto_renew' => 'It will be renewed automatically.',
        'manual_renew' => 'It will not be renewed automatically: please contact us if you wish to continue.',
    ],
    'document' => [
        'subject' => [
            'invoice' => 'Invoice :number',
            'quote' => 'Quote :number',
            'sales_order' => 'Order confirmation :number',
            'delivery_note' => 'Delivery note :number',
            'work_order' => 'Work report :number',
        ],
        'intro' => [
            'invoice' => 'Here are the details of invoice :number.',
            'quote' => 'Here are the details of quote :number.',
            'sales_order' => 'Here are the details of order :number.',
            'delivery_note' => 'Here are the details of delivery note :number.',
            'work_order' => 'Here are the details of work report :number.',
            'document' => 'Here are the details of document :number.',
        ],
        'number' => 'Number',
        'date' => 'Date',
        'total' => 'Total',
    ],

    'account_statement' => [
        'subject' => 'Account statement as of :date',
        'intro' => 'Here is your account statement as of :date.',
        'columns' => [
            'invoice' => 'Invoice',
            'due_date' => 'Due date',
            'balance' => 'Balance',
        ],
        'total_due' => 'Total due',
        'overdue' => 'Of which overdue',
        'no_open_items' => 'There are no open items.',
    ],

];
