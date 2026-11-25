<?php

declare(strict_types=1);

use Database\Seeders\TreasuryBankingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Treasury\Models\F24Delegation;
use Modules\Treasury\Models\F24TaxCode;
use Modules\Treasury\Services\F24\F24DelegationValidator;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    app(TreasuryBankingReferenceSeeder::class)->run();
});

/**
 * @param  list<array<string, mixed>>|null  $lines
 * @return array<string, mixed>
 */
function f24Payload(?array $lines = null, array $overrides = []): array
{
    return array_merge([
        'taxpayer_fiscal_code' => 'RSSMRA85T10A562S',
        'taxpayer_name' => 'Rossi',
        'taxpayer_first_name' => 'Mario',
        'taxpayer_birth_date' => '1985-12-10',
        'taxpayer_birth_place' => 'Bergamo',
        'taxpayer_birth_province' => 'BG',
        'taxpayer_gender' => 'M',
        'payment_date' => now()->addDays(3)->toDateString(),
        'lines' => $lines ?? [
            ['section' => 'erario', 'tax_code' => '1040', 'reference_period' => '', 'reference_year' => (string) now()->year, 'debit_amount' => '1000.00', 'credit_amount' => '0'],
            ['section' => 'erario', 'tax_code' => '6099', 'reference_year' => (string) now()->year, 'debit_amount' => '0', 'credit_amount' => '250.50'],
        ],
    ], $overrides);
}

test('the reference tax codes are seeded idempotently as global rows', function (): void {
    app(TreasuryBankingReferenceSeeder::class)->run();
    $count = F24TaxCode::withoutGlobalScopes()->count();
    app(TreasuryBankingReferenceSeeder::class)->run();

    expect(F24TaxCode::withoutGlobalScopes()->count())->toBe($count)
        ->and(F24TaxCode::withoutGlobalScopes()->where('section', 'erario')->where('code', '6099')->whereNull('tenant_id')->exists())->toBeTrue();
});

test('a delegation is stored with exact totals and the payable balance', function (): void {
    $response = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload())->assertCreated();

    $response->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total_debit', '1000.00')
        ->assertJsonPath('data.total_credit', '250.50')
        ->assertJsonPath('data.total_payable', '749.50')
        ->assertJsonCount(2, 'data.lines');
});

test('a balanced delegation validates, generates a fixed width file and keeps its hash', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload())->assertCreated()->json('data.id');

    $this->postJson("/api/v1/treasury/f24/delegations/{$id}/validate")
        ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.delegation.status', 'validated');

    $this->postJson("/api/v1/treasury/f24/delegations/{$id}/generate")
        ->assertOk()->assertJsonPath('data.status', 'generated')->assertJsonPath('data.total_payable', '749.50');

    $download = $this->get("/api/v1/treasury/f24/delegations/{$id}/download")->assertOk();
    $records = array_values(array_filter(explode("\r\n", $download->getContent())));

    expect($records)->toHaveCount(6)
        ->and(array_map('strlen', $records))->each->toBe(200)
        ->and(substr($records[0], 0, 2))->toBe('HD')
        ->and(substr($records[1], 0, 2))->toBe('TX')
        ->and(substr($records[1], 2, 16))->toBe('RSSMRA85T10A562S')
        ->and(substr($records[2], 0, 2))->toBe('ER')
        ->and(substr($records[4], 0, 2))->toBe('TT')
        ->and(substr($records[4], 2, 45))->toBe('000000000100000'.'000000000025050'.'000000000074950')
        ->and(substr($records[5], 0, 2))->toBe('EF')
        ->and($download->headers->get('X-Content-SHA256'))->toBe(hash('sha256', $download->getContent()));
});

test('credits compensated above the debits are refused', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload([
        ['section' => 'erario', 'tax_code' => '1040', 'reference_year' => (string) now()->year, 'debit_amount' => '100.00', 'credit_amount' => '0'],
        ['section' => 'erario', 'tax_code' => '6099', 'reference_year' => (string) now()->year, 'debit_amount' => '0', 'credit_amount' => '100.01'],
    ]))->assertCreated()->json('data.id');

    $result = $this->postJson("/api/v1/treasury/f24/delegations/{$id}/validate")->assertOk()->assertJsonPath('data.valid', false);

    expect(collect($result->json('data.issues'))->pluck('code')->all())->toContain('credit_exceeds_debit');
    $this->postJson("/api/v1/treasury/f24/delegations/{$id}/generate")->assertUnprocessable();
    expect(F24Delegation::query()->find($id)->status)->toBe('draft');
});

test('an F24 whose credits cancel the debits exactly is a zero balance F24 and can be generated', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload([
        ['section' => 'erario', 'tax_code' => '1040', 'reference_year' => (string) now()->year, 'debit_amount' => '300.00', 'credit_amount' => '0'],
        ['section' => 'erario', 'tax_code' => '6099', 'reference_year' => (string) now()->year, 'debit_amount' => '0', 'credit_amount' => '300.00'],
    ]))->assertCreated()->assertJsonPath('data.total_payable', '0.00')->json('data.id');

    $this->postJson("/api/v1/treasury/f24/delegations/{$id}/generate")->assertOk()->assertJsonPath('data.status', 'generated');
});

test('section rules are enforced: INPS data, IMU municipality code, unknown codes and the reference year', function (): void {
    $year = (string) now()->year;
    $lines = collect([
        ['section' => 'inps', 'tax_code' => 'DM10', 'institution_code' => '12', 'reference_number' => '', 'reference_period' => '1326', 'debit_amount' => '10.00', 'credit_amount' => '0'],
        ['section' => 'imu', 'tax_code' => '3918', 'institution_code' => 'ABCD', 'reference_year' => $year, 'debit_amount' => '10.00', 'credit_amount' => '0'],
        ['section' => 'erario', 'tax_code' => '9999', 'reference_year' => $year, 'debit_amount' => '10.00', 'credit_amount' => '0'],
        ['section' => 'regioni', 'tax_code' => '3800', 'institution_code' => '03', 'reference_year' => '', 'debit_amount' => '10.00', 'credit_amount' => '0'],
        ['section' => 'erario', 'tax_code' => '1040', 'reference_year' => $year, 'debit_amount' => '10.00', 'credit_amount' => '10.00'],
    ]);
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload($lines->all()))->assertCreated()->json('data.id');

    $codes = collect($this->postJson("/api/v1/treasury/f24/delegations/{$id}/validate")->assertOk()->json('data.issues'))->pluck('code')->all();

    expect($codes)->toContain('institution_code_invalid', 'reference_number_required', 'reference_period_invalid', 'cadastral_code_invalid', 'tax_code_unknown', 'reference_year_required', 'one_side_only');
});

test('taxpayer data and the payment date are checked', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload(null, ['taxpayer_fiscal_code' => 'RSSMRA85T10A562X', 'payment_date' => now()->subDay()->toDateString()]))
        ->assertCreated()->json('data.id');

    $codes = collect($this->postJson("/api/v1/treasury/f24/delegations/{$id}/validate")->json('data.issues'))->pluck('code')->all();

    expect($codes)->toContain('taxpayer_fiscal_code_invalid', 'payment_date_past');
});

test('a generated delegation can no longer be changed or deleted, and can be marked paid', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/treasury/f24/delegations/{$id}/mark-paid")->assertUnprocessable();
    $this->postJson("/api/v1/treasury/f24/delegations/{$id}/generate")->assertOk();

    $this->putJson("/api/v1/treasury/f24/delegations/{$id}", f24Payload())->assertUnprocessable();
    $this->deleteJson("/api/v1/treasury/f24/delegations/{$id}")->assertUnprocessable();
    $this->postJson("/api/v1/treasury/f24/delegations/{$id}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');
});

test('a draft can be edited and deleted', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload())->assertCreated()->json('data.id');

    $this->putJson("/api/v1/treasury/f24/delegations/{$id}", f24Payload([
        ['section' => 'erario', 'tax_code' => '1040', 'reference_year' => (string) now()->year, 'debit_amount' => '10.00', 'credit_amount' => '0'],
    ]))->assertOk()->assertJsonPath('data.total_payable', '10.00')->assertJsonCount(1, 'data.lines');

    $this->deleteJson("/api/v1/treasury/f24/delegations/{$id}")->assertOk();
    $this->getJson("/api/v1/treasury/f24/delegations/{$id}")->assertNotFound();
});

test('the download of a delegation without a file is refused', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload())->assertCreated()->json('data.id');

    $this->get("/api/v1/treasury/f24/delegations/{$id}/download")->assertUnprocessable();
});

test('a delegation of another company is not reachable', function (): void {
    $id = $this->postJson('/api/v1/treasury/f24/delegations', f24Payload())->assertCreated()->json('data.id');
    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson("/api/v1/treasury/f24/delegations/{$id}")->assertNotFound();
    $this->getJson('/api/v1/treasury/f24/delegations')->assertOk()->assertJsonCount(0, 'data');
});

test('the tax codes can be listed by section', function (): void {
    $codes = $this->getJson('/api/v1/treasury/f24/tax-codes?section=imu')->assertOk()->json('data');

    expect(collect($codes)->pluck('section')->unique()->all())->toBe(['imu'])
        ->and(collect($codes)->pluck('code')->all())->toContain('3918');
});

test('the balance of a set of lines is computed with exact cents', function (): void {
    $totals = app(F24DelegationValidator::class)->totals(collect([
        ['debit_amount' => '0.10', 'credit_amount' => '0'],
        ['debit_amount' => '0.20', 'credit_amount' => '0'],
        ['debit_amount' => '0', 'credit_amount' => '0.30'],
    ]));

    expect($totals)->toBe(['debit' => '0.30', 'credit' => '0.30', 'payable' => '0.00']);
});
