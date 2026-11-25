<?php

declare(strict_types=1);

use App\DTOs\Banking\CsvStatementMapping;
use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;
use App\Services\Banking\Parsers\CsvStatementParser;

const CSV_IBAN = 'IT60X0542811101000000123456';

/**
 * @param  array<string, string|int>  $columns
 */
function csvMapping(array $columns, array $overrides = []): CsvStatementMapping
{
    return CsvStatementMapping::fromArray(array_merge(['columns' => $columns, 'account_iban' => CSV_IBAN], $overrides));
}

test('it reads an italian bank export with a signed amount column', function (): void {
    $csv = "Data;Importo;Causale;Controparte;IBAN\n01/03/2026;1.234,50;Pagamento FT2026/001;Beta SRL;it60 x054 2811 1010 0000 0123 456\n02/03/2026;-95,00;Commissioni;;\n";
    $parsed = (new CsvStatementParser)->parseWith($csv, csvMapping(['booking_date' => 'Data', 'amount' => 'Importo', 'description' => 'Causale', 'counterpart_name' => 'Controparte', 'counterpart_iban' => 'IBAN']));

    expect($parsed->sourceFormat)->toBe(ParsedStatement::FORMAT_CSV)
        ->and($parsed->accountIban)->toBe(CSV_IBAN)
        ->and($parsed->statementDate->toDateString())->toBe('2026-03-02')
        ->and($parsed->lines)->toHaveCount(2)
        ->and($parsed->lines[0]->direction)->toBe('credit')
        ->and($parsed->lines[0]->amount)->toBe(1234.5)
        ->and($parsed->lines[0]->counterpartName)->toBe('Beta SRL')
        ->and($parsed->lines[0]->counterpartIban)->toBe('IT60X0542811101000000123456')
        ->and($parsed->lines[1]->direction)->toBe('debit')
        ->and($parsed->lines[1]->amount)->toBe(95.0);
});

test('it reads separate debit and credit columns, fees and structured references', function (): void {
    $csv = "date,debit,credit,fee,ref,reference\n2026-03-01,,500.00,1.50,E2E-1,rf18 5390 0754 7034\n2026-03-02,80.25,,,E2E-2,\n";
    $parsed = (new CsvStatementParser)->parseWith($csv, csvMapping(
        ['booking_date' => 'date', 'debit' => 'debit', 'credit' => 'credit', 'fee' => 'fee', 'reference' => 'ref', 'qr_reference' => 'reference'],
        ['delimiter' => ',', 'date_format' => 'Y-m-d', 'decimal_separator' => '.', 'thousands_separator' => null],
    ));

    expect($parsed->lines[0]->direction)->toBe('credit')
        ->and($parsed->lines[0]->feeAmount)->toBe(1.5)
        ->and($parsed->lines[0]->qrReference)->toBe('RF18539007547034')
        ->and($parsed->lines[1]->direction)->toBe('debit')
        ->and($parsed->lines[1]->amount)->toBe(80.25);
});

test('it maps columns by position when the file has no header', function (): void {
    $parsed = (new CsvStatementParser)->parseWith("01/03/2026;10,00;Test\n", csvMapping(['booking_date' => 1, 'amount' => 2, 'description' => 3], ['has_header' => false]));

    expect($parsed->lines)->toHaveCount(1)->and($parsed->lines[0]->description)->toBe('Test');
});

test('a mapped column that the file does not have refuses the file', function (): void {
    expect(fn () => (new CsvStatementParser)->parseWith("Data;Importo\n01/03/2026;1,00\n", csvMapping(['booking_date' => 'Data', 'amount' => 'Importo', 'description' => 'Causale'])))
        ->toThrow(BankStatementException::class);
});

test('an unreadable date or amount names the row', function (string $row): void {
    expect(fn () => (new CsvStatementParser)->parseWith("Data;Importo\n{$row}\n", csvMapping(['booking_date' => 'Data', 'amount' => 'Importo'])))
        ->toThrow(BankStatementException::class);
})->with(['31/02/2026;1,00', '01/03/2026;abc', 'ieri;1,00']);

test('an empty file, a file without movements and a mapping without account are refused', function (): void {
    $mapping = csvMapping(['booking_date' => 'Data', 'amount' => 'Importo']);

    expect(fn () => (new CsvStatementParser)->parseWith('', $mapping))->toThrow(BankStatementException::class)
        ->and(fn () => (new CsvStatementParser)->parseWith("Data;Importo\n", $mapping))->toThrow(BankStatementException::class)
        ->and(fn () => (new CsvStatementParser)->parseWith("Data;Importo\n01/03/2026;1,00\n", csvMapping(['booking_date' => 'Data', 'amount' => 'Importo'], ['account_iban' => ''])))->toThrow(BankStatementException::class);
});

test('a byte order mark and CRLF line ends are tolerated', function (): void {
    $parsed = (new CsvStatementParser)->parseWith("\xEF\xBB\xBFData;Importo\r\n01/03/2026;5,00\r\n", csvMapping(['booking_date' => 'Data', 'amount' => 'Importo']));

    expect($parsed->lines)->toHaveCount(1)->and($parsed->lines[0]->amount)->toBe(5.0);
});
