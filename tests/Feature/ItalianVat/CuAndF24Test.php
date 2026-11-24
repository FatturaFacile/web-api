<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\WithholdingSettlement;
use Modules\GeneralLedger\Models\WithholdingSettlementLine;
use Modules\ItalianVat\Services\CuTelematicFileBuilder;

require_once __DIR__.'/ItalianVatTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ivDeferForeignKeys();
    $this->tenant = ivTenant();
    ivActAs($this->tenant);
    $this->professional = ivContact($this->tenant, ['company_name' => null, 'first_name' => 'Mario', 'last_name' => 'Rossi', 'fiscal_code' => 'RSSMRA80A01H501U']);
    $this->settlement = WithholdingSettlement::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 3, 'currency' => 'EUR', 'total_withheld' => '340.00', 'total_f24' => '340.00',
        'due_date' => '2026-04-16', 'status' => 'calculated',
    ]);
});

/**
 * One line of the withholding register: what a payment of a supplier carried of one withholding type.
 *
 * @param  array<string, mixed>  $overrides
 */
function withholdingLine(object $test, string $supplierId, string $type, string $withheld, array $overrides = []): WithholdingSettlementLine
{
    return WithholdingSettlementLine::create($overrides + [
        'tenant_id' => $test->tenant->id,
        'withholding_settlement_id' => $test->settlement->id,
        'passive_invoice_id' => (string) Str::uuid(),
        'supplier_payment_id' => (string) Str::uuid(),
        'supplier_id' => $supplierId,
        'invoice_number' => 'F'.Str::random(4),
        'paid_at' => '2026-03-10',
        'withholding_type' => $type,
        'reason' => 'A',
        'f24_code' => '1040',
        'gross_paid' => '1000.00',
        'taxable_amount' => '1000.00',
        'withheld_amount' => $withheld,
    ]);
}

it('builds the certification of a professional from the withholdings paid in the year', function (): void {
    $payment = (string) Str::uuid();
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00', ['supplier_payment_id' => $payment]);
    withholdingLine($this, (string) $this->professional->id, 'RT03', '40.00', ['supplier_payment_id' => $payment]);
    withholdingLine($this, (string) $this->professional->id, 'RT01', '100.00', ['gross_paid' => '500.00', 'taxable_amount' => '500.00']);

    $certification = $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk()->json('data.0');

    expect($certification['recipient_name'])->toBe('Rossi Mario')
        ->and($certification['recipient_kind'])->toBe('natural')
        ->and($certification['reason_code'])->toBe('A')
        ->and($certification['gross_amount'])->toBe('1500.00')
        ->and($certification['taxable_amount'])->toBe('1500.00')
        ->and($certification['withheld_amount'])->toBe('300.00')
        ->and($certification['contributions_amount'])->toBe('40.00')
        ->and($certification['payments_count'])->toBe(2)
        ->and($certification['status'])->toBe('validated')
        ->and($certification['validation_errors'])->toBe([]);
});

it('counts only the payments of the requested year and groups by reason', function (): void {
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00');
    withholdingLine($this, (string) $this->professional->id, 'RT01', '50.00', ['reason' => 'M', 'gross_paid' => '250.00', 'taxable_amount' => '250.00']);
    withholdingLine($this, (string) $this->professional->id, 'RT01', '999.00', ['paid_at' => '2025-12-20']);

    $certifications = $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk()->json('data');

    expect(array_column($certifications, 'reason_code'))->toBe(['A', 'M'])
        ->and($certifications[0]['withheld_amount'])->toBe('200.00')
        ->and($certifications[1]['gross_amount'])->toBe('250.00');
});

it('keeps a certification with a missing tax code as a draft and blocks the issue and the export', function (): void {
    $anonymous = ivContact($this->tenant, ['company_name' => 'No Tax Code Studio', 'fiscal_code' => null, 'vat_number' => null]);
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00');
    withholdingLine($this, (string) $anonymous->id, 'RT01', '100.00');

    $certifications = collect($this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk()->json('data'))->keyBy('recipient_name');

    expect($certifications['No Tax Code Studio']['status'])->toBe('draft')
        ->and($certifications['No Tax Code Studio']['validation_errors'][0]['code'])->toBe('cu_tax_code_missing')
        ->and($certifications['Rossi Mario']['status'])->toBe('validated');

    $this->postJson('/api/v1/italian-vat/cu/2026/issue')->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'cu_validation_failed');
    $this->get('/api/v1/italian-vat/cu/2026/file')->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'cu_export_invalid');
    $this->getJson('/api/v1/italian-vat/cu/2026/validation')->assertOk()->assertJsonPath('data.valid', false);
});

it('issues the certifications and exports the telematic file and the PDF', function (): void {
    ivFakePdf();
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00');
    $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk();

    $this->getJson('/api/v1/italian-vat/cu/2026/validation')->assertOk()->assertJsonPath('data.valid', true);
    $issued = $this->postJson('/api/v1/italian-vat/cu/2026/issue')->assertOk()->json('data');

    expect($issued[0]['status'])->toBe('issued')
        ->and($issued[0]['issued_at'])->not->toBeNull();

    $file = $this->get('/api/v1/italian-vat/cu/2026/file')->assertOk();
    $records = explode("\r\n", $file->getContent());

    expect($records)->toHaveCount(8)
        ->and(strlen($records[0]))->toBe(CuTelematicFileBuilder::RECORD_LENGTH - 2)
        ->and($records[3][0])->toBe('D')
        ->and(substr($records[3], 24, 16))->toBe('RSSMRA80A01H501U')
        ->and(substr($records[4], 42, 15))->toBe('000000000100000')
        ->and(app(CuTelematicFileBuilder::class)->structuralErrors($file->getContent()))->toBe([])
        ->and($file->headers->get('Content-Disposition'))->toContain('IT00743110157_CU_2026.txt');

    $pdf = $this->get('/api/v1/italian-vat/cu/certifications/'.$issued[0]['id'].'/pdf')->assertOk();

    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf->getContent())->toStartWith('%PDF-fake italian-vat::cu-certification');
});

it('does not rewrite an issued certification when the year is built again', function (): void {
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00');
    $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk();
    $this->postJson('/api/v1/italian-vat/cu/2026/issue')->assertOk();

    withholdingLine($this, (string) $this->professional->id, 'RT01', '80.00');

    $certifications = $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk()->json('data');

    expect($certifications)->toHaveCount(1)
        ->and($certifications[0]['withheld_amount'])->toBe('200.00')
        ->and($certifications[0]['status'])->toBe('issued');
});

it('refuses to issue a year without certifications', function (): void {
    $this->postJson('/api/v1/italian-vat/cu/2026/issue')->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'cu_nothing_to_issue');
});

it('derives the F24 due from the withholding settlements and tracks the payments', function (): void {
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00');
    withholdingLine($this, (string) $this->professional->id, 'RT01', '100.00', ['f24_code' => '1038']);

    $payments = collect($this->postJson('/api/v1/italian-vat/f24/2026/sync')->assertOk()->json('data'))->keyBy('tax_code');

    expect($payments['1040']['amount_due'])->toBe('200.00')
        ->and($payments['1038']['amount_due'])->toBe('100.00')
        ->and($payments['1040']['status'])->toBe('due')
        ->and($payments['1040']['due_date'])->toBe('2026-04-16');

    $id = $payments['1040']['id'];

    $this->postJson("/api/v1/italian-vat/f24/payments/{$id}/payments", ['amount' => 50, 'paid_at' => '2026-04-16', 'reference' => 'F24-APR'])
        ->assertOk()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.amount_paid', '50.00');
    $this->postJson("/api/v1/italian-vat/f24/payments/{$id}/payments", ['amount' => 150, 'paid_at' => '2026-04-17'])
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.amount_paid', '200.00')->assertJsonPath('data.f24_reference', 'F24-APR');
    $this->postJson("/api/v1/italian-vat/f24/payments/{$id}/payments", ['amount' => 10, 'paid_at' => '2026-04-18'])
        ->assertOk()->assertJsonPath('data.status', 'overpaid');
    $this->postJson("/api/v1/italian-vat/f24/payments/{$id}/payments", ['amount' => 0, 'paid_at' => '2026-04-18'])->assertUnprocessable();
});

it('counts a withholding settlement paid in the ledger as paid in the F24 tracking, and keeps manual payments on a second sync', function (): void {
    $this->settlement->update(['status' => 'paid', 'payment_date' => '2026-04-15']);
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00');

    $first = $this->postJson('/api/v1/italian-vat/f24/2026/sync')->assertOk()->json('data.0');

    expect($first['status'])->toBe('paid')
        ->and($first['amount_paid'])->toBe('200.00')
        ->and($first['paid_at'])->toBe('2026-04-15');

    withholdingLine($this, (string) $this->professional->id, 'RT01', '30.00');
    $second = $this->postJson('/api/v1/italian-vat/f24/2026/sync')->assertOk()->json('data.0');

    expect($second['amount_due'])->toBe('230.00')
        ->and($second['amount_paid'])->toBe('200.00')
        ->and($second['status'])->toBe('partial');
});

it('reconciles the withholdings certified in the CU with the F24 due and paid', function (): void {
    $this->settlement->update(['status' => 'paid', 'payment_date' => '2026-04-15']);
    $payment = (string) Str::uuid();
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00', ['supplier_payment_id' => $payment]);
    withholdingLine($this, (string) $this->professional->id, 'RT03', '40.00', ['supplier_payment_id' => $payment, 'f24_code' => '1040']);
    $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk();
    $this->postJson('/api/v1/italian-vat/f24/2026/sync')->assertOk();

    $this->getJson('/api/v1/italian-vat/f24/2026/reconciliation')->assertOk()
        ->assertJsonPath('data.certified_withheld', '240.00')
        ->assertJsonPath('data.f24_due', '240.00')
        ->assertJsonPath('data.f24_paid', '240.00')
        ->assertJsonPath('data.status', 'balanced');

    withholdingLine($this, (string) $this->professional->id, 'RT01', '60.00');
    $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk();

    $this->getJson('/api/v1/italian-vat/f24/2026/reconciliation')->assertOk()
        ->assertJsonPath('data.certified_withheld', '300.00')
        ->assertJsonPath('data.due_difference', '60.00')
        ->assertJsonPath('data.status', 'unbalanced');
});

it('keeps the certifications and the F24 of two tenants apart', function (): void {
    withholdingLine($this, (string) $this->professional->id, 'RT01', '200.00');
    $certification = $this->postJson('/api/v1/italian-vat/cu/2026/build')->assertOk()->json('data.0.id');
    $f24 = $this->postJson('/api/v1/italian-vat/f24/2026/sync')->assertOk()->json('data.0.id');

    ivActAs(ivTenant('Other'));

    expect($this->getJson('/api/v1/italian-vat/cu/2026')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/italian-vat/f24/2026')->assertOk()->json('data'))->toBe([]);

    $this->get("/api/v1/italian-vat/cu/certifications/{$certification}/pdf")->assertNotFound();
    $this->postJson("/api/v1/italian-vat/f24/payments/{$f24}/payments", ['amount' => 10, 'paid_at' => '2026-04-16'])->assertNotFound();
    expect(DB::table('it_cu_certifications')->count())->toBe(1);
});
