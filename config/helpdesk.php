<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Helpdesk
|--------------------------------------------------------------------------
|
| Defaults of the helpdesk of the service module. A tenant overrides the reopen window, the SLA warning threshold and
| the business hours calendar in its helpdesk settings.
|
*/

return [
    // Days after resolution during which a resolved or closed ticket can be reopened.
    'reopen_window_days' => (int) env('HELPDESK_REOPEN_WINDOW_DAYS', 7),

    // Share of an SLA target, in percent, after which a warning is sent.
    'sla_warning_percent' => (int) env('HELPDESK_SLA_WARNING_PERCENT', 80),

    // Priority from impact (rows) and urgency (columns), ITIL style.
    'priority_matrix' => [
        'high' => ['high' => 'critical', 'medium' => 'high', 'low' => 'medium'],
        'medium' => ['high' => 'high', 'medium' => 'medium', 'low' => 'low'],
        'low' => ['high' => 'medium', 'medium' => 'low', 'low' => 'low'],
    ],

    // Inbound e-mail: messages from one sender above this number per hour are treated as a mail loop and dropped.
    'inbound_max_messages_per_sender_per_hour' => (int) env('HELPDESK_INBOUND_SENDER_LIMIT', 20),

    // Domain used in the Message-ID of outbound replies.
    'message_id_domain' => env('HELPDESK_MESSAGE_ID_DOMAIN', 'helpdesk.fatturafacile.local'),

    // Days a CSAT survey link stays valid.
    'csat_link_days' => (int) env('HELPDESK_CSAT_LINK_DAYS', 30),

    // Largest attachment accepted on a ticket message, in kilobytes.
    'attachment_max_kb' => (int) env('HELPDESK_ATTACHMENT_MAX_KB', 10240),
];
