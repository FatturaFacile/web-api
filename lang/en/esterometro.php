<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_found' => 'Self-invoice :id not found.',
        'invalid_state_transition' => 'Invalid state transition from :from to :to.',
        'xml_validation_failed' => 'FatturaPA XML validation failed: :details',
        'missing_supplier_data' => 'Missing supplier fiscal data: :field.',
        'missing_tenant_fiscal_data' => 'Missing company fiscal data for XML generation: :field.',
        'duplicate_self_invoice' => 'An active self-invoice already exists for passive invoice :id.',
        'sdi_dispatch_failed' => 'SDI transmission failed: :reason',
        'fx_rate_unavailable' => 'ECB exchange rate unavailable for :currency on :date.',
        'vat_reconciliation_failed' => 'VAT register reconciliation failed: :reason',
    ],
    'warnings' => [
        'customs_import_bill' => 'Customs import bill is present: TD19 self-invoice is normally not required.',
        'manual_type_override' => 'Document type manually overridden from :suggested to :selected.',
        'san_marino_vat_charged' => 'San Marino invoice with Italian VAT already charged on original document (TD28).',
        'foreign_type_with_it_supplier' => 'Document type :type normally requires a foreign supplier.',
        'td16_with_foreign_supplier' => 'TD16 is reserved for domestic reverse charge with an Italian supplier.',
    ],
];
