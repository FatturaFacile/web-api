<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Data of the Italian periodic VAT communication (Comunicazione liquidazioni periodiche IVA, model IVP18).
 * Port of FatturaFacile.Application.Taxation.LipeData; amounts are decimal strings.
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
    ) {}
}
