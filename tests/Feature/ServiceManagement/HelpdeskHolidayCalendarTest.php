<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;
use Modules\ServiceManagement\Services\Helpdesk\BusinessHoursClock;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskLocales;
use Modules\ServiceManagement\Services\Helpdesk\HolidayCatalog;

uses(RefreshDatabase::class);

/**
 * Monday to Friday 09:00-18:00 in the given time zone and holiday set, not saved.
 */
function holidayCalendar(string $timezone, ?string $country, ?string $region = null): BusinessHoursCalendar
{
    return BusinessHoursCalendar::factory()->make([
        'tenant_id' => (string) Str::uuid(),
        'timezone' => $timezone,
        'holiday_country' => $country,
        'holiday_region' => $region,
    ]);
}

test('Easter and the movable feasts of the catalog are right', function (): void {
    $catalog = new HolidayCatalog;

    expect($catalog->easter(2026)->format('Y-m-d'))->toBe('2026-04-05')
        ->and($catalog->easter(2027)->format('Y-m-d'))->toBe('2027-03-28')
        ->and($catalog->easter(2028)->format('Y-m-d'))->toBe('2028-04-16')
        ->and($catalog->forYear('CH', 'TI', 2027))->toContain('2027-03-26', '2027-03-29', '2027-05-06', '2027-05-17', '2027-05-27');
});

test('Swiss cantons add their own holidays to the federal ones', function (): void {
    $catalog = new HolidayCatalog;
    $federal = $catalog->forYear('CH', null, 2027);
    $ticino = $catalog->forYear('CH', 'TI', 2027);
    $zurich = $catalog->forYear('CH', 'ZH', 2027);
    $geneva = $catalog->forYear('CH', 'GE', 2027);
    $vaud = $catalog->forYear('CH', 'VD', 2027);

    expect($federal)->toContain('2027-01-01', '2027-08-01', '2027-12-25')
        ->and($ticino)->toContain('2027-01-06', '2027-03-19', '2027-06-29', '2027-08-15', '2027-11-01', '2027-12-08')
        ->and($federal)->not->toContain('2027-01-06')
        ->and($zurich)->toContain('2027-01-02', '2027-05-01')
        ->and($geneva)->toContain('2027-09-09', '2027-12-31')
        ->and($vaud)->toContain('2027-09-20')
        ->and($zurich)->not->toContain('2027-01-06');
});

test('Italy has national holidays and regional observances', function (): void {
    $catalog = new HolidayCatalog;
    $national = $catalog->forYear('IT', null, 2027);

    expect($national)->toContain('2027-01-06', '2027-03-29', '2027-04-25', '2027-06-02', '2027-08-15', '2027-12-08', '2027-12-26')
        ->and($national)->not->toContain('2027-12-07')
        ->and($catalog->forYear('IT', 'LOM', 2027))->toContain('2027-12-07')
        ->and($catalog->forYear('IT', 'LAZ', 2027))->toContain('2027-06-29')
        ->and($catalog->supports('IT', 'LOM'))->toBeTrue()
        ->and($catalog->supports('IT', 'TI'))->toBeFalse()
        ->and($catalog->supports('CH', 'TI'))->toBeTrue()
        ->and($catalog->supports('DE'))->toBeFalse()
        ->and($catalog->forYear('DE', null, 2027))->toBe([]);
});

test('the SLA clock skips the Easter holidays of the calendar country and canton', function (): void {
    $clock = new BusinessHoursClock;
    $format = static fn (CarbonImmutable $at, string $zone): string => $at->setTimezone($zone)->format('Y-m-d H:i');

    // Italy: Friday 26 March 2027 17:00, plus two business hours; Monday 29 March is Easter Monday.
    $rome = CarbonImmutable::parse('2027-03-26 17:00', 'Europe/Rome');
    expect($format($clock->addBusinessMinutes($rome, 120, holidayCalendar('Europe/Rome', null)), 'Europe/Rome'))->toBe('2027-03-29 10:00')
        ->and($format($clock->addBusinessMinutes($rome, 120, holidayCalendar('Europe/Rome', 'IT')), 'Europe/Rome'))->toBe('2027-03-30 10:00');

    // Ticino: Thursday 25 March 17:00; Good Friday and Easter Monday are off, so the second hour falls on Tuesday.
    $zurich = CarbonImmutable::parse('2027-03-25 17:00', 'Europe/Zurich');
    expect($format($clock->addBusinessMinutes($zurich, 120, holidayCalendar('Europe/Zurich', null)), 'Europe/Zurich'))->toBe('2027-03-26 10:00')
        ->and($format($clock->addBusinessMinutes($zurich, 120, holidayCalendar('Europe/Zurich', 'CH', 'TI')), 'Europe/Zurich'))->toBe('2027-03-30 10:00')
        ->and($clock->businessMinutesBetween($zurich, CarbonImmutable::parse('2027-03-30 10:00', 'Europe/Zurich'), holidayCalendar('Europe/Zurich', 'CH', 'TI')))->toBe(120);
});

test('Lombardy skips Sant Ambrogio and a plain Italian calendar does not', function (): void {
    $clock = new BusinessHoursClock;
    // Monday 6 December 2027 17:00 plus two hours; Tuesday 7 December is a Lombard day off.
    $start = CarbonImmutable::parse('2027-12-06 17:00', 'Europe/Rome');

    expect($clock->addBusinessMinutes($start, 120, holidayCalendar('Europe/Rome', 'IT'))->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-12-07 10:00')
        ->and($clock->addBusinessMinutes($start, 120, holidayCalendar('Europe/Rome', 'IT', 'LOM'))->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2027-12-09 10:00');
});

test('the holiday set of a calendar is saved through the API and an unknown canton is refused', function (): void {
    $tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $user = User::create(['name' => 'Manager', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $tenant->id);

    $payload = ['code' => 'LUGANO', 'name' => 'Lugano', 'timezone' => 'Europe/Zurich', 'weekly_schedule' => ['mon' => [['08:00', '17:00']]]];

    $this->postJson('/api/v1/esm/helpdesk/business-hours', [...$payload, 'holiday_country' => 'CH', 'holiday_region' => 'XX'])
        ->assertUnprocessable()->assertJsonValidationErrors('holiday_region');
    $this->postJson('/api/v1/esm/helpdesk/business-hours', [...$payload, 'holiday_country' => 'FR'])
        ->assertUnprocessable()->assertJsonValidationErrors('holiday_country');
    $this->postJson('/api/v1/esm/helpdesk/business-hours', [...$payload, 'holiday_country' => 'CH', 'holiday_region' => 'TI'])
        ->assertCreated()->assertJsonPath('data.holiday_country', 'CH')->assertJsonPath('data.holiday_region', 'TI');

    $ticino = $this->getJson('/api/v1/esm/helpdesk/holidays?country=CH&region=TI&year=2027')->assertOk()->assertJsonPath('data.region', 'TI')->assertJsonPath('data.year', 2027);
    expect($ticino->json('data.dates'))->toContain('2027-03-19');
    expect($this->getJson('/api/v1/esm/helpdesk/holidays?country=IT&region=LOM&year=2027')->assertOk()->json('data.dates'))->toContain('2027-12-07');
    $this->getJson('/api/v1/esm/helpdesk/holidays?country=IT&region=TI')->assertUnprocessable()->assertJsonValidationErrors('region');
    $this->getJson('/api/v1/esm/helpdesk/holidays?country=US')->assertUnprocessable()->assertJsonValidationErrors('country');
});

test('canned responses and knowledge base articles are written and filtered in the five locales', function (): void {
    $tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $user = User::create(['name' => 'Manager', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $tenant->id);

    expect(HelpdeskLocales::All)->toBe(['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'])
        ->and(HelpdeskLocales::language('de-CH'))->toBe('de');

    foreach (HelpdeskLocales::All as $locale) {
        $this->postJson('/api/v1/esm/helpdesk/canned-responses', ['title' => "Greeting {$locale}", 'body' => 'Hello', 'locale' => $locale])
            ->assertCreated()->assertJsonPath('data.locale', $locale);
        $this->postJson('/api/v1/esm/kb/articles', ['title' => "Guide {$locale}", 'content' => 'Steps', 'locale' => $locale, 'visibility' => 'internal'])
            ->assertCreated()->assertJsonPath('data.locale', $locale);
    }

    $this->postJson('/api/v1/esm/helpdesk/canned-responses', ['title' => 'Bad', 'body' => 'x', 'locale' => 'de-DE'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    $this->postJson('/api/v1/esm/kb/articles', ['title' => 'Bad', 'content' => 'x', 'locale' => 'es'])->assertUnprocessable()->assertJsonValidationErrors('locale');

    $articles = $this->getJson('/api/v1/esm/kb/articles?locale=fr-CH')->assertOk()->json('data');
    expect($articles)->toHaveCount(1)->and($articles[0]['title'])->toBe('Guide fr-CH');
});
