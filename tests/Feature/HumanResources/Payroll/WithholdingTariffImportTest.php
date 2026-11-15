<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Contracts\Payroll\WithholdingTariffImporterInterface;
use Modules\HumanResources\Contracts\Payroll\WithholdingTaxServiceInterface;
use Modules\HumanResources\Exceptions\Payroll\WithholdingTariffImportException;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Modules\HumanResources\Models\Payroll\WithholdingTariffBracket;
use Tests\Feature\HumanResources\Payroll\PayrollFixtures;

uses(DatabaseTransactions::class);

function swissPayrollSampleTariffCsv(string $topRate = '9.50'): string
{
    return implode("\n", [
        'canton;tariff_code;valid_from;valid_to;income_from;income_to;rate_percent;fixed_amount',
        'TI;A0N;2026-01-01;2026-12-31;0.00;5000.00;3.00;0.00',
        'TI;A0N;2026-01-01;2026-12-31;5000.05;;'.$topRate.';0.00',
        'TI;A0Y;2026-01-01;2026-12-31;0.00;;10.00;0.00',
    ]);
}

test('the CSV importer creates tariffs with sorted brackets and replaces them on re-import', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    $importer = app(WithholdingTariffImporterInterface::class);

    expect($importer->import(swissPayrollSampleTariffCsv(), (string) $tenant->id))->toBe(['tariffs' => 2, 'brackets' => 3]);

    $importer->import(swissPayrollSampleTariffCsv('11.00'), (string) $tenant->id);

    $tariff = WithholdingTariff::query()->where('tenant_id', $tenant->id)->where('tariff_code', 'A0N')->sole();

    expect($tariff->brackets)->toHaveCount(2)
        ->and($tariff->brackets->last()?->rate_ppm)->toBe(110_000)
        ->and(WithholdingTariffBracket::query()->where('withholding_tariff_id', $tariff->id)->count())->toBe(2);

    $resolved = app(WithholdingTaxServiceInterface::class)->resolveTariff('TI', 'A0N', false, new DateTimeImmutable('2026-06-01'));

    expect($resolved->tariffCode)->toBe('A0N')
        ->and(app(WithholdingTaxServiceInterface::class)->taxFor($resolved, 600_000))->toBe(66_000);
});

test('invalid CSV rows are reported per line and nothing is imported', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    $csv = implode("\n", [
        'canton,tariff_code,valid_from,valid_to,income_from,income_to,rate_percent,fixed_amount',
        'TI,A0N,2026-01-01,,0.00,5000.00,3.00,0.00',
        'XX,A0N,2026-01-01,,0.00,5000.00,3.00,0.00',
        'TI,A0N,2026-13-01,,0.00,5000.00,3.00,0.00',
    ]);

    try {
        app(WithholdingTariffImporterInterface::class)->import($csv, (string) $tenant->id);
        $this->fail('The import should have failed.');
    } catch (WithholdingTariffImportException $exception) {
        expect($exception->invalidParams())->toHaveKeys(['rows.3', 'rows.4']);
    }

    expect(WithholdingTariff::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('tariffs can be uploaded as CSV or created with brackets through the API', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    Sanctum::actingAs($user, ['*']);

    $this->post('/api/v1/hr/payroll/withholding-tariffs/import', [
        'file' => UploadedFile::fake()->createWithContent('tariffs.csv', swissPayrollSampleTariffCsv()),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.tariffs', 2)
        ->assertJsonPath('data.brackets', 3);

    $this->postJson('/api/v1/hr/payroll/withholding-tariffs', [
        'canton' => 'ZH',
        'tariff_code' => 'B1N',
        'valid_from' => '2026-01-01',
        'brackets' => [
            ['income_from' => 0, 'income_to' => 6000, 'rate_percent' => 1.5],
            ['income_from' => 6000.05, 'income_to' => null, 'rate_percent' => 6.25, 'fixed_amount' => 0],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.canton', 'ZH')
        ->assertJsonPath('data.brackets.1.rate_percent', '6.25')
        ->assertJsonPath('data.brackets.1.income_from', '6000.05');

    $this->getJson('/api/v1/hr/payroll/withholding-tariffs?canton=TI&valid_on=2026-05-01')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('the artisan command imports system default tariffs', function (): void {
    app(TenantContext::class)->clear();
    $path = tempnam(sys_get_temp_dir(), 'qst');
    file_put_contents((string) $path, swissPayrollSampleTariffCsv());

    try {
        $this->artisan('hr:payroll:import-tariffs', ['file' => $path])
            ->expectsOutputToContain('Imported 2 tariff(s) with 3 bracket(s).')
            ->assertSuccessful();
    } finally {
        @unlink((string) $path);
    }

    expect(WithholdingTariff::withoutGlobalScopes()->whereNull('tenant_id')->where('canton', 'TI')->where('tariff_code', 'A0Y')->exists())->toBeTrue();

    $this->artisan('hr:payroll:import-tariffs', ['file' => '/does/not/exist.csv'])->assertFailed();
});
