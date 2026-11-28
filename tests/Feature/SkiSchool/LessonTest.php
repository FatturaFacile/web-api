<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\SkiOutdoorSchool\Events\LessonCompleted;
use Modules\SkiOutdoorSchool\Events\PackageEquipmentReserved;
use Modules\SkiOutdoorSchool\Models\InstructorCommission;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

require_once __DIR__.'/SkiSchoolSupport.php';

uses(RefreshDatabase::class);

test('a lesson is booked with a fair rotation instructor, a reference and the exempt VAT regime of Italy', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);

    $this->postJson(SK_API.'/lessons', skLessonPayload())
        ->assertCreated()
        ->assertJsonPath('data.instructor_id', $anna->id)
        ->assertJsonPath('data.assignment_method', 'FAIR_ROTATION')
        ->assertJsonPath('data.status', 'CONFIRMED')
        ->assertJsonPath('data.start_time', '09:00')
        ->assertJsonPath('data.end_time', '09:55')
        ->assertJsonPath('data.duration_minutes', 55)
        ->assertJsonPath('data.vat_regime', 'IT_EXEMPT_ART10_N20')
        ->assertJsonPath('data.vat_legal_reference', 'Art. 10 n. 20 DPR 633/72')
        ->assertJsonPath('data.price_net', '82.50');

    expect(SkiLesson::query()->first()->reference)->toStartWith('SKL-270118-');
});

test('successive bookings are spread over the instructors by the lowest cumulative hours', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $marta = skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);

    $first = skLesson($this, ['start_time' => '09:00']);
    $second = skLesson($this, ['start_time' => '11:00']);
    $third = skLesson($this, ['start_time' => '13:00']);

    expect([$first->instructor_id, $second->instructor_id])->toContain($anna->id)->toContain($marta->id)
        ->and($first->instructor_id)->not->toBe($second->instructor_id)
        ->and($third->instructor_id)->toBe($first->instructor_id);
});

test('a requested instructor is nominated and overrides the rotation even when more loaded', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $marta = skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '08:00', 'slot_count' => 3]);

    $this->postJson(SK_API.'/lessons', skLessonPayload(['requested_instructor_id' => $anna->id, 'start_time' => '14:00', 'auto_assign' => false]))
        ->assertCreated()
        ->assertJsonPath('data.instructor_id', $anna->id)
        ->assertJsonPath('data.assignment_method', 'NOMINATED')
        ->assertJsonPath('data.is_nominated', true);

    $this->postJson(SK_API.'/lessons', skLessonPayload(['start_time' => '15:00']))->assertCreated()->assertJsonPath('data.instructor_id', $marta->id);
});

test('a requested instructor who is busy is refused instead of replaced', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '09:00']);

    $this->postJson(SK_API.'/lessons', skLessonPayload(['requested_instructor_id' => $anna->id, 'start_time' => '09:30']))
        ->assertUnprocessable()->assertJsonPath('code', 'requested_instructor_unavailable');
});

test('a lesson without an available instructor is refused for auto assignment but kept as a request when not assigned', function (): void {
    skSetUp($this);

    $this->postJson(SK_API.'/lessons', skLessonPayload())->assertUnprocessable()->assertJsonPath('code', 'no_instructor_available');
    $this->postJson(SK_API.'/lessons', skLessonPayload(['auto_assign' => false]))->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->assertJsonPath('data.instructor_id', null);
});

test('a manually chosen instructor must teach the discipline and the language and be free', function (): void {
    skSetUp($this);
    $german = skInstructor($this, ['languages' => [['code' => 'DE']]]);
    $anna = skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '09:00']);

    $this->postJson(SK_API.'/lessons', skLessonPayload(['instructor_id' => $german->id, 'auto_assign' => false]))->assertUnprocessable()->assertJsonPath('code', 'instructor_not_qualified');
    $this->postJson(SK_API.'/lessons', skLessonPayload(['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '09:30']))->assertUnprocessable()->assertJsonPath('code', 'instructor_conflict');
});

test('the slot length and the slot count are validated', function (): void {
    skSetUp($this);
    skInstructor($this);

    $this->postJson(SK_API.'/lessons', skLessonPayload(['slot_minutes' => 45]))->assertUnprocessable()->assertJsonValidationErrors(['slot_minutes']);
    $this->postJson(SK_API.'/lessons', skLessonPayload(['slot_count' => 9]))->assertUnprocessable()->assertJsonValidationErrors(['slot_count']);
    $this->postJson(SK_API.'/lessons', skLessonPayload(['slot_minutes' => 30, 'slot_count' => 3]))->assertCreated()->assertJsonPath('data.duration_minutes', 90)->assertJsonPath('data.end_time', '10:30');
});

test('an unassigned lesson is assigned by the fair rotation or to a chosen instructor', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $marta = skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '08:00']);
    $lesson = skLesson($this, ['auto_assign' => false, 'start_time' => '10:00']);

    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/assign', [])->assertOk()->assertJsonPath('data.instructor_id', $marta->id)->assertJsonPath('data.assignment_method', 'FAIR_ROTATION')->assertJsonPath('data.status', 'CONFIRMED');
    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/assign', ['instructor_id' => $anna->id])->assertOk()->assertJsonPath('data.instructor_id', $anna->id)->assertJsonPath('data.assignment_method', 'MANUAL');
});

test('an open lesson is rescheduled with the conflicts checked against the other lessons but not against itself', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '09:00']);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '11:00']);

    $this->putJson(SK_API.'/lessons/'.$lesson->id.'/reschedule', ['start_time' => '09:30'])->assertOk()->assertJsonPath('data.start_time', '09:30');
    $this->putJson(SK_API.'/lessons/'.$lesson->id.'/reschedule', ['start_time' => '11:30'])->assertUnprocessable()->assertJsonPath('code', 'instructor_conflict');
    $this->putJson(SK_API.'/lessons/'.$lesson->id.'/reschedule', ['lesson_date' => '2027-01-20', 'start_time' => '11:30'])->assertOk()->assertJsonPath('data.lesson_date', '2027-01-20');
});

test('completing a lesson records the fee of the instructor with the secretariat retention, once', function (): void {
    skSetUp($this);
    Event::fake([LessonCompleted::class]);
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['slot_minutes' => 30, 'slot_count' => 2, 'instructor_id' => $anna->id, 'auto_assign' => false]);

    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/complete')->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/complete')->assertUnprocessable()->assertJsonPath('code', 'lesson_not_open');

    $commission = InstructorCommission::query()->sole();
    expect($commission->gross_fee)->toBe('40.00')->and($commission->retention_amount)->toBe('4.00')->and($commission->net_fee)->toBe('36.00')
        ->and($commission->hours)->toBe('1.00')->and((string) $commission->instructor_id)->toBe((string) $anna->id);
    Event::assertDispatched(LessonCompleted::class);
});

test('a lesson without an instructor cannot be completed', function (): void {
    skSetUp($this);
    $lesson = skLesson($this, ['auto_assign' => false]);

    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/complete')->assertUnprocessable()->assertJsonPath('code', 'lesson_without_instructor');
});

test('a cancelled lesson frees the instructor and is not completed or reassigned afterwards', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false]);

    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/cancel', [])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/complete')->assertUnprocessable()->assertJsonPath('code', 'lesson_not_open');
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false]);
    $noShow = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '14:00']);
    $this->postJson(SK_API.'/lessons/'.$noShow->id.'/cancel', ['no_show' => true])->assertOk()->assertJsonPath('data.status', 'NO_SHOW');
});

test('the package of a lesson bundles rental, ARTVA kit and skipass with the VAT of the jurisdiction and announces the equipment', function (): void {
    skSetUp($this);
    Event::fake([PackageEquipmentReserved::class]);
    skInstructor($this);
    $lesson = skLesson($this);

    $this->putJson(SK_API.'/lessons/'.$lesson->id.'/package', ['items' => [
        ['item_type' => 'RENTAL_SKI_KIT', 'quantity' => 2, 'days' => 1, 'unit_price_net' => 35],
        ['item_type' => 'ARTVA_KIT', 'quantity' => 1, 'days' => 1, 'unit_price_net' => 25, 'description' => 'ARTVA, shovel, probe'],
        ['item_type' => 'SKIPASS', 'quantity' => 2, 'days' => 1, 'unit_price_net' => 55],
    ]])
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.vat_rate', '22.00')
        ->assertJsonPath('data.0.line_net', 70.0)
        ->assertJsonPath('data.2.vat_rate', '10.00');

    Event::assertDispatched(PackageEquipmentReserved::class, fn (PackageEquipmentReserved $event): bool => $event->ownerType === 'LESSON' && count($event->equipment) === 2);
    $this->getJson(SK_API.'/lessons/'.$lesson->id)->assertOk()->assertJsonCount(3, 'data.package_items');
});

test('a package item is handed over, taken back or cancelled in the right order', function (): void {
    skSetUp($this);
    skInstructor($this);
    $lesson = skLesson($this);
    $itemId = (string) $this->putJson(SK_API.'/lessons/'.$lesson->id.'/package', ['items' => [['item_type' => 'RENTAL_HELMET', 'unit_price_net' => 8]]])->json('data.0.id');

    $this->putJson(SK_API.'/package-items/'.$itemId, ['status' => 'RETURNED'])->assertUnprocessable()->assertJsonPath('code', 'invalid_item_transition');
    $this->putJson(SK_API.'/package-items/'.$itemId, ['status' => 'DELIVERED'])->assertOk()->assertJsonPath('data.status', 'DELIVERED');
    $this->putJson(SK_API.'/package-items/'.$itemId, ['status' => 'RETURNED'])->assertOk()->assertJsonPath('data.status', 'RETURNED');
});

test('the lessons list filters by status, discipline, instructor and unassigned', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false]);
    skLesson($this, ['auto_assign' => false, 'start_time' => '12:00', 'discipline' => 'snowboard']);

    $this->getJson(SK_API.'/lessons')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson(SK_API.'/lessons?unassigned=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.discipline', 'snowboard');
    $this->getJson(SK_API.'/lessons?instructor_id='.$anna->id.'&status=CONFIRMED')->assertOk()->assertJsonPath('meta.total', 1);
});

test('lessons of another company cannot be read or changed', function (): void {
    skSetUp($this);
    $lesson = treasuryInTenant($this->otherTenant, fn (): SkiLesson => SkiLesson::query()->create([
        'tenant_id' => $this->otherTenant->id, 'reference' => 'SKL-X', 'discipline' => 'alpine_ski', 'language_code' => 'IT', 'lesson_date' => '2027-01-19', 'start_time' => '09:00',
        'end_time' => '09:55', 'slot_minutes' => 55, 'duration_minutes' => 55, 'customer_name' => 'X',
    ]));

    $this->getJson(SK_API.'/lessons/'.$lesson->id)->assertNotFound();
    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/cancel', [])->assertNotFound();
});
