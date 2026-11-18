<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Italian fiscal data (issue #286)
|--------------------------------------------------------------------------
|
| Rates, thresholds, codes and legal wording used by the Italian fiscal calculator, the FatturaPA builder and the
| fiscal reports. They are data, not code: every value marked "verify" must be checked with a tax advisor before
| release and kept up to date every year.
|
*/

return [

    /*
     * Natura codes accepted on invoice lines (FatturaPA 1.2.2 / 1.2.3 code list).
     */
    'natura_codes' => [
        'N1', 'N2.1', 'N2.2', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4', 'N5',
        'N6.1', 'N6.2', 'N6.3', 'N6.4', 'N6.5', 'N6.6', 'N6.7', 'N6.8', 'N6.9', 'N7',
    ],

    /*
     * Legal reference printed in DatiRiepilogo/RiferimentoNormativo for each natura (max 100 characters).
     */
    'riferimento_normativo' => [
        'N1' => 'Escluse ex art. 15 DPR 633/72',
        'N2.1' => 'Non soggette ad IVA ai sensi degli artt. da 7 a 7-septies DPR 633/72',
        'N2.2' => 'Non soggette - altri casi',
        'N3.1' => 'Non imponibili - esportazioni',
        'N3.2' => 'Non imponibili - cessioni intracomunitarie',
        'N3.3' => 'Non imponibili - cessioni verso San Marino',
        'N3.4' => 'Non imponibili - operazioni assimilate alle cessioni all\'esportazione',
        'N3.5' => 'Non imponibili - a seguito di dichiarazioni d\'intento',
        'N3.6' => 'Non imponibili - altre operazioni che non concorrono alla formazione del plafond',
        'N4' => 'Esenti art. 10 DPR 633/72',
        'N5' => 'Regime del margine / IVA non esposta in fattura',
        'N6.9' => 'Inversione contabile art. 17 DPR 633/72',
        'N7' => 'IVA assolta in altro stato UE',
    ],

    /*
     * Virtual stamp duty (imposta di bollo) on e-invoices without VAT. Verify amounts and codes every year.
     */
    'stamp_duty' => [
        'threshold' => '77.47',
        'amount' => '2.00',
        // Natura codes whose lines count towards the threshold.
        'natura_codes' => ['N1', 'N2.1', 'N2.2', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4'],
        // Default payer when the tenant has no preference: "customer" (re-charged, included in the total) or "issuer".
        'default_charge_mode' => 'customer',
        // Quarterly F24 payment of the e-invoice stamp duty (verify before release).
        'f24' => [
            1 => ['tributo' => '2521', 'due_month' => 5, 'due_day' => 31, 'next_year' => false],
            2 => ['tributo' => '2522', 'due_month' => 9, 'due_day' => 30, 'next_year' => false],
            3 => ['tributo' => '2523', 'due_month' => 11, 'due_day' => 30, 'next_year' => false],
            4 => ['tributo' => '2524', 'due_month' => 2, 'due_day' => 28, 'next_year' => true],
        ],
        // Q1/Q2 amounts below this threshold may be paid together with the following quarter (verify).
        'deferral_threshold' => '5000.00',
    ],

    /*
     * Withholding tax (ritenuta d'acconto).
     */
    'withholding' => [
        'types' => [
            'RT01' => 'Ritenuta persone fisiche',
            'RT02' => 'Ritenuta persone giuridiche',
            'RT03' => 'Contributo INPS',
            'RT04' => 'Contributo ENASARCO',
            'RT05' => 'Contributo ENPAM',
            'RT06' => 'Altro contributo previdenziale',
        ],
        // Causale pagamento (CU / modello 770 codes).
        'reasons' => [
            'A', 'B', 'C', 'D', 'E', 'G', 'H', 'I', 'J', 'K', 'L', 'L1', 'M', 'M1', 'M2', 'N', 'O', 'O1',
            'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'V1', 'V2', 'W', 'X', 'Y', 'ZO',
        ],
        'default_type' => 'RT01',
        'default_reason' => 'A',
    ],

    /*
     * Social security funds (cassa previdenziale / rivalsa).
     */
    'fund_types' => [
        'TC01' => 'Cassa nazionale previdenza e assistenza avvocati e procuratori legali',
        'TC02' => 'Cassa previdenza dottori commercialisti',
        'TC03' => 'Cassa previdenza e assistenza geometri',
        'TC04' => 'Cassa nazionale previdenza e assistenza ingegneri e architetti liberi professionisti',
        'TC05' => 'Cassa nazionale del notariato',
        'TC06' => 'Cassa nazionale previdenza e assistenza ragionieri e periti commerciali',
        'TC07' => 'Ente nazionale assistenza agenti e rappresentanti di commercio (ENASARCO)',
        'TC08' => 'Ente nazionale previdenza e assistenza consulenti del lavoro (ENPACL)',
        'TC09' => 'Ente nazionale previdenza e assistenza medici (ENPAM)',
        'TC10' => 'Ente nazionale previdenza e assistenza farmacisti (ENPAF)',
        'TC11' => 'Ente nazionale previdenza e assistenza veterinari (ENPAV)',
        'TC12' => 'Ente nazionale previdenza e assistenza impiegati dell\'agricoltura (ENPAIA)',
        'TC13' => 'Fondo previdenza impiegati imprese di spedizione e agenzie marittime',
        'TC14' => 'Istituto nazionale previdenza giornalisti italiani (INPGI)',
        'TC15' => 'Opera nazionale assistenza orfani sanitari italiani (ONAOSI)',
        'TC16' => 'Cassa autonoma assistenza integrativa giornalisti italiani (CASAGIT)',
        'TC17' => 'Ente previdenza periti industriali e periti industriali laureati (EPPI)',
        'TC18' => 'Ente previdenza e assistenza pluricategoriale (EPAP)',
        'TC19' => 'Ente nazionale previdenza e assistenza biologi (ENPAB)',
        'TC20' => 'Ente nazionale previdenza e assistenza professione infermieristica (ENPAPI)',
        'TC21' => 'Ente nazionale previdenza e assistenza psicologi (ENPAP)',
        'TC22' => 'INPS',
    ],

    /*
     * Regime forfettario (RF19): no VAT, no withholding, legal wording on every invoice (verify wording).
     */
    'forfettario' => [
        'regime' => 'RF19',
        'natura' => 'N2.2',
        'riferimento_normativo' => 'Art. 1, commi 54-89, L. 190/2014 - Regime forfettario',
        'legal_notices' => [
            'Operazione effettuata ai sensi dell\'art. 1, commi da 54 a 89, della Legge n. 190/2014 - Regime forfettario.',
            'Compenso non soggetto a ritenuta d\'acconto ai sensi dell\'art. 1, comma 67, della Legge n. 190/2014.',
        ],
    ],

    'reverse_charge' => [
        'natura' => 'N6.9',
    ],

    /*
     * Fiscal regimes accepted in the tenant fiscal preferences.
     */
    'regimes' => [
        'RF01', 'RF02', 'RF04', 'RF05', 'RF06', 'RF07', 'RF08', 'RF09', 'RF10', 'RF11', 'RF12', 'RF13',
        'RF14', 'RF15', 'RF16', 'RF17', 'RF18', 'RF19', 'RF20',
    ],

    /*
     * ENASARCO contribution withheld on agents' commissions, rendered as DatiRitenuta RT04. The rate is the share
     * withheld on the invoice; the annual maximum is the commission ceiling per principal and calendar year; the annual
     * minimum is reported as a year-end shortfall. Tenants may override a year in `enasarco_annual_limits`.
     * Values are placeholders to verify with a tax advisor / ENASARCO before release.
     */
    'enasarco' => [
        'withholding_type' => 'RT04',
        'default_reason' => 'R',
        'years' => [
            2025 => ['rate' => '8.50', 'annual_minimum' => '480.00', 'annual_maximum_commission' => '27000.00'],
            2026 => ['rate' => '8.50', 'annual_minimum' => '490.00', 'annual_maximum_commission' => '27500.00'],
        ],
    ],

    /*
     * FatturaPA limits.
     */
    'causale_max_length' => 200,
];
