<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Data of the Italian periodic VAT communication (Comunicazione liquidazioni periodiche IVA, model IVP18).
 * Port of FatturaFacile.Application.Taxation.LipeData; amounts are decimal strings.
 *
 * subcontracting, exceptionalEvents and advanceMethod map to the optional VP1 fields Subfornitura (1), EventiEccezionali
 * (codes 1, 9) and Metodo (advance calculation method 1 to 4) of the IVP18 specification.
 */
final readonly class LipeData
{
    public function __construct(
        public string $taxCode,
        public string $vatNumber,
        public int $year,
        public ?int $month = null,
        public ?int $quarter = null,
        public ?string $declarantTaxCode = null,
        public string $declarantRoleCode = '1',
        public string $activeOperations = '0',
        public string $passiveOperations = '0',
        public string $outputVat = '0',
        public string $inputVat = '0',
        public string $previousDebit = '0',
        public string $previousCredit = '0',
        public string $interest = '0',
        public string $payable = '0',
        public string $previousYearCredit = '0',
        public string $euAutoPayments = '0',
        public string $taxCredits = '0',
        public string $advance = '0',
        public bool $subcontracting = false,
        public ?int $exceptionalEvents = null,
        public ?int $advanceMethod = null,
    ) {}
}
