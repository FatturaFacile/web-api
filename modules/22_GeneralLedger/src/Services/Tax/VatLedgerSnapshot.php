<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * The VAT documents of a period as they stand in the ledger.
 */
final readonly class VatLedgerSnapshot
{
    /**
     * @param  list<VatSaleDocument>  $sales
     * @param  list<VatPurchaseDocument>  $purchases
     * @param  array<string, string>  $outputByAccount  signed tax to clear (credit minus debit) by account id
     * @param  array<string, string>  $inputByAccount  signed tax to clear (debit minus credit) by account id
     * @param  list<VatRegisterLine>  $saleLines
     * @param  list<VatRegisterLine>  $purchaseLines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $sales,
        public array $purchases,
        public array $outputByAccount,
        public array $inputByAccount,
        public array $saleLines,
        public array $purchaseLines,
        public array $warnings,
    ) {}

    public function passiveOperations(): string
    {
        $total = '0';

        foreach ($this->purchases as $purchase) {
            $total = Decimal::add($total, $purchase->totalNet);
        }

        return Decimal::round2($total);
    }
}
