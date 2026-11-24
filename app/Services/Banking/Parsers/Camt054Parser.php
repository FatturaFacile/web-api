<?php

declare(strict_types=1);

namespace App\Services\Banking\Parsers;

use App\DTOs\Banking\ParsedStatement;

/**
 * ISO 20022 debit/credit notification (`camt.054`), used by Swiss QR-bill payments: it carries the account and the
 * entries, with the creditor reference (27 digit QR reference or ISO 11649) of each payment, but no balances.
 */
class Camt054Parser extends AbstractCamtParser
{
    protected function format(): string
    {
        return ParsedStatement::FORMAT_CAMT054;
    }

    protected function containerElement(): string
    {
        return 'Ntfctn';
    }
}
