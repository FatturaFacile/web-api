<?php

declare(strict_types=1);

use App\Services\Security\CsvFormulaNeutralizer;
use Modules\Associations\Support\CsvFile;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\Payroll\Exports\CsvBuilder;

test('cells starting with a formula character are turned into text, numbers are kept', function (mixed $value, string $expected): void {
    expect(CsvFormulaNeutralizer::neutralize($value))->toBe($expected);
})->with([
    'equals' => ['=1+1', "'=1+1"],
    'plus' => ['+39 333 1234', "'+39 333 1234"],
    'minus text' => ['-2+3+cmd|\' /C calc\'!A0', "'-2+3+cmd|' /C calc'!A0"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'tab' => ["\t=1", "'\t=1"],
    'carriage return' => ["\r=1", "'\r=1"],
    'negative number' => ['-12.50', '-12.50'],
    'negative int' => [-3, '-3'],
    'plain text' => ['Hilti Store', 'Hilti Store'],
    'empty' => ['', ''],
    'null' => [null, ''],
]);

test('every csv writer of the application neutralises formulas', function (): void {
    $builder = new CsvBuilder;
    $builder->row('=HYPERLINK("http://evil.example")', -5, 'ok');

    expect((new ExcelCsvWriter)->write(['a'], [['=1+1'], [-5]]))->toContain("\"'=1+1\"")->toContain('"-5"')
        ->and($builder->encoded())->toContain("\"'=HYPERLINK(\"\"http://evil.example\"\")\";-5;ok")
        ->and(CsvFile::write(['a', 'b'], [['@cmd', '-1']]))->toContain("'@cmd;-1");
});
