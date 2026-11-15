<?php

declare(strict_types=1);

// The messages come from the framework (it merges with this file); this file adds the names of the fields.
return [
    'attributes' => require __DIR__.'/validation_attributes.php',

    'tenant_country_locked' => 'The country cannot be changed once invoices or journal entries exist.',
    'lettering_lines_invalid' => 'Select at least two existing ledger lines.',
    'lettering_lines_not_posted' => 'Only lines of posted entries can be lettered.',
    'lettering_lines_mixed' => 'The lines must belong to the same account and the same partner.',
    'lettering_lines_already_lettered' => 'One or more lines are already lettered.',
    'lettering_not_balanced' => 'The debits and credits of the selected lines do not cancel out.',
    'datev_non_numeric_accounts' => 'The DATEV format needs numeric accounts: non-numeric accounts found (:accounts).',
    'us_zip_unknown' => 'The ZIP code is not in the preloaded states: provide the state.',
    'us_jurisdiction_state_mismatch' => 'The jurisdiction does not belong to the state of the certificate.',
    'us_1099_supplier_payment_invalid' => 'Only USD supplier payments of type payment can be linked.',
];
