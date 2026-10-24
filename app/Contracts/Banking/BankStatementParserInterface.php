<?php

declare(strict_types=1);

namespace App\Contracts\Banking;

use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;

/**
 * Reads one bank statement format into the normalised `ParsedStatement`.
 */
interface BankStatementParserInterface
{
    /**
     * @throws BankStatementException when the content is not a valid statement of this format
     */
    public function parse(string $content): ParsedStatement;
}
