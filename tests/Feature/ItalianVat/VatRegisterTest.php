<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\VatRegisterEntry;
use Modules\ItalianVat\Models\VatRegisterPrint;

require_once __DIR__.'/ItalianVatTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ivDeferForeignKeys();
    ivActAs(ivTenant());
});

it('creates the standard sectionals on first use and lets the tenant add its own', function (): void {
    $codes = array_column($this->getJson('/api/v1/italian-vat/sections')->assertOk()->json('data'), 'code');

    expect($codes)->toContain('VEN', 'VEN-DIF', 'VEN-NC', 'VEN-AUT', 'ACQ', 'ACQ-RC', 'COR', 'MCT');

    $this->postJson('/api/v1/italian-vat/sections', ['register_type' => 'sales', 'code' => 'ven-exp', 'name' => 'Export invoices', 'document_kind' => 'ordinary'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'VEN-EXP');

    $this->postJson('/api/v1/italian-vat/sections', ['register_type' => 'sales', 'code' => 'VEN', 'name' => 'Duplicate'])->assertUnprocessable();
});

it('numbers every sectional progressively within the year', function (): void {
    ivRegister('VEN', ['entry_date' => '2025-01-10'])->assertCreated()->assertJsonPath('data.protocol_number', 1);
    ivRegister('VEN', ['entry_date' => '2025-02-10'])->assertCreated()->assertJsonPath('data.protocol_number', 2);
    ivRegister('VEN-DIF', ['entry_date' => '2025-02-11'])->assertCreated()->assertJsonPath('data.protocol_number', 1);
    ivRegister('VEN', ['entry_date' => '2026-01-05'])->assertCreated()->assertJsonPath('data.protocol_number', 1);
});

it('calculates the VAT, scorporates nothing and stores a credit note with negative amounts', function (): void {
    ivRegister('VEN', ['taxable_amount' => '1000.00', 'vat_rate' => 22])->assertCreated()
        ->assertJsonPath('data.vat_amount', '220.00')
        ->assertJsonPath('data.gross_amount', '1220.00');

    ivRegister('VEN-NC', ['taxable_amount' => '100.00', 'vat_rate' => 22])->assertCreated()
        ->assertJsonPath('data.document_kind', 'credit_note')
        ->assertJsonPath('data.taxable_amount', '-100.00')
        ->assertJsonPath('data.vat_amount', '-22.00');
});

it('requires the nature of an operation without VAT and refuses an inconsistent VAT amount', function (): void {
    ivRegister('VEN', ['vat_rate' => 0])->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'vat_nature_required');
    ivRegister('VEN', ['vat_rate' => 0, 'vat_nature' => 'N9'])->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'vat_nature_invalid');
    ivRegister('VEN', ['vat_rate' => 0, 'vat_nature' => 'N3.5'])->assertCreated()->assertJsonPath('data.vat_nature', 'N3.5');
    ivRegister('VEN', ['taxable_amount' => '1000.00', 'vat_rate' => 22, 'vat_amount' => '180.00'])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'vat_amount_mismatch');
});

it('splits the VAT of the purchases with the pro-rata and shows the non-deductible VAT', function (): void {
    $this->putJson('/api/v1/italian-vat/pro-rata/2025', ['deductible_operations' => 700, 'total_operations' => 1000])
        ->assertOk()->assertJsonPath('data.provisional_percentage', 70);

    ivRegister('ACQ', ['entry_date' => '2025-01-15', 'taxable_amount' => '1000.00', 'vat_rate' => 22, 'apply_pro_rata' => true])->assertCreated()
        ->assertJsonPath('data.deduction_percentage', '70.00')
        ->assertJsonPath('data.deductible_vat', '154.00')
        ->assertJsonPath('data.non_deductible_vat', '66.00')
        ->assertJsonPath('data.non_deductible_reason', 'pro_rata');

    ivRegister('ACQ', ['entry_date' => '2025-01-16', 'taxable_amount' => '500.00', 'vat_rate' => 22, 'deduction_percentage' => 40])->assertCreated()
        ->assertJsonPath('data.deductible_vat', '44.00')
        ->assertJsonPath('data.non_deductible_reason', 'other');

    ivRegister('ACQ', ['entry_date' => '2025-01-17', 'taxable_amount' => '100.00', 'vat_rate' => 22])->assertCreated()
        ->assertJsonPath('data.deductible_vat', '22.00')
        ->assertJsonPath('data.non_deductible_vat', '0.00')
        ->assertJsonPath('data.non_deductible_reason', null);

    $this->getJson('/api/v1/italian-vat/pro-rata/2025')->assertOk()->assertJsonPath('data.percentage_in_force', 70);
    $this->getJson('/api/v1/italian-vat/pro-rata/2026')->assertOk()->assertJsonPath('data.percentage_in_force', 70);
});

it('closes the pro-rata of a year when it becomes definitive', function (): void {
    $this->putJson('/api/v1/italian-vat/pro-rata/2025', ['deductible_operations' => 800, 'total_operations' => 1000, 'definitive' => true])
        ->assertOk()->assertJsonPath('data.definitive_percentage', 80)->assertJsonPath('data.is_definitive', true);

    $this->putJson('/api/v1/italian-vat/pro-rata/2025', ['deductible_operations' => 500, 'total_operations' => 1000])
        ->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'pro_rata_year_closed');

    $this->putJson('/api/v1/italian-vat/pro-rata/2026', ['deductible_operations' => 1500, 'total_operations' => 1000])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'pro_rata_operations_inconsistent');
});

it('summarises a register by rate and by nature', function (): void {
    ivRegister('VEN', ['entry_date' => '2025-03-01', 'taxable_amount' => '1000.00', 'vat_rate' => 22]);
    ivRegister('VEN', ['entry_date' => '2025-03-02', 'taxable_amount' => '500.00', 'vat_rate' => 22]);
    ivRegister('VEN', ['entry_date' => '2025-03-03', 'taxable_amount' => '200.00', 'vat_rate' => 10]);
    ivRegister('VEN', ['entry_date' => '2025-03-04', 'taxable_amount' => '300.00', 'vat_rate' => 0, 'vat_nature' => 'N4']);

    $response = $this->getJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'?from=2025-03-01&to=2025-03-31')->assertOk();

    $response->assertJsonPath('data.totals.taxable', '2000.00')
        ->assertJsonPath('data.totals.vat', '350.00')
        ->assertJsonPath('data.by_nature.0.nature', 'N4')
        ->assertJsonPath('data.by_nature.0.taxable', '300.00');

    $rates = collect($response->json('data.by_rate'))->keyBy('rate');

    expect($rates['22.00']['taxable'])->toBe('1500.00')
        ->and($rates['22.00']['count'])->toBe(2)
        ->and($rates['10.00']['vat'])->toBe('20.00')
        ->and($response->json('data.entries'))->toHaveCount(4);
});

it('prints a register definitively and locks the printed lines and the period', function (): void {
    $first = ivRegister('VEN', ['entry_date' => '2025-01-10'])->json('data.id');
    ivRegister('VEN', ['entry_date' => '2025-01-20']);

    $print = $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])
        ->assertCreated()
        ->assertJsonPath('data.first_protocol', 1)
        ->assertJsonPath('data.last_protocol', 2)
        ->assertJsonPath('data.entries_count', 2)
        ->assertJsonPath('data.page_from', 1)
        ->assertJsonPath('data.page_to', 1)
        ->assertJsonPath('data.stamp_reference', 'VEN/2025/1-1');

    expect($print->json('data.content_hash'))->toHaveLength(64);

    $this->deleteJson('/api/v1/italian-vat/register-entries/'.$first)->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'register_entry_locked');
    ivRegister('VEN', ['entry_date' => '2025-01-25'])->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'period_locked');
    ivRegister('VEN', ['entry_date' => '2025-02-03'])->assertCreated()->assertJsonPath('data.protocol_number', 3);
    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'print_nothing_to_print');
});

it('continues the page numbering and the protocols in the next print', function (): void {
    ivRegister('VEN', ['entry_date' => '2025-01-10']);
    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])->assertCreated();

    foreach (range(1, 45) as $day) {
        ivRegister('VEN', ['entry_date' => '2025-02-'.str_pad((string) (($day % 27) + 1), 2, '0', STR_PAD_LEFT)])->assertCreated();
    }

    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-02-28'])
        ->assertCreated()
        ->assertJsonPath('data.first_protocol', 2)
        ->assertJsonPath('data.last_protocol', 46)
        ->assertJsonPath('data.page_from', 2)
        ->assertJsonPath('data.page_to', 3);

    expect($this->getJson('/api/v1/italian-vat/register-prints?year=2025')->assertOk()->json('data'))->toHaveCount(2);
});

it('refuses to print a register with a gap in the protocol numbers', function (): void {
    ivRegister('VEN', ['entry_date' => '2025-01-10']);
    $middle = ivRegister('VEN', ['entry_date' => '2025-01-11'])->json('data.id');
    ivRegister('VEN', ['entry_date' => '2025-01-12']);

    $this->deleteJson('/api/v1/italian-vat/register-entries/'.$middle)->assertNoContent();

    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'print_protocol_gap')
        ->assertJsonPath('invalid_params.0.params.expected', 2)
        ->assertJsonPath('invalid_params.0.params.found', 3);
});

it('refuses a print dated in the future or outside the year of the register', function (): void {
    ivRegister('VEN', ['entry_date' => '2025-01-10']);

    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2099-12-31'])->assertUnprocessable();
    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2024-12-31'])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'print_period_invalid');
});

it('reverses a printed line with an opposite line in the open period', function (): void {
    $original = ivRegister('VEN', ['entry_date' => '2025-01-10', 'taxable_amount' => '1000.00', 'vat_rate' => 22])->json('data.id');
    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])->assertCreated();

    $this->postJson("/api/v1/italian-vat/register-entries/{$original}/reverse", ['entry_date' => '2025-01-20'])
        ->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'period_locked');

    $this->postJson("/api/v1/italian-vat/register-entries/{$original}/reverse", ['entry_date' => '2025-02-05', 'reason' => 'Wrong customer'])
        ->assertCreated()
        ->assertJsonPath('data.taxable_amount', '-1000.00')
        ->assertJsonPath('data.vat_amount', '-220.00')
        ->assertJsonPath('data.protocol_number', 2)
        ->assertJsonPath('data.notes', 'Wrong customer');

    $this->getJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'?from=2025-01-01&to=2025-12-31')
        ->assertJsonPath('data.totals.taxable', '0.00')
        ->assertJsonPath('data.totals.vat', '0.00');
});

it('detects an alteration of a printed register behind the application', function (): void {
    $entry = ivRegister('VEN', ['entry_date' => '2025-01-10'])->json('data.id');
    $print = $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])->json('data.id');

    $this->getJson("/api/v1/italian-vat/register-prints/{$print}/verify")->assertOk()->assertJsonPath('data.intact', true);

    DB::table('it_vat_register_entries')->where('id', $entry)->update(['taxable_amount' => '5.00']);

    $this->getJson("/api/v1/italian-vat/register-prints/{$print}/verify")
        ->assertOk()
        ->assertJsonPath('data.intact', false)
        ->assertJsonPath('data.expected_count', 1);
});

it('never lets a printed line change through the model', function (): void {
    $entryId = ivRegister('VEN', ['entry_date' => '2025-01-10'])->json('data.id');
    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])->assertCreated();

    $entry = VatRegisterEntry::query()->findOrFail($entryId);

    expect($entry->isLocked())->toBeTrue()
        ->and(fn () => $entry->update(['taxable_amount' => '1.00']))->toThrow(ItalianVatException::class)
        ->and(fn () => VatRegisterPrint::query()->findOrFail($entry->print_id)->update(['total_vat' => '1.00']))->toThrow(ItalianVatException::class);
});

it('serves the PDF of a definitive print', function (): void {
    ivFakePdf();
    ivRegister('VEN', ['entry_date' => '2025-01-10']);
    $print = $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-01-31'])->json('data.id');

    $response = $this->get("/api/v1/italian-vat/register-prints/{$print}/pdf")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF-fake italian-vat::register-print');
});
