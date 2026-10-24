<?php

declare(strict_types=1);

return [

    'terminal_label' => 'POS terminal',
    'terminal_inactive' => 'The POS terminal is not active.',
    'terminal_busy' => 'The terminal already has an open session.',
    'session_closed' => 'The POS session is not open.',
    'cash_tendered_too_low' => 'The cash tendered does not cover the sale total.',
    'mixed_split_invalid' => 'For mixed payments the cash amount must be positive and lower than the total.',
    'prepaid_card_required' => 'Prepaid payments require a voucher or prepaid card.',
    'already_refunded' => 'This sale has already been refunded.',
    'already_transmitted' => 'A sale already transmitted to the fiscal printer cannot be refunded here.',
    'refund_session_closed' => 'Sales of a closed session cannot be refunded.',
    'rt_already_transmitted' => 'This sale has already been transmitted to the fiscal printer.',
    'rt_refunded_sale' => 'A refunded sale cannot be transmitted.',
    'refund_note' => 'Refund of receipt :number',
    'earn_note' => 'Points for receipt :number',
    'redeem_note' => 'Payment of receipt :number',

    'messages' => [
        'terminal_created' => 'POS terminal created successfully',
        'session_opened' => 'POS session opened',
        'session_closed' => 'POS session closed',
        'session_report' => 'POS session report retrieved successfully',
        'sale_registered' => 'Sale registered successfully',
        'sale_retrieved' => 'Sale retrieved successfully',
        'sale_refunded' => 'Sale refunded successfully',
        'rt_transmitted' => 'Sale transmitted to the fiscal printer',
    ],

];
