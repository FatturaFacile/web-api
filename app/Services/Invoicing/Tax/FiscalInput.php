<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

/**
 * Everything the Italian fiscal calculator needs; settings are already resolved (request + tenant defaults).
 */
final readonly class FiscalInput
{
    /**
     * @param  list<FiscalLine>  $lines
     */
    public function __construct(
        public array $lines,
        public string $regimeFiscale = 'RF01',
        public bool $splitPayment = false,
        public bool $reverseCharge = false,
        public ?FundSettings $fund = null,
        public ?WithholdingSettings $withholding = null,
        public ?EnasarcoSettings $enasarco = null,
        public bool $stampDutyChargedToCustomer = true,
        public string $vatPayability = 'I',
    ) {}
}
