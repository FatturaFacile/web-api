<?php

declare(strict_types=1);

return [
    'agreement' => [
        'bonus_required' => 'Enter the target and the amount of the fixed bonus.',
        'contributions_required' => 'Add at least one contribution before activating the agreement.',
        'version_conflict' => 'The agreement was changed by someone else. Reload it and try again.',
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
        'purchase_only' => 'Settlement proposals are available for supplier (purchase) agreements only.',
        'invoice_required' => 'Choose the supplier invoice to set the rebate off against.',
        'invoice_not_found' => 'The invoice does not exist or does not belong to the supplier of the agreement.',
        'insufficient_open_balance' => 'The open balance of the invoice (:open) does not cover the rebate.',
        'invalid_transition' => 'This step is not allowed for a proposal that is :status.',
        'version_conflict' => 'The proposal was changed by someone else. Reload it and try again.',
        'vat_account_required' => 'Choose the VAT account to post the VAT of the rebate.',
        'charge_note' => 'Supplier rebate :name (:code)',
        'charge_line' => 'Supplier rebate :name, period :from to :to',
        'set_off_description' => 'Rebate :code set off against invoice :invoice',
        'nothing_to_settle' => 'There is no accrued rebate to settle.',
        'tax_rate_required' => 'Enter the VAT rate of the credit note.',
        'credit_note_note' => 'Volume rebate :name (:code)',
        'credit_note_line' => 'Volume rebate :name, period :from to :to',
    ],
    'contribution' => [
        'agreement_closed' => 'The agreement is closed: contributions can no longer be added.',
        'locked' => 'Only a planned contribution can be changed or removed.',
    ],
    'run' => [
        'purchase_only' => 'Accrual runs are available for supplier (purchase) agreements only.',
        'period_locked' => 'The period is locked: an accrual run already covers it up to :date. Reverse that run first.',
        'not_posted' => 'Only a posted accrual run can be reversed.',
        'not_latest' => 'Only the latest accrual run of the agreement can be reversed.',
        'already_settled' => 'The accruals of this run are already part of a settlement proposal.',
        'journal_description' => 'Supplier rebate accrual :code up to :to',
    ],
    'promotion' => [
        'percentage_max' => 'A percentage discount cannot exceed 100.',
        'period' => 'The end date cannot be before the start date.',
        'code_taken' => 'The code :code is already used by another promotion.',
    ],
];
