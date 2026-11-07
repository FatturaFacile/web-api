<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FiscalRetail\Models\CommercialDocument;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
});

/**
 * Issues a one-line commercial document at the given UTC instant and returns the stored document.
 */
function issueCorrispettivoAtUtc(string $utcInstant): CommercialDocument
{
    test()->travelTo(new DateTimeImmutable($utcInstant, new DateTimeZone('UTC')));

    $id = test()->postJson('/api/v1/fiscal-retail/commercial-documents', [
        'payment_method' => 'cash',
        'lines' => [['description' => 'Caffè', 'quantity' => 1, 'unit_price' => '1.00', 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');

    return CommercialDocument::findOrFail($id);
}

test('a sale after midnight Italian summer time belongs to the Italian day, not to the UTC one', function (): void {
    $document = issueCorrispettivoAtUtc('2027-07-14 22:30:00');

    expect($document->issue_date->toDateString())->toBe('2027-07-15')
        ->and($document->created_at->getTimezone()->getName())->toBe('UTC');
});

test('a sale after midnight Italian winter time belongs to the Italian day', function (): void {
    expect(issueCorrispettivoAtUtc('2027-01-14 23:30:00')->issue_date->toDateString())->toBe('2027-01-15')
        ->and(issueCorrispettivoAtUtc('2027-01-14 22:59:00')->issue_date->toDateString())->toBe('2027-01-14');
});

test('the night clocks go forward and back the business day follows the local wall clock', function (): void {
    // 28 March 2027: 02:00 CET becomes 03:00 CEST. 00:30 UTC is 01:30 CET, 01:30 UTC is 03:30 CEST, both on the 28th.
    expect(issueCorrispettivoAtUtc('2027-03-27 22:59:00')->issue_date->toDateString())->toBe('2027-03-27')
        ->and(issueCorrispettivoAtUtc('2027-03-27 23:00:00')->issue_date->toDateString())->toBe('2027-03-28')
        ->and(issueCorrispettivoAtUtc('2027-03-28 01:30:00')->issue_date->toDateString())->toBe('2027-03-28')
        ->and(issueCorrispettivoAtUtc('2027-03-28 21:59:00')->issue_date->toDateString())->toBe('2027-03-28')
        ->and(issueCorrispettivoAtUtc('2027-03-28 22:00:00')->issue_date->toDateString())->toBe('2027-03-29');

    // 31 October 2027: 03:00 CEST becomes 02:00 CET. The day ends at 23:00 UTC, one hour later than the day before.
    expect(issueCorrispettivoAtUtc('2027-10-30 22:00:00')->issue_date->toDateString())->toBe('2027-10-31')
        ->and(issueCorrispettivoAtUtc('2027-10-31 22:30:00')->issue_date->toDateString())->toBe('2027-10-31')
        ->and(issueCorrispettivoAtUtc('2027-10-31 23:00:00')->issue_date->toDateString())->toBe('2027-11-01');
});

test('the first sale of the new year local time is numbered in the new year', function (): void {
    issueCorrispettivoAtUtc('2027-12-31 22:00:00');
    $document = issueCorrispettivoAtUtc('2027-12-31 23:30:00');

    expect($document->issue_date->toDateString())->toBe('2028-01-01')
        ->and($document->fiscal_year)->toBe(2028)
        ->and($document->sequential_number)->toBe(1);
});

test('closing today local time is allowed while UTC is still on the day before, tomorrow is refused', function (): void {
    issueCorrispettivoAtUtc('2027-07-14 22:30:00');

    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-07-16/close')->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-07-15/close')->assertCreated();

    $this->getJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-07-15')
        ->assertOk()
        ->assertJsonPath('data.closed', true)
        ->assertJsonPath('data.timezone', 'Europe/Rome');

    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('document');
});

test('the register puts a sale of the last evening of the month UTC in the next local month', function (): void {
    issueCorrispettivoAtUtc('2027-04-30 21:30:00');
    issueCorrispettivoAtUtc('2027-04-30 22:30:00');

    $april = $this->getJson('/api/v1/fiscal-retail/corrispettivi/register?year=2027&month=4')->assertOk();
    $may = $this->getJson('/api/v1/fiscal-retail/corrispettivi/register?year=2027&month=5')->assertOk();

    expect($april->json('data.totals.total_gross'))->toBe('1.22')
        ->and($may->json('data.totals.total_gross'))->toBe('1.22')
        ->and($may->json('data.rows.0.business_date'))->toBe('2027-05-01')
        ->and($may->json('data.timezone'))->toBe('Europe/Rome');
});

test('a Swiss company follows Zurich time and a configured time zone wins over the country', function (): void {
    $this->tenant->update(['country' => 'CH']);
    expect(issueCorrispettivoAtUtc('2027-07-14 22:30:00')->issue_date->toDateString())->toBe('2027-07-15');

    $this->putJson('/api/v1/settings/preferences/company', ['timezone' => 'America/New_York'])->assertSuccessful();
    expect(app(TenantClock::class)->timezone(Tenant::find($this->tenant->id)))->toBe('America/New_York');

    $this->putJson('/api/v1/settings/preferences/company', ['timezone' => 'Mars/Olympus'])->assertUnprocessable();
});

test('the clock falls back to the application time zone for other countries', function (): void {
    $tenant = new Tenant(['country' => 'DE']);

    expect(app(TenantClock::class)->timezone($tenant))->toBe('UTC')
        ->and(app(TenantClock::class)->timezone(new Tenant(['country' => 'it'])))->toBe('Europe/Rome')
        ->and(app(TenantClock::class)->businessDate(new DateTimeImmutable('2027-07-14 22:30:00 UTC'), new Tenant(['country' => 'CH'])))->toBe('2027-07-15');
});
