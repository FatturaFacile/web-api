<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Treasury\Models\PagoPaPaymentNotice;
use Modules\Treasury\Services\PagoPaNoticeService;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pagoPaPayload(array $overrides = []): array
{
    return array_merge([
        'debtor_name' => 'Mario Rossi',
        'debtor_fiscal_code' => 'RSSMRA80A01H501U',
        'amount' => '122.00',
        'due_date' => now()->addDays(30)->toDateString(),
        'causale' => 'Invoice 2027/15',
        'creditor_fiscal_code' => '12345678901',
    ], $overrides);
}

test('a notice gets a 13-digit IUV and an 18-digit numeric notice code', function (): void {
    $response = $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload())->assertCreated();

    expect($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.iuv'))->toMatch('/^\d{13}$/')
        ->and($response->json('data.notice_code'))->toMatch('/^\d{18}$/')
        ->and($response->json('data.notice_code'))->toStartWith('302'.$response->json('data.iuv'))
        ->and($response->json('data.amount'))->toBe('122.00')
        ->and($response->json('data.currency'))->toBe('EUR');
});

test('IUVs are unique and progressive per tenant, with a counter of their own for each tenant', function (): void {
    $iuvs = collect(range(1, 5))->map(fn (): string => $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload())->assertCreated()->json('data.iuv'));

    expect($iuvs->unique())->toHaveCount(5)
        ->and($iuvs->map(fn (string $iuv): string => substr($iuv, 0, 11))->all())->toBe(['00000000001', '00000000002', '00000000003', '00000000004', '00000000005'])
        ->and($iuvs->every(fn (string $iuv): bool => $iuv === PagoPaNoticeService::buildIuv((int) substr($iuv, 0, 11))))->toBeTrue();

    treasuryActor($this, 'accountant', $this->otherTenant);
    $first = $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload())->assertCreated()->json('data.iuv');

    expect(substr($first, 0, 11))->toBe('00000000001');
});

test('check digits are the remainder modulo 93', function (): void {
    expect(PagoPaNoticeService::buildIuv(1))->toBe('0000000000101')
        ->and(PagoPaNoticeService::buildIuv(94))->toBe('0000000009401')
        ->and(PagoPaNoticeService::buildNoticeCode('0000000000101'))->toHaveLength(18)
        ->and(PagoPaNoticeService::buildNoticeCode('0000000000101'))->toStartWith('3020000000000101');
});

test('the QR payload follows the pagoPA format with the amount in cents', function (): void {
    $notice = $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload(['amount' => '1234.50']))->assertCreated();

    $payload = $this->getJson('/api/v1/treasury/pagopa-notices/'.$notice->json('data.id').'/qr-payload')->assertOk()->json('data.payload');

    expect($payload)->toBe('PAGOPA|002|'.$notice->json('data.notice_code').'|12345678901|123450')
        ->and($payload)->toMatch('/^PAGOPA\|002\|\d{18}\|\d{11}\|\d+$/');
});

test('the creation payload is validated', function (): void {
    $this->postJson('/api/v1/treasury/pagopa-notices', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['debtor_name', 'amount', 'due_date', 'causale', 'creditor_fiscal_code']);
    $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload(['creditor_fiscal_code' => '123']))->assertUnprocessable()->assertJsonValidationErrors(['creditor_fiscal_code']);
    $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload(['amount' => '0']))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
});

test('a pending notice can be paid once and then no longer cancelled', function (): void {
    $id = $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload())->assertCreated()->json('data.id');

    $this->postJson("/api/v1/treasury/pagopa-notices/{$id}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');
    expect(PagoPaPaymentNotice::query()->findOrFail($id)->paid_at)->not->toBeNull();

    $this->postJson("/api/v1/treasury/pagopa-notices/{$id}/mark-paid")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/treasury/pagopa-notices/{$id}/cancel")->assertUnprocessable()->assertJsonValidationErrors(['status']);
});

test('a pending notice can be cancelled and a cancelled notice cannot be paid', function (): void {
    $id = $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload())->assertCreated()->json('data.id');

    $this->postJson("/api/v1/treasury/pagopa-notices/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/treasury/pagopa-notices/{$id}/mark-paid")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/treasury/pagopa-notices/{$id}/cancel")->assertUnprocessable();
});

test('the list filters by status and a notice of another tenant is invisible', function (): void {
    $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload())->assertCreated();
    $cancelled = $this->postJson('/api/v1/treasury/pagopa-notices', pagoPaPayload())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/treasury/pagopa-notices/{$cancelled}/cancel")->assertOk();

    $foreign = treasuryInTenant($this->otherTenant, fn (): PagoPaPaymentNotice => PagoPaPaymentNotice::factory()->create(['tenant_id' => $this->otherTenant->id]));

    $this->getJson('/api/v1/treasury/pagopa-notices')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/treasury/pagopa-notices?status=pending')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/treasury/pagopa-notices?status=bogus')->assertUnprocessable();

    foreach (['', '/qr-payload'] as $suffix) {
        $this->getJson("/api/v1/treasury/pagopa-notices/{$foreign->id}{$suffix}")->assertNotFound();
    }

    $this->postJson("/api/v1/treasury/pagopa-notices/{$foreign->id}/cancel")->assertNotFound();
    $this->postJson("/api/v1/treasury/pagopa-notices/{$foreign->id}/mark-paid")->assertNotFound();
});
