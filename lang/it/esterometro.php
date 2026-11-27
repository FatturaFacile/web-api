<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_found' => 'Autofattura :id non trovata.',
        'invalid_state_transition' => 'Transizione di stato non consentita da :from a :to.',
        'xml_validation_failed' => 'Validazione XML FatturaPA fallita: :details',
        'missing_supplier_data' => 'Dati fiscali del fornitore mancanti: :field.',
        'missing_tenant_fiscal_data' => 'Dati fiscali aziendali mancanti per l\'emissione XML: :field.',
        'duplicate_self_invoice' => 'Esiste già un\'autofattura attiva per la fattura passiva :id.',
        'sdi_dispatch_failed' => 'Invio a SDI non riuscito: :reason',
        'fx_rate_unavailable' => 'Tasso di cambio BCE non disponibile per :currency alla data :date.',
        'vat_reconciliation_failed' => 'Riconciliazione Registri IVA fallita: :reason',
    ],
    'warnings' => [
        'customs_import_bill' => 'Presente bolletta doganale di importazione: di norma non è richiesta autofattura TD19.',
        'manual_type_override' => 'Tipo documento modificato manualmente da :suggested a :selected.',
        'san_marino_vat_charged' => 'Fattura da San Marino con IVA italiana già addebitata in origine (TD28).',
        'foreign_type_with_it_supplier' => 'Il codice :type richiede normalmente un fornitore estero.',
        'td16_with_foreign_supplier' => 'TD16 è riservato al reverse charge interno con fornitore italiano.',
    ],
];
