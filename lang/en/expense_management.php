<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Expense data loaded.',
        'saved' => 'Saved.',
        'deleted' => 'Deleted.',
        'submitted' => 'The expense report was submitted for approval.',
        'approved' => 'The expense report was approved.',
        'rejected' => 'The expense report was rejected.',
        'exported' => 'The payout was recorded.',
        'scanned' => 'Receipt read.',
    ],
    'errors' => [
        'report_not_editable' => 'The expense report can no longer be edited.',
        'report_empty' => 'The expense report has no lines or allowances.',
        'blocking_violations' => ':count line(s) have blocking policy violations.',
        'missing_receipt' => 'A receipt is required.',
        'invalid_transition' => 'The expense report cannot move from :from to :to.',
        'self_approval_forbidden' => 'You cannot approve your own expense report.',
        'rejection_reason_required' => 'A reason is required to reject an expense report.',
        'no_reimbursable_amount' => 'There is nothing to reimburse.',
        'employee_iban_missing' => 'The employee has no IBAN on the payroll profile.',
        'debtor_account_missing' => 'No active company bank account in the report currency.',
        'card_transaction_mismatch' => 'The card transaction does not match the expense line (currency or data).',
        'card_transaction_already_matched' => 'The card transaction or the expense line is already matched.',
        'amount_exceeds_transaction' => 'The matched amounts exceed the card transaction (:amount).',
        'vehicle_rate_not_found' => 'No cost-per-km rate found for vehicle :vehicle on that date.',
        'currency_rate_missing' => 'No exchange rate from :from to :to; enter the rate.',
        'receipt_not_found' => 'Receipt not found.',
        'period_invalid' => 'The end of the period is before its start.',
        'category_not_found' => 'Expense category not found.',
        'approved_amount_exceeds' => 'The approved amount exceeds the reimbursable amount (:max).',
    ],
];
