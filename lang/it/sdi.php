<?php

declare(strict_types=1);

return [
    'messages' => [
        'bulk_sent' => 'Invio massivo a SDI elaborato',
        'self_invoice_created' => 'Autofattura emessa',
    ],
    'bulk' => [
        'title' => 'Invio massivo a SDI',
        'not_found' => 'Fattura non trovata.',
        'forbidden' => 'Non puoi inviare questa fattura.',
    ],
    'fiscal' => [
        'td04_needs_credit_note' => 'TD04 si usa solo su una nota di credito.',
        'linked_document_required' => ':type richiede il riferimento al documento collegato.',
        'supplier_cannot_be_from' => 'Su :type il fornitore non può essere di :country.',
        'supplier_must_be_from' => 'Su :type il fornitore deve essere di :country.',
        'nature_with_vat' => "Una natura IVA non può stare accanto a un'aliquota: azzera l'aliquota delle righe o togli la natura.",
        'n35_needs_declaration' => "La natura N3.5 richiede una dichiarazione d'intento.",
        'split_and_reverse_charge' => 'Un documento non può essere insieme in scissione dei pagamenti e in reverse charge.',
        'forfettario_no_vat' => "Nel regime forfettario (RF19) non si addebita l'IVA: usa aliquota 0.",
        'forfettario_no_withholding' => 'Nel regime forfettario (RF19) i compensi non sono soggetti a ritenuta.',
        'forfettario_no_special_vat' => 'Scissione dei pagamenti e reverse charge non si applicano al regime forfettario (RF19).',
        'forfettario_no_declaration' => "Una dichiarazione d'intento non si può usare nel regime forfettario (RF19).",
        'forfettario_nature' => 'Nel regime forfettario (RF19) la natura IVA è N2.2.',
        'credit_note_adjustments' => 'Cassa, ritenuta ed Enasarco non sono gestiti su una nota di credito.',
        'fund_incomplete' => 'La cassa previdenziale richiede un tipo valido (TC01-TC22) e una percentuale.',
        'withholding_incomplete' => 'La ritenuta richiede una percentuale.',
        'enasarco_parameters_missing' => 'Mancano i parametri Enasarco per il :year e questo tipo di agente: inseriscili con la loro fonte.',
        'declaration_not_found' => "La dichiarazione d'intento non appartiene a questo cliente.",
        'declaration_not_valid_on_date' => "La dichiarazione d'intento :protocol non copre la data del documento.",
        'declaration_over_plafond' => "Il documento supera il plafond della dichiarazione d'intento :protocol: residuo :residual.",
        'declaration_in_use' => "Una dichiarazione d'intento già usata da documenti non può essere eliminata.",
        'not_a_self_invoice_source' => ':type non si può creare da una fattura ricevuta.',
        'self_invoice_exists' => 'Esiste già un :type per questa fattura ricevuta.',
    ],
    'preflight' => [
        'tenant_vat' => "Manca la partita IVA dell'azienda.",
        'tenant_address' => "Manca il campo :field dell'indirizzo dell'azienda.",
        'customer_identity' => 'Il cliente deve avere partita IVA o codice fiscale.',
        'supplier_identity' => 'Su un\'autofattura il fornitore deve avere la partita IVA.',
        'customer_address' => 'Il cliente deve avere un indirizzo di fatturazione completo.',
        'supplier_address' => 'Su un\'autofattura il fornitore deve avere un indirizzo di fatturazione completo.',
        'zero_rate_nature' => 'Le righe ad aliquota zero richiedono una natura IVA.',
    ],
];
