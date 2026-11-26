<?php

declare(strict_types=1);

/**
 * Messages of the multi-currency engine (Mod 003): the answers of the API and the errors of its rules. Keep it, de, fr and en in sync.
 */
return [
    'messages' => [
        'listed' => 'Records retrieved successfully.',
        'resolved' => 'Exchange rate resolved.',
        'rate_stored' => 'Exchange rate stored.',
        'sync_queued' => 'Exchange rate synchronisation queued.',
        'exposure' => 'Currency exposure computed.',
        'settings' => 'Currency settings retrieved.',
        'settings_saved' => 'Currency settings saved.',
        'revaluation_simulated' => 'FX revaluation simulated.',
        'revaluation' => 'FX revaluation retrieved.',
        'revaluation_posted' => 'FX revaluation posted.',
        'revaluation_reversed' => 'FX revaluation reversed.',
    ],
    'errors' => [
        'exchange_rate_missing' => 'No :base/:quote exchange rate is available on :date or in the :days days before it: enter a manual rate or synchronise the rates.',
        'sync_already_running' => 'A :provider synchronisation is already queued or running.',
        'same_currency' => 'Base and quote currency must be different.',
        'contract_reason_required' => 'A contract rate needs a reason.',
        'currency_in_use' => 'These currencies still have open items or balances and cannot be deactivated: :currencies.',
        'vat_method_not_allowed' => 'This VAT rate method is not allowed for companies in :country.',
        'vat_method_locked' => 'The VAT rate method must be kept for at least one tax period before it can be changed.',
        'account_not_found' => 'The account does not exist in the chart of accounts of the company.',
        'nothing_to_revalue' => 'There are no open items or foreign-currency accounts to revalue at :date.',
        'period_end_invalid' => 'The period end is not a valid end of a :type period.',
        'revaluation_not_found' => 'The FX revaluation does not exist.',
        'revaluation_already_posted' => 'The FX revaluation is already posted.',
        'revaluation_already_reversed' => 'The FX revaluation is already reversed.',
        'revaluation_not_posted' => 'Only a posted FX revaluation can be reversed.',
        'revaluation_period_conflict' => 'The positions at :date were already revalued and posted: reverse that revaluation first.',
        'line_account_missing' => 'The revaluation line :reference has no ledger account.',
        'reversal_date_invalid' => 'The reversal date must be after the period end (:date).',
        'idempotency_key_required' => 'The Idempotency-Key header (8 to 100 characters) is required to post a revaluation.',
    ],
];
