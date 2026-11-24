<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\DeclarationOfIntent;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\DeclarationOfIntentService;

require_once __DIR__.'/ItalianVatTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ivDeferForeignKeys();
    $this->tenant = ivTenant();
    ivActAs($this->tenant);
    $this->customer = ivContact($this->tenant, ['type' => 'customer', 'company_name' => 'Exporter Spa']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function plafondDeclaration(object $test, array $overrides = []): DeclarationOfIntent
{
    return DeclarationOfIntent::create($overrides + [
        'tenant_id' => $test->tenant->id,
        'contact_id' => $test->customer->id,
        'direction' => DeclarationOfIntent::RECEIVED,
        'protocol_number' => '25'.str_pad((string) random_int(1, 999999999999999), 15, '0', STR_PAD_LEFT).'-000001',
        'receipt_date' => '2025-01-20',
        'year' => 2025,
        'plafond_amount' => '10000.00',
        'consumed_amount' => '8500.00',
        'valid_from' => '2025-01-20',
        'valid_to' => '2025-12-31',
    ]);
}

/**
 * @return TestResponse
 */
function plafondCheck(string $contactId, string $date, int|string $amount, ?string $declarationId = null)
{
    return test()->postJson('/api/v1/italian-vat/plafond/check', array_filter([
        'contact_id' => $contactId, 'date' => $date, 'amount' => $amount, 'declaration_id' => $declarationId,
    ]));
}

it('covers a sale within the residual plafond and tells what is left afterwards', function (): void {
    $declaration = plafondDeclaration($this);

    plafondCheck((string) $this->customer->id, '2025-06-01', 1000)->assertOk()
        ->assertJsonPath('data.covered', true)
        ->assertJsonPath('data.declaration_id', (string) $declaration->id)
        ->assertJsonPath('data.residual', '1500.00')
        ->assertJsonPath('data.residual_after', '500.00')
        ->assertJsonPath('data.error_code', null);
});

it('refuses a sale above the residual plafond, and one without any declaration', function (): void {
    plafondDeclaration($this);

    plafondCheck((string) $this->customer->id, '2025-06-01', 2000)->assertOk()
        ->assertJsonPath('data.covered', false)
        ->assertJsonPath('data.error_code', 'plafond_exceeded');

    $stranger = ivContact($this->tenant, ['type' => 'customer', 'company_name' => 'No Declaration', 'fiscal_code' => 'BNCNNA85B41F205X']);

    plafondCheck((string) $stranger->id, '2025-06-01', 10)->assertOk()
        ->assertJsonPath('data.covered', false)
        ->assertJsonPath('data.error_code', 'plafond_no_declaration');
});

it('refuses a sale on a date outside the validity of the declaration', function (): void {
    plafondDeclaration($this);

    plafondCheck((string) $this->customer->id, '2026-02-01', 10)->assertJsonPath('data.error_code', 'declaration_expired');
    plafondCheck((string) $this->customer->id, '2025-01-05', 10)->assertJsonPath('data.error_code', 'declaration_not_yet_valid');
});

it('prefers the declaration that still has plafond among the valid ones', function (): void {
    plafondDeclaration($this, ['consumed_amount' => '10000.00']);
    $fresh = plafondDeclaration($this, ['consumed_amount' => '0.00']);

    plafondCheck((string) $this->customer->id, '2025-06-01', 5000)->assertJsonPath('data.covered', true)->assertJsonPath('data.declaration_id', (string) $fresh->id);
});

it('stops covering sales once a declaration is revoked, and refuses to revoke twice', function (): void {
    $declaration = plafondDeclaration($this);

    $this->postJson("/api/v1/italian-vat/plafond/declarations/{$declaration->id}/revoke", ['reason' => 'Customer request'])
        ->assertOk()
        ->assertJsonPath('data.revocation_reason', 'Customer request');

    plafondCheck((string) $this->customer->id, '2025-06-01', 10)->assertJsonPath('data.covered', false)->assertJsonPath('data.error_code', 'declaration_revoked');
    $this->postJson("/api/v1/italian-vat/plafond/declarations/{$declaration->id}/revoke", [])->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'declaration_already_revoked');
});

it('keeps a revoked declaration out of the issue of the invoice', function (): void {
    $declaration = plafondDeclaration($this);
    $this->postJson("/api/v1/italian-vat/plafond/declarations/{$declaration->id}/revoke", [])->assertOk();

    $invoice = new SalesInvoice(['tenant_id' => $this->tenant->id, 'declaration_of_intent_id' => $declaration->id, 'date' => '2025-06-01', 'total_net' => '10.00']);
    $invoice->contact_id = $this->customer->id;
    $service = app(DeclarationOfIntentService::class);

    expect(fn () => $service->consume($invoice))->toThrow(ValidationException::class);

    $declaration->refresh();

    expect($declaration->consumed_amount)->toBe('8500.00');
});

it('does not pick a revoked declaration for a sale that names none', function (): void {
    $revoked = plafondDeclaration($this);
    $this->postJson("/api/v1/italian-vat/plafond/declarations/{$revoked->id}/revoke", [])->assertOk();

    $invoice = new SalesInvoice(['tenant_id' => $this->tenant->id, 'date' => '2025-06-01']);
    $invoice->contact_id = $this->customer->id;
    $service = app(DeclarationOfIntentService::class);

    expect($service->resolveForSale($invoice))->toBeNull();

    $valid = plafondDeclaration($this, ['consumed_amount' => '0.00']);

    expect($service->resolveForSale($invoice)?->id)->toBe($valid->id);
});

it('consumes the plafond of a covered sale and refuses the one above it', function (): void {
    $declaration = plafondDeclaration($this);
    $service = app(DeclarationOfIntentService::class);

    $covered = new SalesInvoice(['tenant_id' => $this->tenant->id, 'declaration_of_intent_id' => $declaration->id, 'date' => '2025-06-01', 'total_net' => '1000.00']);
    $service->consume($covered);

    expect($declaration->refresh()->consumed_amount)->toBe('9500.00');

    $above = new SalesInvoice(['tenant_id' => $this->tenant->id, 'declaration_of_intent_id' => $declaration->id, 'date' => '2025-06-02', 'total_net' => '600.00']);

    expect(fn () => $service->consume($above))->toThrow(ValidationException::class)
        ->and($declaration->refresh()->consumed_amount)->toBe('9500.00');
});

it('monitors the erosion of the plafond with an alert level for each declaration', function (): void {
    $warning = plafondDeclaration($this);
    $exhausted = plafondDeclaration($this, ['consumed_amount' => '10000.00', 'valid_from' => '2025-02-01']);
    $healthy = plafondDeclaration($this, ['consumed_amount' => '1000.00', 'valid_from' => '2025-03-01']);
    $revoked = plafondDeclaration($this, ['consumed_amount' => '0.00', 'valid_from' => '2025-04-01']);
    $this->postJson("/api/v1/italian-vat/plafond/declarations/{$revoked->id}/revoke", [])->assertOk();

    $monitor = $this->getJson('/api/v1/italian-vat/plafond/monitor?year=2025&as_of=2025-06-01')->assertOk();
    $rows = collect($monitor->json('data.declarations'))->keyBy('id');

    expect($rows[(string) $warning->id]['usage_percentage'])->toBe('85.00')
        ->and($rows[(string) $warning->id]['alert'])->toBe('warning')
        ->and($rows[(string) $warning->id]['status'])->toBe('active')
        ->and($rows[(string) $exhausted->id]['status'])->toBe('exhausted')
        ->and($rows[(string) $exhausted->id]['alert'])->toBe('critical')
        ->and($rows[(string) $healthy->id]['alert'])->toBe('ok')
        ->and($rows[(string) $revoked->id]['status'])->toBe('revoked')
        ->and($monitor->json('data.totals.plafond'))->toBe('40000.00')
        ->and($monitor->json('data.totals.consumed'))->toBe('19500.00')
        ->and($monitor->json('data.totals.residual'))->toBe('20500.00');

    $expired = $this->getJson('/api/v1/italian-vat/plafond/monitor?year=2025&as_of=2026-02-01')->assertOk();

    expect(collect($expired->json('data.declarations'))->pluck('status')->unique()->values()->all())->toBe(['expired', 'revoked']);
});

it('does not see or revoke the declarations of another tenant', function (): void {
    $declaration = plafondDeclaration($this);
    $other = ivTenant('Other');
    ivActAs($other);

    $this->postJson("/api/v1/italian-vat/plafond/declarations/{$declaration->id}/revoke", [])->assertNotFound();
    plafondCheck((string) $this->customer->id, '2025-06-01', 10)->assertJsonPath('data.error_code', 'plafond_no_declaration');
    expect($this->getJson('/api/v1/italian-vat/plafond/monitor?year=2025')->assertOk()->json('data.declarations'))->toBe([]);
});
