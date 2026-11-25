<?php

declare(strict_types=1);

return [
    'account' => [
        'type_locked' => 'Account :code already carries postings: its type and normal balance cannot change. Create a new account and reclassify with a journal.',
        'has_postings' => 'Account :code carries postings: deactivate it (is_active = false) instead of deleting it.',
        'has_sub_accounts' => 'Account :code has sub-accounts: move or delete them first.',
        'is_default_posting' => 'Account :code is a default posting account: choose another one in the ledger settings first.',
        'parent_cycle' => 'An account cannot be placed under itself or one of its sub-accounts.',
        'is_not_postable' => 'Account :code is a structural grouping node and does not accept direct postings.',
        'currency_not_allowed' => 'Currency :currency is not allowed for account :code.',
        'code_locked' => 'Account :code already carries postings: its account code cannot be changed.',
    ],
    'mapping' => [
        'self_mapping' => 'An account cannot be mapped to itself.',
        'duplicate' => 'A mapping between this source account and target already exists.',
        'allocation_exceeded' => 'Total allocation percentage for standard :standard exceeds 100%.',
    ],
    'accrual' => [
        'cutoff_outside_coverage' => 'The cut-off date must fall within the coverage, before its last day.',
        'nothing_to_post' => 'The share at the cut-off date is zero: nothing to post.',
        'income_account_type' => 'For :type the income statement account must be of type :types.',
        'balance_account_type' => 'For :type the balance sheet account must be of type :types.',
        'competence_dates_pair' => 'Both competence start and end dates must be provided together.',
        'competence_end_before_start' => 'The competence end date must be on or after the start date.',
        'missing_balance_sheet_account' => 'Configure a default balance sheet account for [:type] before generating closing entries.',
        'missing_income_account' => 'No income statement account resolved for [:type].',
        'already_posted' => 'A closing entry has already been posted for this item and cut-off date.',
    ],
    'settings' => [
        'posting_account_type' => 'The account for [:role] must be an account of type :types of this tenant.',
        'fx_provision_account_type' => 'The FX unrealised gain provision account must be of type :types of this tenant.',
    ],
    'journal' => [
        'submit_only_draft' => 'Only a draft can be submitted for approval; this journal is [:status].',
        'approve_only_pending' => 'Only a journal waiting for approval can be approved; this journal is [:status].',
        'four_eyes' => 'Four-eyes principle: the journal must be approved by a person other than the one who wrote or submitted it.',
        'reject_only_pending' => 'Only a journal waiting for approval can be rejected; this journal is [:status].',
        'needs_second_approval' => 'This journal needs the approval of a second person: submit it and have it approved.',
        'account_not_in_chart' => 'The account does not belong to the chart of accounts of this tenant.',
        'account_inactive' => 'Account :code is not active.',
        'account_automatic_only' => 'Account :code accepts only automatic postings.',
        'cost_center_required' => 'Account :code requires a cost centre.',
    ],
    'template' => [
        'unbalanced' => 'The template lines do not balance for these amounts.',
        'amount_required' => 'A positive amount is required.',
        'rate_required' => 'This template taxes the amount: a tax rate is required.',
        'account_unknown' => 'No account of this tenant was found for the parameter ":key".',
        'system_readonly' => 'A system template cannot be changed or deleted.',
    ],
];
