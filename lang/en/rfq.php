<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation_subject' => 'Request for Quotation :number — :title',
        'invitation_greeting' => 'Hello :supplier,',
        'invitation_intro' => ':buyer has invited you to submit a quotation for Request for Quotation :number (:title).',
        'invitation_deadline' => 'Submission deadline: :deadline (UTC).',
        'invitation_cta' => 'Open Bidding Portal',
        'invitation_outro' => 'This secure link is personal to your company and expires automatically at the submission deadline.',

        'deadline_extended_subject' => 'Deadline Extended: RFQ :number — :title',
        'deadline_extended_body' => 'The submission deadline for RFQ :number (:title) issued by :buyer has been extended to :deadline (UTC).',

        'award_subject' => 'Award Notification: RFQ :number — :title',
        'award_greeting' => 'Congratulations :supplier,',
        'award_body_full' => ':buyer has awarded you the full contract for RFQ :number (:title).',
        'award_body_partial' => ':buyer has awarded you selected line items under RFQ :number (:title).',
        'award_po_numbers' => 'Generated Purchase Order(s): :orders',

        'regret_subject' => 'Outcome Notification: RFQ :number — :title',
        'regret_greeting' => 'Dear :supplier,',
        'regret_body' => 'Thank you for participating in RFQ :number (:title) issued by :buyer. After careful evaluation, this tender has been awarded to another vendor. We appreciate your effort and look forward to future opportunities.',

        'cancelled_subject' => 'Cancellation Notice: RFQ :number — :title',
        'cancelled_body' => 'Please note that Request for Quotation :number (:title) issued by :buyer has been cancelled.',
        'cancelled_reason' => 'Reason: :reason',
    ],
];
