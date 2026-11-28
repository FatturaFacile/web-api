<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

require_once __DIR__.'/SkiSchoolSupport.php';

uses(RefreshDatabase::class);

const SK_PUBLIC = '/api/v1/ski-school/public/cervinia-ski';

function skOpenPortal(object $test): void
{
    $test->putJson(SK_API.'/settings', ['public_slug' => 'cervinia-ski', 'booking_enabled' => true, 'meeting_point' => 'Cervinia, ticket office'])->assertOk()->assertJsonPath('data.public_slug', 'cervinia-ski');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function skPublicLesson(array $overrides = []): array
{
    return array_merge([
        'discipline' => 'alpine_ski', 'language_code' => 'IT', 'lesson_date' => '2027-01-19', 'start_time' => '09:00', 'slot_minutes' => 55, 'participants' => 2,
        'customer_name' => 'Maria Verdi', 'customer_email' => 'maria@example.com', 'customer_phone' => '+393331234567',
    ], $overrides);
}

test('the portal answers only when its owner switched it on, and an unknown address gets the same not found', function (): void {
    skSetUp($this);

    $this->getJson(SK_PUBLIC.'/catalogue')->assertNotFound();

    skOpenPortal($this);
    $this->getJson(SK_PUBLIC.'/catalogue')->assertOk()
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.vat_legal_reference', 'Art. 10 n. 20 DPR 633/72')
        ->assertJsonPath('data.slot_minutes', [30, 55])
        ->assertJsonPath('data.meeting_point', 'Cervinia, ticket office');
    $this->getJson('/api/v1/ski-school/public/unknown-school/catalogue')->assertNotFound();
});

test('the address of the portal is unique and the switch is a setting of the school', function (): void {
    skSetUp($this);
    skOpenPortal($this);
    treasuryActAs($this, treasuryActor($this, 'accountant', $this->otherTenant), $this->otherTenant);

    $this->putJson(SK_API.'/settings', ['public_slug' => 'cervinia-ski'])->assertUnprocessable()->assertJsonValidationErrors(['public_slug']);
    $this->putJson(SK_API.'/settings', ['public_slug' => 'Not Valid!'])->assertUnprocessable()->assertJsonValidationErrors(['public_slug']);
});

test('the catalogue lists the levels and the add-ons with their prices and VAT rates', function (): void {
    skSetUp($this);
    skOpenPortal($this);

    $catalogue = $this->getJson(SK_PUBLIC.'/catalogue')->assertOk()->json('data');

    expect($catalogue['levels'])->toHaveCount(13)->and(collect($catalogue['addons'])->firstWhere('item_type', 'SKIPASS'))->toBe(['item_type' => 'SKIPASS', 'unit_price_net' => 55.0, 'vat_rate' => 10.0])
        ->and($catalogue['languages'])->toContain('IT')->toContain('DE')->toContain('FR')->toContain('EN');
});

test('the free start times of a day count the instructors who can teach and exclude the busy ones', function (): void {
    skSetUp($this);
    skOpenPortal($this);
    $anna = skInstructor($this);
    skInstructor($this, ['first_name' => 'Hans', 'last_name' => 'Keller', 'languages' => [['code' => 'DE']]]);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '09:50']);

    $result = $this->getJson(SK_PUBLIC.'/availability?date=2027-01-19&discipline=alpine_ski&language=IT&slot_minutes=55')->assertOk()->json('data');

    expect($result['instructors'])->toHaveCount(1)->and($result['instructors'][0]['display_name'])->toBe('Anna R.')
        ->and(collect($result['slots'])->pluck('start')->all())->toContain('08:00')->not->toContain('09:50')
        ->and($result['slots'][0]['available_instructors'])->toBe(1);
    $this->getJson(SK_PUBLIC.'/availability?date=2027-01-19&discipline=alpine_ski&language=IT')->assertUnprocessable()->assertJsonValidationErrors(['slot_minutes']);
});

test('a tourist books a private lesson: the instructor is rotated, the price is computed by the school and add-ons are priced from the catalogue', function (): void {
    skSetUp($this);
    skOpenPortal($this);
    $anna = skInstructor($this);

    $response = $this->postJson(SK_PUBLIC.'/lessons', skPublicLesson([
        'price_net' => 1, 'instructor_id' => (string) Str::uuid(),
        'items' => [['item_type' => 'SKIPASS', 'quantity' => 2, 'days' => 1, 'unit_price_net' => 0.01], ['item_type' => 'RENTAL_HELMET', 'quantity' => 1, 'days' => 2]],
    ]))->assertCreated()
        ->assertJsonPath('data.status', 'CONFIRMED')
        ->assertJsonPath('data.instructor_first_name', 'Anna')
        ->assertJsonPath('data.end_time', '09:55')
        ->assertJsonPath('data.price_net', '82.50')
        ->assertJsonPath('data.vat_legal_reference', 'Art. 10 n. 20 DPR 633/72')
        ->assertJsonPath('data.meeting_point', 'Cervinia, ticket office');

    expect($response->json('data.reference'))->toStartWith('SKL-')->and($response->json('data'))->not->toHaveKey('customer_email')
        ->and($response->json('data.totals'))->toBe(['net_cents' => 8250 + 11000 + 1600, 'vat_cents' => 1100 + 352, 'gross_cents' => 8250 + 11000 + 1600 + 1100 + 352]);

    $lesson = SkiLesson::query()->sole();
    expect((string) $lesson->instructor_id)->toBe((string) $anna->id)->and($lesson->source->value)->toBe('PUBLIC_PORTAL')->and($lesson->customer_email)->toBe('maria@example.com')
        ->and((float) $lesson->packageItems()->where('item_type', 'SKIPASS')->value('unit_price_net'))->toBe(55.0);
});

test('a tourist can request an instructor, and gets a refusal when that instructor is busy', function (): void {
    skSetUp($this);
    skOpenPortal($this);
    $anna = skInstructor($this);
    $marta = skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);
    skLesson($this, ['instructor_id' => $marta->id, 'auto_assign' => false, 'start_time' => '09:00']);

    $this->postJson(SK_PUBLIC.'/lessons', skPublicLesson(['requested_instructor_id' => $marta->id]))->assertUnprocessable()->assertJsonPath('code', 'requested_instructor_unavailable');
    $this->postJson(SK_PUBLIC.'/lessons', skPublicLesson(['requested_instructor_id' => $anna->id]))->assertCreated()->assertJsonPath('data.instructor_first_name', 'Anna');

    expect(SkiLesson::query()->where('source', 'PUBLIC_PORTAL')->sole()->is_nominated)->toBeTrue();
});

test('a booking is refused when nobody teaches that discipline in that language at that time, and the data is validated', function (): void {
    skSetUp($this);
    skOpenPortal($this);
    skInstructor($this, ['languages' => [['code' => 'DE']]]);

    $this->postJson(SK_PUBLIC.'/lessons', skPublicLesson())->assertUnprocessable()->assertJsonPath('code', 'no_instructor_available');
    $this->postJson(SK_PUBLIC.'/lessons', skPublicLesson(['customer_email' => 'nope', 'discipline' => 'curling']))->assertUnprocessable()->assertJsonValidationErrors(['customer_email', 'discipline']);
    $this->postJson(SK_PUBLIC.'/lessons', skPublicLesson(['items' => [['item_type' => 'JETPACK']]]))->assertUnprocessable()->assertJsonValidationErrors(['items.0.item_type']);

    expect(SkiLesson::query()->count())->toBe(0);
});

test('the company comes from the address of the portal and never from a header sent by the client', function (): void {
    skSetUp($this);
    skOpenPortal($this);
    skInstructor($this);

    $this->withHeader('X-Tenant-Id', $this->otherTenant->id)->postJson(SK_PUBLIC.'/lessons', skPublicLesson())->assertCreated();

    expect(SkiLesson::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1)->and(SkiLesson::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(0);
});

test('the weekly courses with a free place are offered by level and a tourist enrols for a computed price', function (): void {
    skSetUp($this);
    skOpenPortal($this);
    $blue = skCourse($this, ['capacity' => 1]);
    skCourse($this, ['level_id' => skLevel('SNOW_LEAGUE', 'RED_PRINCE')->id, 'capacity' => 4, 'language_code' => 'IT']);

    $offer = $this->getJson(SK_PUBLIC.'/courses?discipline=alpine_ski')->assertOk()->assertJsonCount(2, 'data')->json('data');
    expect(collect($offer)->firstWhere('id', (string) $blue->id)['free_places'])->toBe(1)->and($offer[0]['level_label_key'])->toStartWith('skiSchool.level.SNOW_LEAGUE.');
    $this->getJson(SK_PUBLIC.'/courses?language=DE')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $blue->id);

    $response = $this->postJson(SK_PUBLIC.'/courses/'.$blue->id.'/enrollments', [
        'student_name' => 'Luca Verdi', 'guardian_email' => 'maria@example.com', 'emergency_contact_name' => 'Maria Verdi', 'emergency_contact_phone' => '+393331234567',
        'price_net' => 1, 'items' => [['item_type' => 'ARTVA_KIT', 'quantity' => 1, 'days' => 5]],
    ])->assertCreated()->assertJsonPath('data.price_net', '250.00')->assertJsonPath('data.vat_legal_reference', 'Art. 10 n. 20 DPR 633/72');

    expect($response->json('data.reference'))->toStartWith('GCE-')->and($response->json('data.totals.net_cents'))->toBe(25000 + 12500)
        ->and(CourseEnrollment::query()->sole()->source->value)->toBe('PUBLIC_PORTAL');

    $this->getJson(SK_PUBLIC.'/courses?language=DE')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(SK_PUBLIC.'/courses/'.$blue->id.'/enrollments', ['student_name' => 'Late', 'guardian_email' => 'a@example.com', 'emergency_contact_name' => 'A', 'emergency_contact_phone' => '1'])
        ->assertUnprocessable()->assertJsonPath('code', 'course_full');
});

test('the public routes are throttled by IP', function (): void {
    skSetUp($this);
    skOpenPortal($this);

    $middleware = collect(app('router')->getRoutes()->getByName('ski-school.public.catalogue')->gatherMiddleware());

    expect($middleware->contains(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'throttle:')))->toBeTrue()
        ->and($middleware->contains('auth:sanctum'))->toBeFalse();
});
