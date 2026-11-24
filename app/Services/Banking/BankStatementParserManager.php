<?php

declare(strict_types=1);

namespace App\Services\Banking;

use App\Contracts\Banking\BankStatementParserInterface;
use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;
use App\Services\Banking\Parsers\Camt053Parser;
use App\Services\Banking\Parsers\Camt054Parser;
use App\Services\Banking\Parsers\CbiStatementParser;

/**
 * Recognises the format of a bank statement and hands it to the matching parser: ISO 20022 documents by their root
 * element or namespace (`camt.053`, `camt.054`) and CBI files by their `RH`/`PC` header record.
 */
class BankStatementParserManager
{
    /**
     * @var array<string, BankStatementParserInterface>
     */
    private array $parsers;

    public function __construct(Camt053Parser $camt053, Camt054Parser $camt054, CbiStatementParser $cbi)
    {
        $this->parsers = [
            ParsedStatement::FORMAT_CAMT053 => $camt053,
            ParsedStatement::FORMAT_CAMT054 => $camt054,
            ParsedStatement::FORMAT_CBI => $cbi,
        ];
    }

    /**
     * Support another format (or replace a parser).
     */
    public function extend(string $format, BankStatementParserInterface $parser): void
    {
        $this->parsers[$format] = $parser;
    }

    /**
     * @throws BankStatementException when the format is not recognised
     */
    public function parse(string $content): ParsedStatement
    {
        return $this->parsers[$this->detect($content)]->parse($content);
    }

    /**
     * The format key of a payload: `camt053`, `camt054` or `cbi`.
     *
     * @throws BankStatementException
     */
    public function detect(string $content): string
    {
        $head = ltrim(substr($content, 0, 4096), "\xEF\xBB\xBF \t\r\n");

        if ($head === '') {
            throw BankStatementException::because(BankStatementException::EMPTY_FILE);
        }

        if (str_starts_with($head, '<')) {
            return match (true) {
                preg_match('/camt\.053|BkToCstmrStmt/i', $head) === 1 => ParsedStatement::FORMAT_CAMT053,
                preg_match('/camt\.054|BkToCstmrDbtCdtNtfctn/i', $head) === 1 => ParsedStatement::FORMAT_CAMT054,
                default => throw BankStatementException::because(BankStatementException::UNSUPPORTED_FORMAT),
            };
        }

        if (preg_match('/^(RH|PC)/', $head) === 1) {
            return ParsedStatement::FORMAT_CBI;
        }

        throw BankStatementException::because(BankStatementException::UNSUPPORTED_FORMAT);
    }
}
