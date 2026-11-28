<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SkiOutdoorSchool\Models\Instructor;

require_once __DIR__.'/SkiSchoolSupport.php';

uses(RefreshDatabase::class);

test('an instructor is registered with licence, languages, disciplines, rate and retention rule', function (): void {
    skSetUp($this);

    $this->postJson(SK_API.'/instructors', skInstructorPayload(['languages' => [['code' => 'de', 'proficiency' => 'NATIVE'], ['code' => 'IT'], ['code' => 'DE']], 'license_valid_until' => '2027-12-31']))
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'Anna Rossi')
        ->assertJsonPath('data.license_type', 'AMSI_FISI')
        ->assertJsonPath('data.hourly_rate', '40.00')
        ->assertJsonPath('data.retention_mode', 'PERCENT')
        ->assertJsonPath('data.disciplines', ['alpine_ski', 'snowboard'])
        ->assertJsonCount(2, 'data.languages');

    expect(Instructor::query()->count())->toBe(1);
});

test('the fields of an instructor are validated and the languages are required', function (): void {
    skSetUp($this);

    $this->postJson(SK_API.'/instructors', skInstructorPayload(['license_type' => 'NOPE', 'languages' => [], 'disciplines' => ['curling'], 'hourly_rate' => -1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['license_type', 'languages', 'disciplines.0', 'hourly_rate']);
});

test('an instructor with open lessons cannot be deleted but a free one can', function (): void {
    skSetUp($this);
    $busy = skInstructor($this);
    skLesson($this, ['instructor_id' => $busy->id, 'auto_assign' => false]);
    $free = skInstructor($this, ['first_name' => 'Free', 'last_name' => 'Guide']);

    $this->deleteJson(SK_API.'/instructors/'.$busy->id)->assertUnprocessable()->assertJsonPath('code', 'instructor_in_use');
    $this->deleteJson(SK_API.'/instructors/'.$free->id)->assertOk();
});

test('the instructors of another company are neither listed nor reachable', function (): void {
    skSetUp($this);
    $mine = skInstructor($this);
    $foreign = treasuryInTenant($this->otherTenant, fn (): Instructor => Instructor::query()->create(['tenant_id' => $this->otherTenant->id] + [
        'first_name' => 'Altro', 'last_name' => 'Maestro', 'license_type' => 'OTHER', 'hourly_rate' => 10,
    ]));

    $this->getJson(SK_API.'/instructors')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->getJson(SK_API.'/instructors/'.$foreign->id)->assertNotFound();
});

test('a read-only role cannot register instructors', function (): void {
    skSetUp($this, 'accountant_readonly');

    $this->getJson(SK_API.'/instructors')->assertOk();
    $this->postJson(SK_API.'/instructors', skInstructorPayload())->assertForbidden();
});

test('the planning board shows the slots of the day per instructor with their lessons and filters by discipline and language', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skInstructor($this, ['first_name' => 'Hans', 'last_name' => 'Keller', 'languages' => [['code' => 'DE']], 'disciplines' => ['telemark']]);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '09:50']);

    $board = $this->getJson(SK_API.'/planning/board?date=2027-01-19&slot_minutes=55')->assertOk()
        ->assertJsonPath('data.slot_minutes', 55)
        ->assertJsonCount(2, 'data.instructors');
    $annaRow = collect($board->json('data.instructors'))->firstWhere('name', 'Anna Rossi');
    expect($board->json('data.slots.0'))->toBe(['start' => '08:00', 'end' => '08:55'])
        ->and($annaRow['lessons'][0]['start_time'])->toBe('09:50')
        ->and($annaRow['free_slots'])->not->toContain('09:50')->toContain('08:55');

    $this->getJson(SK_API.'/planning/board?date=2027-01-19&discipline=telemark')->assertOk()->assertJsonCount(1, 'data.instructors')->assertJsonPath('data.instructors.0.name', 'Hans Keller');
    $this->getJson(SK_API.'/planning/board?date=2027-01-19&language=EN')->assertOk()->assertJsonCount(1, 'data.instructors')->assertJsonPath('data.instructors.0.name', 'Anna Rossi');
});

test('the board can be reduced to the instructors free at a given start time and to a requested instructor', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $marta = skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '09:00']);

    $this->getJson(SK_API.'/planning/board?date=2027-01-19&slot_minutes=55&slot_start=09:00&only_available=1')->assertOk()
        ->assertJsonCount(1, 'data.instructors')->assertJsonPath('data.instructors.0.id', $marta->id);
    $this->getJson(SK_API.'/planning/board?date=2027-01-19&instructor_id='.$anna->id)->assertOk()->assertJsonCount(1, 'data.instructors');
});

test('the board lists the unassigned lessons of the day', function (): void {
    skSetUp($this);
    skLesson($this, ['auto_assign' => false]);

    $this->getJson(SK_API.'/planning/board?date=2027-01-19')->assertOk()->assertJsonPath('data.totals.unassigned', 1)->assertJsonPath('data.unassigned.0.status', 'REQUESTED');
});

test('the rotation ranking puts the least loaded eligible instructor first and explains who is out', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $marta = skInstructor($this, ['first_name' => 'Marta', 'last_name' => 'Bianchi']);
    $german = skInstructor($this, ['first_name' => 'Hans', 'last_name' => 'Keller', 'languages' => [['code' => 'DE']]]);
    skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '08:00', 'slot_count' => 2]);

    $ranking = $this->getJson(SK_API.'/planning/rotation?date=2027-01-19&start_time=11:00&slot_minutes=55&discipline=alpine_ski&language=IT')->assertOk()->json('data');

    expect($ranking[0]['instructor_id'])->toBe($marta->id)->and($ranking[0]['rank'])->toBe(1)
        ->and($ranking[1]['instructor_id'])->toBe($anna->id)->and($ranking[1]['hours_in_period'])->toBe(1.83)
        ->and($ranking[2]['instructor_id'])->toBe($german->id)->and($ranking[2]['reasons'])->toBe(['language_mismatch']);

    $busy = $this->getJson(SK_API.'/planning/rotation?date=2027-01-19&start_time=08:30&slot_minutes=55&discipline=alpine_ski&language=IT')->assertOk()->json('data');
    expect(collect($busy)->firstWhere('instructor_id', $anna->id)['reasons'])->toBe(['lesson_conflict']);
});

test('a blackout takes the instructor out of the slot and an availability window limits the day', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $this->postJson(SK_API.'/instructors/'.$anna->id.'/availability', ['slot_date' => '2027-01-19', 'start_time' => '12:00', 'end_time' => '13:00', 'kind' => 'BLACKOUT', 'note' => 'Lunch'])->assertCreated();

    $this->postJson(SK_API.'/lessons', skLessonPayload(['instructor_id' => $anna->id, 'auto_assign' => false, 'start_time' => '12:00']))->assertUnprocessable()->assertJsonPath('code', 'instructor_conflict');

    $this->postJson(SK_API.'/instructors/'.$anna->id.'/availability', ['slot_date' => '2027-01-20', 'start_time' => '09:00', 'end_time' => '11:00', 'kind' => 'AVAILABLE'])->assertCreated();
    $this->postJson(SK_API.'/lessons', skLessonPayload(['instructor_id' => $anna->id, 'auto_assign' => false, 'lesson_date' => '2027-01-20', 'start_time' => '14:00']))->assertUnprocessable()->assertJsonPath('code', 'instructor_conflict');
    $this->postJson(SK_API.'/lessons', skLessonPayload(['instructor_id' => $anna->id, 'auto_assign' => false, 'lesson_date' => '2027-01-20', 'start_time' => '09:00']))->assertCreated();
});
