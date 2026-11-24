<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\SDI\Enums\TipoDocumento;

/**
 * What the customer really pays on a sales document. `total_gross` is the document total SDI wants, while withholding and
 * Enasarco are paid to the tax authority and to the fund, and split-payment VAT is paid by the public administration.
 */
trait HasFiscalAmounts
{
    public function amountDue(): string
    {
        if (TipoDocumento::ofInvoice($this)->isSelfInvoice()) {
            return '0.00';
        }

        $due = (string) ($this->total_gross ?? '0');

        if ($this->withholding_tax_enabled) {
            $due = Decimal::sub($due, (string) ($this->withholding_tax_amount ?? '0'));
        }

        if ($this->enasarco_enabled) {
            $due = Decimal::sub($due, (string) ($this->enasarco_amount ?? '0'));
        }

        if ($this->split_payment) {
            $due = Decimal::sub($due, (string) ($this->total_tax ?? '0'));
        }

        return Decimal::round2($due);
    }

    /**
     * What is left to collect.
     */
    public function outstandingAmount(): string
    {
        return Decimal::round2(Decimal::sub($this->amountDue(), (string) ($this->total_paid ?? '0')));
    }
}
