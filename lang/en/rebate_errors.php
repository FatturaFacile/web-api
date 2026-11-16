<?php

declare(strict_types=1);

return [
    'agreement' => [
        'not_active' => 'The agreement is not active.',
        'locked' => 'Only the name, the notes and the end date of an agreement that is no longer a draft can be changed.',
        'period' => 'The end date cannot be before the start date.',
        'not_draft' => 'Only a draft agreement can be activated.',
        'tiers_required' => 'Add at least one tier before activating the agreement.',
        'delete_draft_only' => 'Only a draft agreement without accruals can be deleted.',
        'code_taken' => 'The code :code is already used by another agreement.',
        'tiers_distinct' => 'Every tier needs a different threshold.',
    ],
    'accrual' => [
        'date_outside' => 'The date must fall inside the agreement period (:from to :to).',
    ],
    'settlement' => [
        'nothing_to_settle' => 'There is no accrued rebate to settle.',
        'tax_rate_required' => 'Enter the VAT rate of the credit note.',
        'credit_note_note' => 'Volume rebate :name (:code)',
        'credit_note_line' => 'Volume rebate :name, period :from to :to',
    ],
    'promotion' => [
        'percentage_max' => 'A percentage discount cannot exceed 100.',
        'period' => 'The end date cannot be before the start date.',
        'code_taken' => 'The code :code is already used by another promotion.',
    ],
];
