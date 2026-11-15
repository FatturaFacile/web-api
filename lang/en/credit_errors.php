<?php

declare(strict_types=1);

return [
    'customer' => [
        'missing' => 'The customer does not exist in this company.',
    ],
    'scorecard' => [
        'already_configured' => 'The company already has scorecard factors or rating bands: change them instead of creating the defaults.',
        'missing_factors' => 'The scorecard has no active factor: add at least one factor before scoring.',
        'no_band' => 'No rating band contains the score :score: adjust the score ranges of the rating bands.',
        'manual_missing' => 'Enter a value from 0 to 100 for the manual factor :code.',
    ],
    'band' => [
        'overlap' => 'The score range overlaps another rating band.',
        'code_taken' => 'Another rating band already uses this code.',
    ],
    'score' => [
        'missing' => 'The customer has no score yet: score the customer before asking for a proposal.',
    ],
    'limit' => [
        'pending_exists' => 'The customer already has a credit limit request waiting for a decision.',
        'amount_missing' => 'Enter the requested limit: there is no score to propose one from.',
        'not_pending' => 'Only a pending request can be decided or cancelled.',
        'same_user' => 'The user who made the request cannot decide it.',
        'note_required' => 'The limit is above the proposal of :proposed: explain the decision in a note.',
        'valid_until_past' => 'The limit cannot be valid until a day in the past.',
    ],
    'insurance' => [
        'dates' => 'The policy must end after it starts.',
        'policy_not_active' => 'The policy is not in force today.',
        'period' => 'The cover cannot last beyond the end of the policy.',
        'duplicate' => 'The customer already has a requested or granted cover under this policy.',
        'total_exceeded' => 'The covers granted under this policy (:granted) would go over its total cover.',
        'policy_has_covers' => 'The policy has covers: cancel it instead of deleting it.',
    ],
    'strategy' => [
        'days_order' => 'The days after the due date cannot be lower than those of the previous step.',
        'in_use' => 'An open case is already working with the steps of this strategy.',
        'default_required' => 'Mark another strategy as the default instead.',
    ],
    'case' => [
        'no_overdue' => 'The customer has no overdue invoice to collect.',
        'invoice_not_overdue' => 'Some invoices are not open and overdue invoices of the customer.',
        'invoice_in_case' => 'Some invoices are already in an open collection case.',
        'closed' => 'The case is closed.',
        'disputed' => 'The case has an open dispute: decide it first.',
        'promise_pending' => 'The case has a pending promise to pay: resolve it first.',
        'no_strategy' => 'The case has no active dunning strategy.',
        'no_more_steps' => 'The strategy has no further step.',
        'step_not_due' => 'The next step is due :days days after the due date: the oldest invoice is :overdue days overdue.',
        'open_disputes' => 'The case has open disputes: decide them before closing it.',
        'still_outstanding' => 'The case cannot be resolved: :amount is still outstanding.',
    ],
    'promise' => [
        'pending_exists' => 'The case already has a pending promise to pay.',
        'past_date' => 'The promised date cannot be in the past.',
        'amount_exceeds' => 'The promised amount is above the outstanding amount of :outstanding.',
        'not_pending' => 'The promise is already closed.',
        'paid_required' => 'Enter the amount paid to mark the promise as kept.',
        'paid_below' => 'The amount paid is below the promised :promised: mark the promise as broken.',
    ],
    'dispute' => [
        'invoice_not_open' => 'The invoice is not an open invoice of this customer.',
        'amount_exceeds' => 'The disputed amount is above what is still due on the invoice (:outstanding).',
        'case_mismatch' => 'The case is not an open case of this customer.',
        'not_open' => 'The dispute is already decided or is not open.',
        'credit_required' => 'Enter the amount credited to the customer.',
        'credit_exceeds' => 'The credited amount is above the disputed :disputed.',
    ],
];
