<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_found' => 'Eigenbeleg :id nicht gefunden.',
        'invalid_state_transition' => 'Ungültiger Statusübergang von :from zu :to.',
        'xml_validation_failed' => 'FatturaPA-XML-Validierung fehlgeschlagen: :details',
        'missing_supplier_data' => 'Fehlende Steuerdaten des Lieferanten: :field.',
        'missing_tenant_fiscal_data' => 'Fehlende Unternehmenssteuerdaten für die XML-Erstellung: :field.',
        'duplicate_self_invoice' => 'Für die Eingangsrechnung :id existiert bereits ein aktiver Eigenbeleg.',
        'sdi_dispatch_failed' => 'SDI-Übermittlung fehlgeschlagen: :reason',
        'fx_rate_unavailable' => 'EZB-Wechselkurs für :currency am :date nicht verfügbar.',
        'vat_reconciliation_failed' => 'Abstimmung der MwSt-Register fehlgeschlagen: :reason',
    ],
    'warnings' => [
        'customs_import_bill' => 'Zolleinfuhrbeleg vorhanden: TD19-Eigenbeleg normalerweise nicht erforderlich.',
        'manual_type_override' => 'Belegart manuell von :suggested auf :selected geändert.',
        'san_marino_vat_charged' => 'Rechnung aus San Marino mit bereits berechneter italienischer MwSt (TD28).',
        'foreign_type_with_it_supplier' => 'Die Belegart :type erfordert normalerweise einen ausländischen Lieferanten.',
        'td16_with_foreign_supplier' => 'TD16 ist für das inländische Reverse-Charge-Verfahren mit italienischem Lieferanten vorgesehen.',
    ],
];
