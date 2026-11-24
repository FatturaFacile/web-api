<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\GeneralLedger\Exceptions\VatSettlementException;
use Modules\GeneralLedger\Services\Tax\LipeData;
use Modules\GeneralLedger\Services\Tax\LipeIdentifierValidator;
use Modules\GeneralLedger\Services\Tax\LipeXmlBuilder;
use Modules\GeneralLedger\Services\Tax\LipeXsdValidator;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\CuCertification;
use Modules\ItalianVat\Services\CuCertificationService;
use Modules\ItalianVat\Services\CuTelematicFileBuilder;

function lipeModule(int $month, string $output = '1000', string $input = '700'): LipeData
{
    return new LipeData(
        taxCode: '01234567890',
        vatNumber: '01234567890',
        year: 2026,
        month: $month,
        activeOperations: '4545.45',
        passiveOperations: '3181.82',
        outputVat: $output,
        inputVat: $input,
        payable: '300',
    );
}

it('builds one communication with a module for each month of the quarter, valid against the schema', function (): void {
    $modules = [lipeModule(1), lipeModule(2, '800', '900'), lipeModule(3)];
    $xml = LipeXmlBuilder::buildModules($modules[0], $modules);

    expect(LipeXsdValidator::errors($xml))->toBe([])
        ->and(substr_count($xml, '<iv:Modulo>'))->toBe(3)
        ->and($xml)->not->toContain('NumeroModulo')
        ->and($xml)->toContain('xmlns:ds="http://www.w3.org/2000/09/xmldsig#"')
        ->and($xml)->toContain('<iv:Mese>2</iv:Mese>')
        ->and($xml)->toContain('<iv:IvaCredito>100,00</iv:IvaCredito>');
});

it('writes the optional VP1 fields in the order of the specification', function (): void {
    $data = new LipeData(taxCode: '00743110157', vatNumber: '00743110157', year: 2026, quarter: 2, subcontracting: true, exceptionalEvents: 9, advanceMethod: 1, advance: '10', outputVat: '100');
    $xml = LipeXmlBuilder::build($data);

    expect(LipeXsdValidator::errors($xml))->toBe([])
        ->and(strpos($xml, 'Trimestre'))->toBeLessThan(strpos($xml, 'Subfornitura'))
        ->and(strpos($xml, 'Subfornitura'))->toBeLessThan(strpos($xml, 'EventiEccezionali'))
        ->and(strpos($xml, 'Metodo'))->toBeLessThan(strpos($xml, 'Acconto'));
});

it('names the file with the sender tax code and a five character progressive', function (): void {
    $data = lipeModule(1);

    expect(LipeXmlBuilder::fileName($data))->toBe('IT01234567890_LI_00001.xml')
        ->and(LipeXmlBuilder::fileName($data, 36))->toBe('IT01234567890_LI_00010.xml')
        ->and(LipeXmlBuilder::fileName($data, 46655))->toBe('IT01234567890_LI_00ZZZ.xml');
});

it('refuses more than five modules and mixed periodicity', function (): void {
    $months = array_map(static fn (int $month): LipeData => lipeModule($month), range(1, 6));
    $mixed = [lipeModule(1), new LipeData(taxCode: '01234567890', vatNumber: '01234567890', year: 2026, quarter: 1)];

    expect(fn () => LipeXmlBuilder::buildModules($months[0], $months))->toThrow(VatSettlementException::class)
        ->and(fn () => LipeXmlBuilder::buildModules($mixed[0], $mixed))->toThrow(VatSettlementException::class);
});

it('checks the Partita IVA, the tax codes, the role code and the period', function (): void {
    $valid = new LipeData(taxCode: '00743110157', vatNumber: '00743110157', year: 2026, month: 3, declarantTaxCode: 'RSSMRA80A01H501U', declarantRoleCode: '1');
    $invalid = new LipeData(taxCode: '12345', vatNumber: '01234567890', year: 2010, month: 13, quarter: 1, declarantTaxCode: 'RSSMRA80A01H501U', declarantRoleCode: '99');

    expect(LipeIdentifierValidator::errors($valid))->toBe([])
        ->and(LipeIdentifierValidator::errors($invalid))->toHaveCount(6);
});

it('keeps the single-module file of build() identical to buildModules() with one module', function (): void {
    $data = lipeModule(5);

    expect(LipeXmlBuilder::build($data))->toBe(LipeXmlBuilder::buildModules($data, [$data]));
});

it('reports the structural errors of a LIPE file that does not match the schema', function (): void {
    $xml = str_replace('<iv:CodiceFornitura>IVP18</iv:CodiceFornitura>', '<iv:CodiceFornitura>XXX</iv:CodiceFornitura>', LipeXmlBuilder::build(lipeModule(1)));

    expect(LipeXsdValidator::errors($xml))->not->toBe([]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function cuCertification(array $attributes = []): CuCertification
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
        'payments_count' => 1,
        'status' => 'validated',
    ]);
}

it('stops the export when a certification or the company data does not validate', function (): void {
    $tenant = new Tenant(['legal_name' => 'Acme Srl', 'fiscal_code' => '00743110157', 'vat_number' => 'IT00743110157']);
    $builder = app(CuTelematicFileBuilder::class);
    $invalid = cuCertification(['recipient_tax_code' => 'NOT-A-TAX-CODE', 'withheld_amount' => '1500.00']);

    $codes = array_column($builder->preflight($tenant, [$invalid]), 'code');

    expect($codes)->toContain('cu_tax_code_invalid')
        ->and($codes)->toContain('cu_withheld_exceeds_taxable')
        ->and(fn () => $builder->build($tenant, 2026, [$invalid]))->toThrow(ItalianVatException::class)
        ->and(array_column($builder->preflight(new Tenant(['legal_name' => 'No Code']), [cuCertification()]), 'code'))->toContain('cu_company_tax_code_invalid')
        ->and(array_column($builder->preflight($tenant, []), 'code'))->toContain('cu_nothing_to_export');
});

it('validates a certification: tax code, reason, amounts and name', function (): void {
    $service = app(CuCertificationService::class);

    expect($service->validate(cuCertification()->getAttributes()))->toBe([])
        ->and(array_column($service->validate(cuCertification(['recipient_tax_code' => null])->getAttributes()), 'code'))->toBe(['cu_tax_code_missing'])
        ->and(array_column($service->validate(cuCertification(['reason_code' => 'ZZ'])->getAttributes()), 'code'))->toBe(['cu_reason_invalid'])
        ->and(array_column($service->validate(cuCertification(['gross_amount' => '0.00', 'taxable_amount' => '0.00', 'withheld_amount' => '0.00'])->getAttributes()), 'code'))->toBe(['cu_gross_not_positive'])
        ->and(array_column($service->validate(cuCertification(['taxable_amount' => '2000.00'])->getAttributes()), 'code'))->toBe(['cu_taxable_exceeds_gross'])
        ->and($service->validate(cuCertification(['recipient_tax_code' => '12345678901'])->getAttributes()))->toBe([]);
});
