<?php

declare(strict_types=1);

namespace App\Services\Banking\Parsers;

use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;
use App\Support\Security\SafeXmlLoader;
use InvalidArgumentException;

/**
 * ISO 20022 customer statement (`camt.053`): account, opening and closing balances and the booked entries.
 */
class Camt053Parser extends AbstractCamtParser
{
    public function parse(string $content): ParsedStatement
    {
        $trimmed = ltrim($content, "\xEF\xBB\xBF \t\r\n");

        if ($trimmed === '') {
            throw BankStatementException::because(BankStatementException::EMPTY_FILE);
        }

        try {
            SafeXmlLoader::assertSafeXml($trimmed);
        } catch (InvalidArgumentException) {
            throw BankStatementException::because(BankStatementException::UNSAFE_XML);
        }

        return parent::parse($content);
    }

    protected function format(): string
    {
        return ParsedStatement::FORMAT_CAMT053;
    }

    protected function containerElement(): string
    {
        return 'Stmt';
    }
}
