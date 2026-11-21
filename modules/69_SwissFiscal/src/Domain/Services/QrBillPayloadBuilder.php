<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\Services;

use Modules\SwissFiscal\Domain\ValueObjects\QrBill;

/** Serialises a {@see QrBill} into the Swiss QR Code payload (SPC, version 0200, UTF-8, one field per line). */
final class QrBillPayloadBuilder
{
    public function build(QrBill $bill): string
    {
        $lines = ['SPC', '0200', '1', $bill->iban];
        array_push($lines, ...$bill->creditor->payloadFields());
        // Ultimate creditor is reserved for future use and must be sent empty.
        array_push($lines, ...array_fill(0, 7, ''));
        $lines[] = $bill->amount?->toDecimalString() ?? '';
        $lines[] = $bill->currency->value;

        if ($bill->debtor !== null) {
            array_push($lines, ...$bill->debtor->payloadFields());
        } else {
            array_push($lines, ...array_fill(0, 7, ''));
        }

        $lines[] = $bill->referenceType->value;
        $lines[] = $bill->reference ?? '';
        $lines[] = $bill->unstructuredMessage;
        $lines[] = 'EPD';

        if ($bill->billInformation !== '') {
            $lines[] = $bill->billInformation;
        }

        return implode("\n", $lines);
    }
}
