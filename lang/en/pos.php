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
    'rt_simulated_notice' => 'Simulated fiscal printer: nothing was sent to the Agenzia delle Entrate.',
    'refund_note' => 'Refund of receipt :number',
    'earn_note' => 'Points for receipt :number',
    'redeem_note' => 'Payment of receipt :number',

    'drawer_cash_insufficient' => 'The drawer does not hold enough cash for this movement.',
    'ticket_payment_excess' => 'The non-cash payments exceed the amount due, or cash was given for an amount already covered.',
    'ticket_payment_short' => 'The payments do not cover the ticket total.',
    'return_sale_not_returnable' => 'Only a completed sale can have goods returned.',
    'return_item_unknown' => 'The line does not belong to this sale or is a discount line.',
    'return_quantity_exceeds' => 'The quantity is zero or above what is left to return.',
    'x_report_open_only' => 'The X report is read on an open session: read the Z report of a closed one.',
    'z_report_closed_only' => 'The Z report is issued when the session is closed: read the X report of an open one.',

    'messages' => [
        'terminal_created' => 'POS terminal created successfully',
        'session_opened' => 'POS session opened',
        'session_closed' => 'POS session closed',
        'session_report' => 'POS session report retrieved successfully',
        'sale_registered' => 'Sale registered successfully',
        'sale_retrieved' => 'Sale retrieved successfully',
        'sale_refunded' => 'Sale refunded successfully',
        'rt_transmitted' => 'Sale transmitted to the fiscal printer',
        'rt_simulated' => 'Sale recorded by the simulated fiscal printer: nothing was sent to the Agenzia delle Entrate',
        'rt_not_transmitted' => 'The fiscal printer did not accept the sale yet: the receipt is kept and can be sent again',
    ],

];
