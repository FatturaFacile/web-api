<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Ledger accounts read and cleared by the VAT settlement of a tenant. The settlement and interest accounts are resolved
 * only when an entry is written, so a calculation never creates accounts.
 */
final readonly class VatAccountSet
{
    /**
     * @param  list<string>  $outputAccountIds
     * @param  list<string>  $inputAccountIds
     */
    public function __construct(
        public array $outputAccountIds,
        public array $inputAccountIds,
    ) {}
}
