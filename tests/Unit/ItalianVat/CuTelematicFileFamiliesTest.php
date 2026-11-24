<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\ItalianVat\Models\CuCertification;
use Modules\ItalianVat\Services\CuFieldValidator;
use Modules\ItalianVat\Services\CuRecordLayout;
use Modules\ItalianVat\Services\CuTelematicFileBuilder;

/**
 * @param  array<string, mixed>  $attributes
 */
function cuFamilyCertification(array $attributes = []): CuCertification
{
    return new CuCertification($attributes + [
        'year' => 2026,
        'recipient_name' => 'Mario Rossi',
        'recipient_tax_code' => 'RSSMRA80A01H501U',
        'recipient_kind' => 'natural',
        'reason_code' => 'A',
        'gross_amount' => '1000.00',
        'non_taxable_amount' => '0.00',
        'taxable_amount' => '1000.00',
        'withheld_amount' => '200.00',
        'contributions_amount' => '0.00',
        'payments_count' => 2,
        'status' => 'validated',
    ]);
}

function cuFamilyTenant(): Tenant
{
    return new Tenant([
        'legal_name' => 'Acme Srl',
        'fiscal_code' => '00743110157',
        'vat_number' => 'IT00743110157',
        'pec_email' => 'acme@pec.example',
        'settings' => ['street' => 'Via Roma 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'mi', 'phone' => '0212345678'],
    ]);
}

it('declares non-overlapping fields inside the data area of every record family', function (): void {
    foreach (CuRecordLayout::all() as $family => $fields) {
        $nextFree = 2;

        uasort($fields, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($fields as $name => [$position, $length]) {
            expect($position)->toBeGreaterThanOrEqual($nextFree, "$family.$name overlaps the previous field")
                ->and($position + $length - 1)->toBeLessThan(CuRecordLayout::RECORD_LENGTH - 2, "$family.$name reaches the end marker");
            $nextFree = $position + $length;
        }
    }

    expect(array_keys(CuRecordLayout::all()))->toBe(CuRecordLayout::FAMILIES);
});

it('writes the A, B, C, D, E, H and Z record families in order with the counts and the control total', function (): void {
    $builder = app(CuTelematicFileBuilder::class);
    $second = cuFamilyCertification(['recipient_name' => 'Anna Bianchi', 'recipient_tax_code' => 'BNCNNA85B41F205Y', 'gross_amount' => '2500.50', 'taxable_amount' => '2500.50', 'withheld_amount' => '500.10']);

    $file = $builder->build(cuFamilyTenant(), 2026, [cuFamilyCertification(), $second], new DateTimeImmutable('2027-02-15'));
    $records = explode("\r\n", $file['content']);

    expect($file['file_name'])->toBe('IT00743110157_CU_2026.txt')
        ->and($records)->toHaveCount(10)
        ->and(implode('', array_map(static fn (string $r): string => $r[0] ?? '', $records)))->toBe('ABCDEDEHZ')
        ->and($builder->structuralErrors($file['content']))->toBe([]);

    foreach (array_slice($records, 0, 9) as $record) {
        expect(strlen($record))->toBe(1898)
            ->and($record[1897])->toBe('A');
    }

    expect(substr($records[0], 15, 5))->toBe('CUR26')
        ->and(substr($records[0], 20, 2))->toBe('01')
        ->and(substr($records[0], 53, 8))->toBe('15022027')
        ->and(substr($records[1], 42, 60))->toStartWith('ACME SRL')
        ->and(substr($records[1], 142, 5))->toBe('20121')
        ->and(substr($records[1], 147, 40))->toStartWith('MILANO')
        ->and(substr($records[1], 187, 2))->toBe('MI')
        ->and(substr($records[2], 24, 11))->toBe('00743110157')
        ->and(substr($records[3], 24, 16))->toBe('RSSMRA80A01H501U')
        ->and(substr($records[3], 40, 1))->toBe('1')
        ->and(substr($records[3], 115, 5))->toBe('00002')
        ->and(substr($records[4], 42, 15))->toBe('000000000100000')
        ->and(substr($records[4], 87, 15))->toBe('000000000020000')
        ->and(substr($records[6], 42, 15))->toBe('000000000250050')
        ->and(substr($records[7], 31, 17))->toBe('00000000000350050')
        ->and(substr($records[7], 82, 17))->toBe('00000000000070010')
        ->and(substr($records[8], 16, 7))->toBe('0000001')
        ->and(substr($records[8], 37, 7))->toBe('0000002')
        ->and(substr($records[8], 58, 9))->toBe('000000009')
        ->and(substr($records[8], 67, 15))->toBe('000000000350050');
});

it('detects structural damage: wrong length, order, progressive or totals', function (): void {
    $builder = app(CuTelematicFileBuilder::class);
    $content = $builder->build(cuFamilyTenant(), 2026, [cuFamilyCertification()])['content'];
    $records = explode("\r\n", $content);

    $shortened = $records;
    $shortened[3] = substr($shortened[3], 1);
    $swapped = $records;
    [$swapped[3], $swapped[4]] = [$swapped[4], $swapped[3]];
    $wrongTotal = $records;
    $wrongTotal[5] = substr_replace($wrongTotal[5], '00000000000000001', 31, 17);
    $wrongProgressive = $records;
    $wrongProgressive[4] = substr_replace($wrongProgressive[4], '0000009', 17, 7);

    expect($builder->structuralErrors($content))->toBe([])
        ->and($builder->structuralErrors(implode("\r\n", $shortened)))->not->toBe([])
        ->and($builder->structuralErrors(implode("\r\n", $swapped)))->not->toBe([])
        ->and($builder->structuralErrors(implode("\r\n", $wrongTotal)))->not->toBe([])
        ->and($builder->structuralErrors(implode("\r\n", $wrongProgressive)))->not->toBe([])
        ->and($builder->structuralErrors(rtrim($content)))->not->toBe([]);
});

it('refuses a tax code with a wrong check character, an invalid company address and an oversized amount', function (): void {
    $builder = app(CuTelematicFileBuilder::class);
    $badAddress = new Tenant(['legal_name' => 'Acme Srl', 'fiscal_code' => '00743110157', 'settings' => ['postal_code' => '2012', 'province' => 'MIL']]);
    $oversized = cuFamilyCertification(['gross_amount' => '99999999999999.00', 'taxable_amount' => '1.00', 'withheld_amount' => '0.00']);

    expect(array_column($builder->preflight(cuFamilyTenant(), [cuFamilyCertification(['recipient_tax_code' => 'RSSMRA80A01H501X'])]), 'code'))->toContain('cu_tax_code_invalid')
        ->and(array_column($builder->preflight(new Tenant(['legal_name' => 'Acme', 'fiscal_code' => '01234567890']), [cuFamilyCertification()]), 'code'))->toContain('cu_company_tax_code_invalid')
        ->and(array_column($builder->preflight($badAddress, [cuFamilyCertification()]), 'code'))->toContain('cu_company_address_invalid')
        ->and(array_column($builder->preflight(cuFamilyTenant(), [$oversized]), 'code'))->toContain('cu_field_too_long');
});

it('validates tax codes, VAT numbers, dates, cents and text', function (): void {
    expect(CuFieldValidator::isValidTaxCode('RSSMRA80A01H501U'))->toBeTrue()
        ->and(CuFieldValidator::isValidTaxCode('RSSMRA80A01H501X'))->toBeFalse()
        ->and(CuFieldValidator::isValidTaxCode('00743110157'))->toBeTrue()
        ->and(CuFieldValidator::isValidTaxCode('12345'))->toBeFalse()
        ->and(CuFieldValidator::isValidVatNumber('IT00743110157'))->toBeTrue()
        ->and(CuFieldValidator::isValidVatNumber('01234567890'))->toBeFalse()
        ->and(CuFieldValidator::isValidDate('29022028'))->toBeTrue()
        ->and(CuFieldValidator::isValidDate('29022027'))->toBeFalse()
        ->and(CuFieldValidator::isValidDate('2027-02-15'))->toBeFalse()
        ->and(CuFieldValidator::isValidCents('000000123', 15))->toBeTrue()
        ->and(CuFieldValidator::isValidCents('1234567890123456', 15))->toBeFalse()
        ->and(CuFieldValidator::isValidCents('-5', 15))->toBeFalse()
        ->and(CuFieldValidator::sanitizeText('Rossi & Figli (srl) è'))->toBe('ROSSI & FIGLI SRL E')
        ->and(CuFieldValidator::formatDate(new DateTimeImmutable('2027-01-05')))->toBe('05012027');
});
