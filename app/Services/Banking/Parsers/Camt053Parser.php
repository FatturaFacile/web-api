<?php

declare(strict_types=1);

namespace App\Services\Banking\Parsers;

use App\DTOs\Banking\ParsedStatement;

/**
 * ISO 20022 customer statement (`camt.053`): account, opening and closing balances and the booked entries.
 */
class Camt053Parser extends AbstractCamtParser
{
    protected function format(): string
    {
        return ParsedStatement::FORMAT_CAMT053;
    }

    protected function containerElement(): string
    {
        return 'Stmt';
    }
}
