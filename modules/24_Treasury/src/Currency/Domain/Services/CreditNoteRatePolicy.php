<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Services;

use Modules\Treasury\Currency\Domain\Entities\ResolvedRate;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * The rate of a credit note: the frozen rate of the invoice it corrects when it is linked to one, otherwise the rate of the
 * transaction date.
 */
final class CreditNoteRatePolicy
{
    public function rate(?string $linkedInvoiceRate, ResolvedRate $transactionDateRate): ExchangeRateValue
    {
        return $linkedInvoiceRate === null || $linkedInvoiceRate === ''
            ? $transactionDateRate->rate
            : ExchangeRateValue::of($linkedInvoiceRate);
    }
}
