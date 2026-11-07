<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Models\LegalParameter;
use Modules\Associations\Services\LegalParameterService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    $this->year = (int) now()->year;
});

afterEach(fn () => associationsTearDown());

function lex398Entry(object $test, string $taxable, string $vat = '0.00', ?string $rate = null): void
{
    $test->postJson('/api/v1/associations/lex398/entries', ['entry_date' => now()->toDateString(), 'document_number' => 'R-'.Str::random(5), 'taxable_amount' => $taxable, 'vat_amount' => $vat, 'vat_rate' => $rate])->assertCreated();
}

test('the register is refused until the company opts for the regime', function (): void {
    actAs($this, 'admin');

    $this->postJson('/api/v1/associations/lex398/entries', ['entry_date' => now()->toDateString(), 'document_number' => 'R-1', 'taxable_amount' => '100.00'])->assertUnprocessable();
    $this->putJson('/api/v1/associations/settings', ['lex398_enabled' => true, 'lex398_since' => now()->startOfYear()->toDateString()])->assertOk()->assertJsonPath('data.lex398_enabled', true);
    lex398Entry($this, '100.00');
});

test('the ceiling is a parameter of the year: without it the monitoring says so, with it the status follows the income', function (): void {
    actAs($this, 'admin');
    $this->putJson('/api/v1/associations/settings', ['lex398_enabled' => true])->assertOk();
    lex398Entry($this, '800.00', '176.00', '22');

    $this->getJson("/api/v1/associations/lex398/{$this->year}/monitor")->assertOk()->assertJsonPath('data.status', 'not_configured')->assertJsonPath('data.ceiling', null);

    LegalParameter::query()->create(['code' => LegalParameterService::Lex398Ceiling, 'year' => $this->year, 'value' => '1000', 'source_reference' => 'test value, not a legal figure']);
    LegalParameter::query()->create(['code' => LegalParameterService::Lex398WarningPercent, 'year' => $this->year, 'value' => '75', 'source_reference' => 'company policy']);

    $this->getJson("/api/v1/associations/lex398/{$this->year}/monitor")->assertJsonPath('data.status', 'warning')->assertJsonPath('data.used_percent', '80.00')->assertJsonPath('data.remaining', '200.00');
    lex398Entry($this, '300.00');
    $this->getJson("/api/v1/associations/lex398/{$this->year}/monitor")->assertJsonPath('data.status', 'exceeded');
    lex398Entry($this, '-200.00');
    $this->getJson("/api/v1/associations/lex398/{$this->year}/monitor")->assertJsonPath('data.status', 'warning')->assertJsonPath('data.commercial_income', '900.00');
});

test('the simplified register totals by rate and applies the flat deduction only when configured', function (): void {
    actAs($this, 'admin');
    $this->putJson('/api/v1/associations/settings', ['lex398_enabled' => true])->assertOk();
    lex398Entry($this, '100.00', '22.00', '22');
    lex398Entry($this, '50.00', '5.00', '10');

    $register = $this->getJson("/api/v1/associations/lex398/{$this->year}/register")->assertOk()->json('data');
    expect($register['totals']['vat'])->toBe('27.00')->and($register['totals']['vat_due'])->toBeNull()->and($register['by_rate'])->toHaveCount(2);

    LegalParameter::query()->create(['code' => LegalParameterService::Lex398VatFlatDeductionPercent, 'year' => $this->year, 'value' => '50', 'source_reference' => 'test value, not a legal figure']);
    expect($this->getJson("/api/v1/associations/lex398/{$this->year}/register")->json('data.totals.vat_due'))->toBe('13.50');
    expect((string) $this->get("/api/v1/associations/lex398/{$this->year}/register/csv")->getContent())->toContain('22.00');
});

test('posted invoices are copied once into the register, internal receipts are not', function (): void {
    actAs($this, 'admin');
    $this->putJson('/api/v1/associations/settings', ['lex398_enabled' => true])->assertOk();
    $contact = Contact::create(['company_name' => 'Sponsor srl', 'email' => 'sponsor@example.com']);
    $tenantId = $this->tenant->id;
    $invoice = static fn (string $number, bool $excluded): string => tap((string) Str::uuid7(), static fn (string $id) => DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'contact_id' => $contact->id, 'invoice_type' => 'standard', 'status' => 'posted', 'invoice_number' => $number,
        'date' => now()->toDateString(), 'total_net' => '1000.00', 'total_tax' => '220.00', 'total_gross' => '1220.00', 'posted_at' => now(), 'sdi_excluded' => $excluded,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $invoice('FT-1', false);
    $invoice('REC-1', true);

    $this->postJson("/api/v1/associations/lex398/{$this->year}/sync-invoices")->assertOk()->assertJsonPath('data.added', 1);
    $this->postJson("/api/v1/associations/lex398/{$this->year}/sync-invoices")->assertOk()->assertJsonPath('data.added', 0);
    $this->getJson("/api/v1/associations/lex398/{$this->year}/register")->assertJsonPath('data.entries.0.counterparty', 'Sponsor srl');
});

test('register entries are append-only, and another company sees none of them', function (): void {
    actAs($this, 'admin');
    $this->putJson('/api/v1/associations/settings', ['lex398_enabled' => true])->assertOk();
    lex398Entry($this, '10.00');

    actAs($this, 'admin', tenant: $this->otherTenant);
    $this->getJson("/api/v1/associations/lex398/{$this->year}/register")->assertOk()->assertJsonCount(0, 'data.entries');

    expect(fn () => DB::table('assoc_lex398_entries')->update(['taxable_amount' => '1.00']))->toThrow(Exception::class);
});
