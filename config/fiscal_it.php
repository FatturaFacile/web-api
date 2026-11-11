<?php

declare(strict_types=1);

/**
 * Italian fiscal parameters that are not in the FatturaPA schema. Each value is a legal figure: change it only with its
 * source. Enasarco rates and ceilings change every year and live in the `enasarco_parameters` table instead.
 */
return [

    'stamp_duty' => [
        // D.P.R. 642/1972, Tariffa parte I art. 13 c. 1 and nota 2: EUR 2.00 on documents without VAT above EUR 77.47.
        // Exactly 77.47 is not subject.
        'threshold' => env('FISCAL_IT_STAMP_DUTY_THRESHOLD', '77.47'),
        'amount' => env('FISCAL_IT_STAMP_DUTY_AMOUNT', '2.00'),
    ],

    'legal_references' => [
        // L. 190/2014, art. 1 c. 54-89: regime forfettario.
        'N2.2' => 'Art. 1, commi 54-89, L. 190/2014',
        // D.P.R. 633/1972, art. 8 c. 1 lett. c): sales to an exporter under a declaration of intent.
        'N3.5' => 'Art. 8, c. 1, lett. c), D.P.R. 633/1972',
    ],

    'self_invoice' => [
        // RegimeFiscale of the cedente on an integration document, whose real regime the buyer does not know. To confirm
        // on the Agenzia delle Entrate guide to e-invoices (v1.9): see the report of the change.
        'supplier_regime' => env('FISCAL_IT_SELF_INVOICE_SUPPLIER_REGIME', 'RF18'),
    ],

    // CAP of an address outside Italy. CAPType admits five digits only, so a foreign code cannot be written as it is.
    'foreign_postal_code' => '00000',

    'declaration_of_intent' => [
        // Provvedimento Agenzia delle Entrate 293390 of 28 October 2021: TipoDato of AltriDatiGestionali.
        'data_type' => 'INTENTO',
    ],

    'withholding' => [
        // F24 tax code of the withholding on self-employed income (art. 25 D.P.R. 600/1973): 1040, verified on the Agenzia
        // delle Entrate pages "F24 Ritenute reddito lavoro autonomo" and "Codice tributo 1040" and on the F24/770 2026 table
        // (read 2026-11-11). A causale with its own code goes in `f24_codes_by_reason`.
        'f24_code' => env('FISCAL_IT_WITHHOLDING_F24_CODE', '1040'),
        'f24_codes_by_reason' => [],
        // Paid by the 16th of the month after the payment (Agenzia delle Entrate, F24 ritenute lavoro autonomo, read 2026-11-11).
        'due_day' => 16,
    ],

];
