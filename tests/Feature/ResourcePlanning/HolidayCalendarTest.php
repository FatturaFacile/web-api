<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ResourcePlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    rpDeferForeignKeys();
});

/**
 * @return array<string, array<string, mixed>> the holidays of the query, keyed by date
 */
function rpHolidays(object $test, string $query): array
{
    return collect($test->getJson("/api/v1/resource-planning/holidays?{$query}")->assertOk()->json('data'))->keyBy('date')->all();
}

it('resolves the cantonal holidays of Switzerland', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));

    $ticino = rpHolidays($this, 'country=CH&region=TI&year=2027');
    $zurich = rpHolidays($this, 'country=CH&region=ZH&year=2027');

    expect($ticino)->toHaveKeys(['2027-01-01', '2027-05-06', '2027-06-29', '2027-08-01', '2027-12-08'])
        ->and($ticino)->not->toHaveKey('2027-01-02')
        ->and($zurich)->toHaveKeys(['2027-01-02', '2027-05-01'])
        ->and($zurich)->not->toHaveKey('2027-06-29')
        ->and($zurich)->not->toHaveKey('2027-12-08');
});

it('resolves the national and regional holidays of Italy and the patron saint of the municipality', function (): void {
    $tenant = rpTenant('IT');
    rpActAs($tenant, rpUser($tenant));

    $milan = rpHolidays($this, 'country=IT&region=MI&municipality=Milano&year=2027');
    $elsewhere = rpHolidays($this, 'country=IT&year=2027');
    $varese = rpHolidays($this, 'country=IT&region=VA&municipality=Varese&year=2027');

    expect($milan)->toHaveKeys(['2027-04-25', '2027-06-02', '2027-08-15', '2027-12-07', '2027-12-08'])
        ->and($milan['2027-12-07']['name'])->toBe("Sant'Ambrogio")
        ->and($elsewhere)->not->toHaveKey('2027-12-07')
        ->and($varese)->toHaveKey('2027-05-08')
        ->and($elsewhere)->toHaveKey('2027-03-29');
});

it('lets the half days and the corrections of the tenant override the calendar', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    DB::table('time_public_holidays')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'canton' => null, 'holiday_date' => '2027-12-24', 'name' => 'Christmas Eve', 'day_fraction' => 0.5,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $holidays = rpHolidays($this, 'country=CH&region=TI&year=2027');

    expect($holidays['2027-12-24']['day_fraction'])->toBe(0.5)->and($holidays['2027-12-25']['day_fraction'])->toBe(1.0);
});

it('records and removes the local holidays of the tenant', function (): void {
    $tenant = rpTenant('IT');
    rpActAs($tenant, rpUser($tenant));

    $id = $this->postJson('/api/v1/resource-planning/local-holidays', ['country' => 'IT', 'region_code' => 'VA', 'municipality' => 'Luino', 'month' => 9, 'day' => 23, 'name' => 'Local patron saint', 'day_fraction' => 0.5])
        ->assertCreated()
        ->assertJsonPath('data.is_generic', false)
        ->assertJsonPath('data.day_fraction', 0.5)
        ->json('data.id');

    expect($this->getJson('/api/v1/resource-planning/local-holidays')->assertOk()->json('data'))->toHaveCount(1)
        ->and(rpHolidays($this, 'country=IT&region=VA&municipality=Luino&year=2027')['2027-09-23']['day_fraction'])->toBe(0.5)
        ->and(rpHolidays($this, 'country=IT&region=VA&municipality=Gavirate&year=2027'))->not->toHaveKey('2027-09-23')
        ->and(rpHolidays($this, 'country=IT&region=MI&year=2027'))->not->toHaveKey('2027-09-23');

    $this->deleteJson("/api/v1/resource-planning/local-holidays/{$id}")->assertNoContent();
    $this->deleteJson("/api/v1/resource-planning/local-holidays/{$id}")->assertNotFound();
    expect(rpHolidays($this, 'country=IT&region=VA&municipality=Luino&year=2027'))->not->toHaveKey('2027-09-23');
});

it('applies a recurring local holiday every year and a dated one to its year only', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));

    $this->postJson('/api/v1/resource-planning/local-holidays', ['country' => 'CH', 'region_code' => 'TI', 'month' => 3, 'day' => 10, 'name' => 'Recurring feast'])->assertCreated();
    $this->postJson('/api/v1/resource-planning/local-holidays', ['country' => 'CH', 'region_code' => 'TI', 'month' => 3, 'day' => 11, 'year' => 2027, 'name' => 'One-off feast'])->assertCreated();

    expect(rpHolidays($this, 'country=CH&region=TI&year=2027'))->toHaveKeys(['2027-03-10', '2027-03-11'])
        ->and(rpHolidays($this, 'country=CH&region=TI&year=2028'))->toHaveKey('2028-03-10')
        ->and(rpHolidays($this, 'country=CH&region=TI&year=2028'))->not->toHaveKey('2028-03-11');
});

it('keeps generic holidays read-only and the holidays of a tenant private', function (): void {
    $tenant = rpTenant('IT');
    rpActAs($tenant, rpUser($tenant));
    $generic = (string) Str::uuid();
    DB::table('rp_local_holidays')->insert(['id' => $generic, 'tenant_id' => null, 'country' => 'IT', 'month' => 2, 'day' => 3, 'name' => 'Generic feast', 'day_fraction' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect($this->getJson('/api/v1/resource-planning/local-holidays')->json('data.0.is_generic'))->toBeTrue();
    $this->deleteJson("/api/v1/resource-planning/local-holidays/{$generic}")->assertNotFound();

    $this->postJson('/api/v1/resource-planning/local-holidays', ['country' => 'IT', 'month' => 4, 'day' => 5, 'name' => 'Private feast'])->assertCreated();

    $other = rpTenant('IT');
    rpActAs($other, rpUser($other));
    expect(collect($this->getJson('/api/v1/resource-planning/local-holidays')->json('data'))->pluck('name')->all())->toBe(['Generic feast']);
});

it('validates a local holiday', function (): void {
    $tenant = rpTenant('IT');
    rpActAs($tenant, rpUser($tenant));

    $this->postJson('/api/v1/resource-planning/local-holidays', ['country' => 'ITA', 'month' => 13, 'day' => 5, 'name' => 'Bad'])->assertUnprocessable();
    $this->postJson('/api/v1/resource-planning/local-holidays', ['country' => 'IT', 'month' => 4, 'day' => 5])->assertUnprocessable();
});
